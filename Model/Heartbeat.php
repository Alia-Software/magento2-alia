<?php
declare(strict_types=1);

namespace Alia\Connect\Model;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Module\Manager as ModuleManager;

class Heartbeat
{
    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ModuleManager $moduleManager,
        private readonly ComponentRegistrarInterface $componentRegistrar
    ) {
    }

    /**
     * @return array{moduleVersion: string, magentoVersion: string, edition: string, b2b: bool, cloud: bool}
     */
    public function collect(): array
    {
        return [
            'moduleVersion' => $this->moduleVersion(),
            'magentoVersion' => (string) $this->productMetadata->getVersion(),
            'edition' => (string) $this->productMetadata->getEdition(),
            'b2b' => $this->moduleManager->isEnabled('Magento_Company'),
            'cloud' => getenv('MAGENTO_CLOUD_ENVIRONMENT') !== false,
        ];
    }

    private function moduleVersion(): string
    {
        $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'Alia_Connect');
        $file = $path . '/composer.json';
        if (!$path || !is_readable($file)) {
            return 'dev';
        }
        $composer = json_decode((string) file_get_contents($file), true);
        return is_array($composer) && is_string($composer['version'] ?? null) ? $composer['version'] : 'dev';
    }
}
