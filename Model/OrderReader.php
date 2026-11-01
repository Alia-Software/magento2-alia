<?php
declare(strict_types=1);

namespace Alia\Connect\Model;

use Alia\Connect\Observer\SaveQuoteVisitor;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Store\Model\StoreManagerInterface;

class OrderReader
{
    private const ORDER_COLUMNS = [
        'entity_id',
        'increment_id',
        'store_id',
        'state',
        'status',
        'customer_email',
        'customer_id',
        'customer_is_guest',
        'quote_id',
        'base_currency_code',
        'base_subtotal',
        'base_discount_amount',
        'base_grand_total',
        'coupon_code',
        'relation_parent_id',
        'created_at',
        'updated_at',
    ];
    private const MAX_EDIT_DEPTH = 20;
    // Alia ignores older orders, so a bulk update of old ones shouldn't use up each run's batches.
    private const MAX_ORDER_AGE_DAYS = 30;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Orders updated after the cursor, oldest first, ordered by (updated_at, entity_id) so a page boundary
     * inside one second still moves forward.
     *
     * @return array<int, array<string, mixed>>
     */
    public function readAfter(string $updatedAt, int $entityId, int $limit): array
    {
        $connection = $this->sales();
        $afterCursor = $connection->quoteInto('updated_at > ?', $updatedAt)
            . ' OR ' . $connection->quoteInto('(updated_at = ? AND entity_id > ' . $entityId . ')', $updatedAt);
        $select = $connection->select()
            ->from($this->table('sales_order', 'sales'), self::ORDER_COLUMNS)
            ->where($afterCursor)
            ->where('created_at >= ?', gmdate('Y-m-d H:i:s', time() - self::MAX_ORDER_AGE_DAYS * 86400))
            ->order(['updated_at ASC', 'entity_id ASC'])
            ->limit($limit);
        return $connection->fetchAll($select);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function toPayload(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $orderIds = array_map(static fn(array $row): int => (int) $row['entity_id'], $rows);
        $quoteIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int) $row['quote_id'],
            $rows
        )));
        $payments = $this->paymentMethods($orderIds);
        $phones = $this->billingPhones($orderIds);
        $originals = $this->originalOrderIds($rows);
        $visitors = $this->visitors($quoteIds);
        $firstOrderTimes = $this->firstOrderTimes($rows);

        $orders = [];
        foreach ($rows as $row) {
            $orderId = (int) $row['entity_id'];
            $email = $row['customer_email'] !== null ? (string) $row['customer_email'] : null;
            $orders[] = [
                'entityID' => $orderId,
                'incrementID' => (string) $row['increment_id'],
                'storeID' => (int) $row['store_id'],
                'websiteID' => $this->websiteId((int) $row['store_id']),
                'state' => (string) $row['state'],
                'status' => (string) $row['status'],
                'paymentMethod' => $payments[$orderId] ?? null,
                'email' => $email,
                'phone' => $phones[$orderId] ?? null,
                'customerID' => $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
                'customerIsGuest' => (bool) $row['customer_is_guest'],
                'isFirstOrder' => $row['customer_id'] !== null
                    ? (string) $row['created_at'] === ($firstOrderTimes[(int) $row['customer_id']] ?? null)
                    : null,
                'baseCurrencyCode' => (string) $row['base_currency_code'],
                'baseSubtotal' => (float) $row['base_subtotal'],
                'baseDiscountAmount' => (float) $row['base_discount_amount'],
                'baseGrandTotal' => (float) $row['base_grand_total'],
                'couponCode' => $row['coupon_code'] !== null ? (string) $row['coupon_code'] : null,
                'originalOrderID' => $originals[$orderId] ?? $orderId,
                'createdAt' => (string) $row['created_at'],
                'updatedAt' => (string) $row['updated_at'],
                'visitor' => $visitors[(int) $row['quote_id']] ?? null,
            ];
        }
        return $orders;
    }

    /**
     * @param int[] $orderIds
     * @return array<int, string>
     */
    private function paymentMethods(array $orderIds): array
    {
        $connection = $this->sales();
        return $connection->fetchPairs(
            $connection->select()
                ->from($this->table('sales_order_payment', 'sales'), ['parent_id', 'method'])
                ->where('parent_id IN (?)', $orderIds)
        );
    }

    /**
     * @param int[] $orderIds
     * @return array<int, string>
     */
    private function billingPhones(array $orderIds): array
    {
        $connection = $this->sales();
        return $connection->fetchPairs(
            $connection->select()
                ->from($this->table('sales_order_address', 'sales'), ['parent_id', 'telephone'])
                ->where('parent_id IN (?)', $orderIds)
                ->where('address_type = ?', 'billing')
        );
    }

    /**
     * The first order of each edit chain. relation_parent_id only points one edit back, so this walks up a
     * level at a time for the whole batch.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function originalOrderIds(array $rows): array
    {
        $originals = [];
        $parents = [];
        foreach ($rows as $row) {
            $orderId = (int) $row['entity_id'];
            $originals[$orderId] = $orderId;
            $parent = (int) $row['relation_parent_id'];
            if ($parent > 0) {
                $originals[$orderId] = $parent;
                $parents[$parent] = true;
            }
        }
        $connection = $this->sales();
        for ($level = 0; $parents !== [] && $level < self::MAX_EDIT_DEPTH; $level++) {
            $next = $connection->fetchPairs(
                $connection->select()
                    ->from($this->table('sales_order', 'sales'), ['entity_id', 'relation_parent_id'])
                    ->where('entity_id IN (?)', array_keys($parents))
            );
            $parents = [];
            foreach ($originals as $orderId => $original) {
                $parent = (int) ($next[$original] ?? 0);
                if ($parent > 0) {
                    $originals[$orderId] = $parent;
                    $parents[$parent] = true;
                }
            }
        }
        return $originals;
    }

    /**
     * @param int[] $quoteIds
     * @return array<int, string>
     */
    private function visitors(array $quoteIds): array
    {
        if ($quoteIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection('checkout');
        return $connection->fetchPairs(
            $connection->select()
                ->from(
                    $this->resource->getTableName(SaveQuoteVisitor::TABLE, 'checkout'),
                    ['quote_id', 'visitor']
                )
                ->where('quote_id IN (?)', $quoteIds)
        );
    }

    /**
     * By customer id, which sales_order indexes; it has no index on customer_email, so guests get none.
     * Canceled orders and orders replaced by an edit never happened, so they don't count as a first order.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string> each customer's earliest created_at
     */
    private function firstOrderTimes(array $rows): array
    {
        $customerIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): ?int => $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
            $rows
        ))));
        if ($customerIds === []) {
            return [];
        }
        $connection = $this->sales();
        $times = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from(
                    $this->table('sales_order', 'sales'),
                    ['customer_id', 'first_created_at' => new Expression('MIN(created_at)')]
                )
                ->where('customer_id IN (?)', $customerIds)
                ->where('state != ?', 'canceled')
                ->where("relation_child_id IS NULL OR relation_child_id = ''")
                ->group('customer_id')
        ) as $row) {
            $times[(int) $row['customer_id']] = (string) $row['first_created_at'];
        }
        return $times;
    }

    private function websiteId(int $storeId): int
    {
        try {
            return (int) $this->storeManager->getStore($storeId)->getWebsiteId();
        } catch (\Exception) {
            return 0;
        }
    }

    private function sales(): AdapterInterface
    {
        return $this->resource->getConnection('sales');
    }

    private function table(string $name, string $connection): string
    {
        return $this->resource->getTableName($name, $connection);
    }
}
