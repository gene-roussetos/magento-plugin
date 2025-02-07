<?php declare(strict_types=1);

namespace Rvvup\Payments\ViewModel;

use Exception;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;
use Rvvup\Payments\Model\Clearpay\Config;
use Rvvup\Payments\Model\ComplexProductTypePool;
use Rvvup\Payments\Model\ThresholdProvider;
use Magento\Store\Model\StoreManagerInterface;

class Clearpay implements ArgumentInterface
{
    /** @var Config */
    private $config;
    /** @var ProductRepositoryInterface */
    private $productRepository;
    /** @var ThresholdProvider */
    private $thresholdProvider;
    /** @var Session */
    private $session;
    /** @var StoreManagerInterface */
    private $storeManager;
    /** @var ComplexProductTypePool */
    private $productTypePool;

    /**
     * Set via di.xml
     *
     * @var \Psr\Log\LoggerInterface|RvvupLog
     */
    private $logger;

    /**
     * @var Price
     */
    private $priceViewModel;

    /** @var null|bool */
    private $isEnabled;

    public const PROVIDER = 'CLEARPAY';

    /**
     * @param Config $config
     * @param ProductRepositoryInterface $productRepository
     * @param ThresholdProvider $thresholdProvider
     * @param Session $session
     * @param StoreManagerInterface $storeManager
     * @param ComplexProductTypePool $productTypePool
     * @param LoggerInterface|RvvupLog $logger
     * @param Price $priceViewModel
     */
    public function __construct(
        Config $config,
        ProductRepositoryInterface $productRepository,
        ThresholdProvider $thresholdProvider,
        Session $session,
        StoreManagerInterface $storeManager,
        ComplexProductTypePool $productTypePool,
        LoggerInterface $logger,
        Price $priceViewModel
    ) {
        $this->config = $config;
        $this->productRepository = $productRepository;
        $this->thresholdProvider = $thresholdProvider;
        $this->session = $session;
        $this->storeManager = $storeManager;
        $this->productTypePool = $productTypePool;
        $this->logger = $logger;
        $this->priceViewModel = $priceViewModel;
    }

    /**
     * Pass a product object and method will return an array containing upper and lower limits
     * or false if the product is restricted or messaging is disabled
     *
     * @param ProductInterface $product
     * @return array|false
     */
    public function getThresholds(ProductInterface $product)
    {
        if (!$this->isEnabled()) {
            return false;
        }
        // Use getRvvupRestricted method instead of getData('rvvup_restricted') as this is mocked on unit tests.
        $isRestricted = (bool) $product->getRvvupRestricted();
        if ($isRestricted) {
            return false;
        }

        return $this->getThresholdsByProviderAndCurrency();
    }

    /**
     * @param ProductInterface $product
     * @return bool
     */
    public function showByProduct(ProductInterface $product): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        // Use getRvvupRestricted method instead of getData('rvvup_restricted') as this is mocked on unit tests.
        $isRestricted = (bool) $product->getRvvupRestricted();
        if ($isRestricted) {
            return false;
        }
        $price = $this->priceViewModel->getPrice($product);

        $thresholds = $this->getThresholdsByProviderAndCurrency();

        if (!$thresholds) {
            return false;
        }

        return ($price >= $thresholds['min']) && ($price <= $thresholds['max']);
    }

    /**
     * @param string $sku
     * @return bool
     */
    public function showBySku(string $sku): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        try {
            $product = $this->productRepository->get($sku);
            return $this->showByProduct($product);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @return bool
     */
    public function showByCart(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $quote = $this->session->getQuote();
        } catch (LocalizedException $ex) {
            return false;
        }

        foreach ($quote->getAllItems() as $item) {
            if ($item->getProduct()->getRvvupRestricted()) {
                return false;
            }
        }
        $total = $quote->getGrandTotal();

        $thresholds = $this->getThresholdsByProviderAndCurrency();

        if (!$thresholds) {
            return false;
        }

        return ($total >= $thresholds['min']) && ($total <= $thresholds['max']);
    }

    /**
     * @return string
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getCartTotal(): string
    {
        return number_format((float) $this->session->getQuote()->getGrandTotal(), 2, '.', '');
    }

    /**
     * @return string
     */
    public function getLogoType(): string
    {
        return $this->config->getIconType();
    }

    /**
     * @return string
     */
    public function getBadgeTheme(): string
    {
        return $this->config->getTheme();
    }

    /**
     * @return string
     */
    public function getModalTheme(): string
    {
        return $this->config->getModalTheme();
    }

    /**
     * @return string
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getCurrentCurrencyCode(): string
    {
        return $this->storeManager->getStore()->getCurrentCurrency()->getCode();
    }

    public function getCurrentLocale(): string
    {
        return "en_GB";
    }

    /**
     * @return string
     * @throws NoSuchEntityException
     */
    public function getStoreCode(): string
    {
        return $this->storeManager->getStore()->getCode();
    }

    /**
     * @param ProductInterface $product
     * @param array $threshold
     * @return bool
     */
    public function isInThreshold(ProductInterface $product, array $threshold): bool
    {
        if (in_array($product->getTypeId(), $this->productTypePool->getProductTypes())) {
            return true;
        }
        $price = $this->priceViewModel->getPrice($product);
        return $price >= $threshold['min'] && $price <= $threshold['max'];
    }

    /**
     * @return bool
     */
    private function isEnabled(): bool
    {
        if ($this->isEnabled === null) {
            $this->isEnabled = $this->config->isEnabled();
        }
        return $this->isEnabled;
    }

    /**
     * Get the thresholds from Rvvup by the current provider and the store currency (if thresholds are set for that).
     *
     * @return false|array [
     *     'min' => 10.00,
     *     'max' => 20.00
     * ]
     */
    private function getThresholdsByProviderAndCurrency()
    {
        try {
            $currentCurrencyCode = $this->getCurrentCurrencyCode();
            $thresholds = $this->thresholdProvider->get(self::PROVIDER, $this->getCurrentCurrencyCode());

            // If there are no thresholds for the store currency, return false.
            if (!isset($thresholds[$this->getCurrentCurrencyCode()])) {
                return false;
            }

            return [
                'min' => $min,
                'max' => $max
            ] = $thresholds[$this->getCurrentCurrencyCode()];
        } catch (Exception $e) {
            $this->logger->error(
                'Exception thrown while fetching Clearpay thresholds with message: ' . $e->getMessage(),
                [
                    'provider' => self::PROVIDER,
                    'currency' => $currentCurrencyCode ?? null
                ]
            );

            return false;
        }
    }
}
