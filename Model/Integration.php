<?php
declare(strict_types=1);

namespace Alia\Connect\Model;

use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Api\OauthServiceInterface;
use Magento\Integration\Model\Integration as IntegrationModel;

class Integration
{
    public const NAME = 'Alia';

    public function __construct(
        private readonly IntegrationServiceInterface $integrationService,
        private readonly OauthServiceInterface $oauthService
    ) {
    }

    public function getConsumerKey(): ?string
    {
        $consumerId = $this->getConsumerId();
        if ($consumerId === null) {
            return null;
        }
        $key = $this->oauthService->loadConsumer($consumerId)->getKey();
        return $key ? (string) $key : null;
    }

    /**
     * The consumer exists from install, before the merchant activates, so nothing may be sent until then.
     *
     * @return array{key: string, secret: string}|null
     */
    public function getActiveCredentials(): ?array
    {
        $integration = $this->integrationService->findByName(self::NAME);
        if ((int) $integration->getStatus() !== IntegrationModel::STATUS_ACTIVE) {
            return null;
        }
        $consumerId = $integration->getConsumerId();
        if (!$consumerId) {
            return null;
        }
        $consumer = $this->oauthService->loadConsumer((int) $consumerId);
        $key = (string) $consumer->getKey();
        $secret = (string) $consumer->getSecret();
        return $key !== '' && $secret !== '' ? ['key' => $key, 'secret' => $secret] : null;
    }

    private function getConsumerId(): ?int
    {
        $consumerId = $this->integrationService->findByName(self::NAME)->getConsumerId();
        return $consumerId ? (int) $consumerId : null;
    }
}
