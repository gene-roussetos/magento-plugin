<?php declare(strict_types=1);

namespace Rvvup\Payments\Model;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Rvvup\Payments\Model\Environment\GetEnvironmentVersionsInterface;
use Rvvup\Sdk\GraphQlSdkFactory;
use Rvvup\Sdk\GraphQlSdk;

class SdkProxy
{
    /** @var ConfigInterface */
    private $config;
    /** @var UserAgentBuilder */
    private $userAgent;
    /** @var GraphQlSdkFactory */
    private $sdkFactory;
    /** @var LoggerInterface */
    private $logger;
    /** @var GraphQlSdk */
    private $subject;

    /**
     * @var \Rvvup\Payments\Model\Environment\GetEnvironmentVersionsInterface
     */
    private $getEnvironmentVersions;

    /** @var array */
    private $methods;

    /**
     * @param ConfigInterface $config
     * @param UserAgentBuilder $userAgent
     * @param GraphQlSdkFactory $sdkFactory
     * @param \Rvvup\Payments\Model\Environment\GetEnvironmentVersionsInterface $getEnvironmentVersions
     * @param LoggerInterface $logger
     */
    public function __construct(
        ConfigInterface $config,
        UserAgentBuilder $userAgent,
        GraphQlSdkFactory $sdkFactory,
        GetEnvironmentVersionsInterface $getEnvironmentVersions,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->userAgent = $userAgent;
        $this->sdkFactory = $sdkFactory;
        $this->getEnvironmentVersions = $getEnvironmentVersions;
        $this->logger = $logger;
    }

    /**
     * Get proxied instance
     *
     * @return GraphQlSdk
     */
    private function getSubject(): GraphQlSdk
    {
        if (!$this->subject) {
            $endpoint = $this->config->getEndpoint();
            $merchant = $this->config->getMerchantId();
            $authToken = $this->config->getAuthToken();
            $debugMode = $this->config->isDebugEnabled();
            /** @var GraphQlSdk instance */
            $this->subject = $this->sdkFactory->create([
                'endpoint' => $endpoint,
                'merchantId' => $merchant,
                'authToken' => $authToken,
                'userAgent' => $this->userAgent->get(),
                'debug' => $debugMode,
                'adapter' => (new Client()),
                'logger' => $this->logger
            ]);
        }
        return $this->subject;
    }

    /**
     * @param string|null $cartTotal
     * @param string|null $currency
     * @param array|null $inputOptions
     * @return array
     * @throws \Exception
     */
    public function getMethods(string $cartTotal = null, string $currency = null, ?array $inputOptions = null): array
    {
        if (!$this->methods) {
            $cartTotal = $cartTotal === null ? $cartTotal : (string) round((float) $cartTotal, 2);

            $methods = $this->getSubject()->getMethods($cartTotal, $currency, $inputOptions);
            /**
             * Due to all Rvvup methods having the same `sort_order`values the way Magento sorts methods we need to
             * reverse the array so that they are presented in the order specified in the Rvvup dashboard
             */
            $this->methods = array_reverse($methods);
        }
        return $this->methods;
    }

    /**
     * {@inheritdoc}
     */
    public function createOrder($orderData)
    {
        return $this->getSubject()->createOrder($orderData);
    }

    /**
     * {@inheritdoc}
     */
    public function getOrder($orderId)
    {
        return $this->getSubject()->getOrder($orderId);
    }

    /**
     * {@inheritdoc}
     */
    public function isOrderRefundable($orderId)
    {
        return $this->getSubject()->isOrderRefundable($orderId);
    }

    /**
     * {@inheritdoc}
     */
    public function refundOrder($orderId, $amount, $reason, $idempotency)
    {
        return $this->getSubject()->refundOrder($orderId, $amount, $reason, $idempotency);
    }

    /**
     * {@inheritdoc}
     */
    public function ping(): bool
    {
        return $this->getSubject()->ping();
    }

    /**
     * {@inheritdoc}
     */
    public function registerWebhook(string $url): void
    {
        $this->getSubject()->registerWebhook($url);
    }

    /**
     * @param string $eventType
     * @param string $reason
     * @param array $additionalData
     * @return void
     * @throws \Exception
     */
    public function createEvent(string $eventType, string $reason, array $additionalData = []): void
    {
        $environmentVersions = $this->getEnvironmentVersions->execute();

        $data = [
            'plugin' => $environmentVersions['rvvp_module_version'],
            'php' => $environmentVersions['php_version'],
            'magento_edition' => $environmentVersions['magento_version']['edition'],
            'magento' => $environmentVersions['magento_version']['version'],
        ];

        // Add any data send by the event, but keep core data untouched.
        $this->getSubject()->createEvent($eventType, $reason, array_merge($additionalData, $data));
    }
}
