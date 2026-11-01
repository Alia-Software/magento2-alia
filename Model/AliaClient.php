<?php
declare(strict_types=1);

namespace Alia\Connect\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

class AliaClient
{
    private const PATH = '/webhooks/magento/orders';
    private const TIMEOUT_SECONDS = 15;
    private const CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array{key: string, secret: string} $credentials
     * @param array<string, mixed> $payload
     */
    public function sendOrders(array $credentials, array $payload): bool
    {
        try {
            $body = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (\JsonException $exception) {
            $this->logger->warning('Alia could not encode the orders', ['exception' => $exception]);
            return false;
        }
        $timestamp = (string) time();
        $url = rtrim((string) $this->scopeConfig->getValue('alia/general/backend_url'), '/') . self::PATH;

        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        $curl->setHeaders([
            'Content-Type' => 'application/json',
            // libcurl before 7.69.1 asks for "100 Continue" on bodies over 1KB, and Magento's client then
            // reports 100 as the status of a request that actually succeeded.
            'Expect' => '',
            'X-Alia-Key' => $credentials['key'],
            'X-Alia-Timestamp' => $timestamp,
            'X-Alia-Signature' => hash_hmac('sha256', $timestamp . '.' . $body, $credentials['secret']),
        ]);
        try {
            $curl->post($url, $body);
        } catch (\Exception $exception) {
            $this->logger->warning('Alia could not be reached', ['exception' => $exception]);
            return false;
        }
        $status = $curl->getStatus();
        if ($status < 200 || $status >= 300) {
            $this->logger->warning('Alia refused the orders', ['status' => $status, 'body' => $curl->getBody()]);
            return false;
        }
        return true;
    }
}
