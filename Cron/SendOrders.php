<?php
declare(strict_types=1);

namespace Alia\Connect\Cron;

use Alia\Connect\Model\AliaClient;
use Alia\Connect\Model\Heartbeat;
use Alia\Connect\Model\Integration;
use Alia\Connect\Model\OrderReader;
use Magento\Framework\FlagManager;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class SendOrders
{
    public const CURSOR_FLAG = 'alia_order_cursor';
    private const BATCH_SIZE = 100;
    private const MAX_BATCHES = 10;
    // MySQL stamps updated_at when the update runs, not when its transaction commits, so a slow save can
    // land behind the cursor. Each run rereads this far back.
    private const OVERLAP_SECONDS = 300;
    private const FIRST_RUN_LOOKBACK_SECONDS = 600;

    public function __construct(
        private readonly Integration $integration,
        private readonly OrderReader $orderReader,
        private readonly AliaClient $client,
        private readonly Heartbeat $heartbeat,
        private readonly FlagManager $flagManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $credentials = $this->integration->getActiveCredentials();
        if ($credentials === null) {
            return;
        }
        $storeURL = $this->storeURL();
        if ($storeURL === null) {
            return;
        }
        $base = [
            'storeURL' => $storeURL,
            'heartbeat' => $this->heartbeat->collect(),
        ];

        $cursor = $this->loadCursor();
        [$updatedAt, $entityId] = $cursor['caughtUp']
            ? [$this->secondsBefore($cursor['updatedAt'], self::OVERLAP_SECONDS), 0]
            : [$cursor['updatedAt'], $cursor['entityID']];

        $sent = false;
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $rows = $this->orderReader->readAfter($updatedAt, $entityId, self::BATCH_SIZE);
            if ($rows === []) {
                $cursor['caughtUp'] = true;
                break;
            }
            $payload = $base + ['orders' => $this->orderReader->toPayload($rows)];
            if (!$this->client->sendOrders($credentials, $payload)) {
                return;
            }
            $sent = true;
            $last = $rows[count($rows) - 1];
            $updatedAt = (string) $last['updated_at'];
            $entityId = (int) $last['entity_id'];
            // Rereading the overlap must never move the cursor back, or a store with more updates in the
            // overlap than one run reads would never get past it.
            if ([$updatedAt, $entityId] > [$cursor['updatedAt'], $cursor['entityID']]) {
                $cursor['updatedAt'] = $updatedAt;
                $cursor['entityID'] = $entityId;
            }
            $cursor['caughtUp'] = count($rows) < self::BATCH_SIZE;
            $this->flagManager->saveFlag(self::CURSOR_FLAG, $cursor);
            if ($cursor['caughtUp']) {
                break;
            }
        }
        if (!$sent) {
            if ($this->client->sendOrders($credentials, $base + ['orders' => []])) {
                $this->flagManager->saveFlag(self::CURSOR_FLAG, $cursor);
            }
        }
    }

    /**
     * @return array{updatedAt: string, entityID: int, caughtUp: bool}
     */
    private function loadCursor(): array
    {
        $saved = $this->flagManager->getFlagData(self::CURSOR_FLAG);
        if (is_array($saved) && is_string($saved['updatedAt'] ?? null)) {
            return [
                'updatedAt' => $saved['updatedAt'],
                'entityID' => (int) ($saved['entityID'] ?? 0),
                'caughtUp' => (bool) ($saved['caughtUp'] ?? true),
            ];
        }
        // Saved straight away, so a store whose first sends fail doesn't keep moving its start forward.
        $start = [
            'updatedAt' => gmdate('Y-m-d H:i:s', time() - self::FIRST_RUN_LOOKBACK_SECONDS),
            'entityID' => 0,
            'caughtUp' => false,
        ];
        $this->flagManager->saveFlag(self::CURSOR_FLAG, $start);
        return $start;
    }

    private function secondsBefore(string $time, int $seconds): string
    {
        $timestamp = strtotime($time . ' UTC');
        return gmdate('Y-m-d H:i:s', ($timestamp === false ? time() : $timestamp) - $seconds);
    }

    // Alia matches it to the URL Magento sent on activation by host only.
    private function storeURL(): ?string
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();
            return $store === null ? null : (string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true);
        } catch (\Exception $exception) {
            $this->logger->warning('Alia could not read the store URL', ['exception' => $exception]);
            return null;
        }
    }
}
