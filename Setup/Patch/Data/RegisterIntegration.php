<?php
declare(strict_types=1);

namespace Alia\Connect\Setup\Patch\Data;

use Alia\Connect\Cron\SendOrders;
use Alia\Connect\Model\Integration;
use Alia\Connect\Observer\SaveQuoteVisitor;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Api\OauthServiceInterface;
use Magento\Integration\Model\ConfigBasedIntegrationManager;

class RegisterIntegration implements DataPatchInterface, PatchRevertableInterface
{
    public function __construct(
        private readonly ConfigBasedIntegrationManager $integrationManager,
        private readonly IntegrationServiceInterface $integrationService,
        private readonly OauthServiceInterface $oauthService,
        private readonly ResourceConnection $resource,
        private readonly FlagManager $flagManager
    ) {
    }

    public function apply(): self
    {
        $this->integrationManager->processIntegrationConfig([Integration::NAME]);
        return $this;
    }

    // `module:uninstall --non-composer` only reverts data patches and never runs an Uninstall class, so the
    // module's own table and cursor are removed here.
    public function revert(): void
    {
        $this->flagManager->deleteFlag(SendOrders::CURSOR_FLAG);
        $this->resource->getConnection('checkout')->dropTable(
            $this->resource->getTableName(SaveQuoteVisitor::TABLE, 'checkout')
        );

        $integration = $this->integrationService->findByName(Integration::NAME);
        if (!$integration->getId()) {
            return;
        }
        $consumerId = $integration->getConsumerId();
        $this->integrationService->delete((int) $integration->getId());
        // Deleting the integration leaves its consumer behind, and the consumer still holds the tokens.
        if ($consumerId) {
            $this->oauthService->deleteConsumer((int) $consumerId);
        }
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
