<?php
declare(strict_types=1);

namespace Alia\Connect\ViewModel;

use Alia\Connect\Model\Integration;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Request\Http;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Script implements ArgumentInterface
{
    private const SKIPPED_ACTION_PREFIXES = [
        'checkout_',
        'multishipping_checkout_',
        'paypal_express_',
        'hyva_checkout_',
    ];

    public function __construct(
        private readonly Http $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Integration $integration
    ) {
    }

    public function getPageType(): string
    {
        return $this->request->getFullActionName();
    }

    public function getSrc(): ?string
    {
        if ($this->isCheckout($this->getPageType())) {
            return null;
        }
        $consumerKey = $this->integration->getConsumerKey();
        if ($consumerKey === null) {
            return null;
        }
        $store = $this->storeManager->getStore();
        $backendUrl = rtrim((string) $this->scopeConfig->getValue(
            'alia/general/backend_url',
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        ), '/');
        return $backendUrl . '/public/launcher.js?' . http_build_query([
            'magentoStore' => $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true),
            'magentoKey' => substr(hash('sha256', $consumerKey), 0, 16),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function isCheckout(string $action): bool
    {
        if (str_starts_with($action, 'checkout_cart_')) {
            return false;
        }
        foreach (self::SKIPPED_ACTION_PREFIXES as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return true;
            }
        }
        // Only Magento 2.4.7-p5 and later have this list, which payment modules can add to.
        $sriActions = 'Magento\Csp\Model\SubresourceIntegrity\SriEnabledActions';
        return class_exists($sriActions)
            && ObjectManager::getInstance()->get($sriActions)->isPaymentPageAction($action);
    }
}
