<?php
declare(strict_types=1);

namespace Alia\Connect\Observer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

class SaveQuoteVisitor implements ObserverInterface
{
    public const COOKIE = 'alia_visitor';
    public const TABLE = 'alia_quote_visitor';
    private const MAX_LENGTH = 1024;
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/';

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getData('quote');
        if (!$quote instanceof Quote || !$quote->getId()) {
            return;
        }
        $visitor = $this->cookieManager->getCookie(self::COOKIE);
        if (!is_string($visitor)
            || strlen($visitor) > self::MAX_LENGTH
            || !preg_match(self::TOKEN_PATTERN, $visitor)
        ) {
            return;
        }
        // A failure here must never stop the customer's cart from saving.
        try {
            $connection = $this->resource->getConnection('checkout');
            $connection->insertOnDuplicate(
                $this->resource->getTableName(self::TABLE, 'checkout'),
                ['quote_id' => (int) $quote->getId(), 'visitor' => $visitor],
                ['visitor']
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('Alia could not save the visitor of a cart', ['exception' => $exception]);
        }
    }
}
