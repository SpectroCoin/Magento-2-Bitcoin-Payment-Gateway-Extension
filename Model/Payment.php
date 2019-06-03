<?php
namespace Spectrocoin\Merchant\Model;

use Braintree\Exception;
use Spectrocoin\Merchant\Library\SCMerchantClient\Data\OrderCallback;
use Spectrocoin\Merchant\Library\SCMerchantClient\SCMerchantClient;
use Spectrocoin\Merchant\Library\SCMerchantClient\Message\CreateOrderRequest;
use Spectrocoin\Merchant\Library\SCMerchantClient\Message\CreateOrderResponse;
use Spectrocoin\Merchant\Library\SCMerchantClient\Data\ApiError;
use Spectrocoin\Merchant\Library\SCMerchantClient\Data\OrderStatusEnum;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;


class Payment extends AbstractMethod {
    const COINGATE_MAGENTO_VERSION = '1.0.6';
    const CODE = 'spectrocoin_merchant';
    protected $_code = 'spectrocoin_merchant';
    protected $_isInitializeNeeded = true;
    protected $urlBuilder;
    protected $storeManager;
    protected $scClient;
    protected $resolver;


    /**
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param Data $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param UrlInterface $urlBuilder
     * @param StoreManagerInterface $storeManager
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     * @internal param ModuleListInterface $moduleList
     * @internal param TimezoneInterface $localeDate
     * @internal param CountryFactory $countryFactory
     * @internal param Http $response
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        UrlInterface $urlBuilder,
        StoreManagerInterface $storeManager,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = array()
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );

        $this->scClient = new SCMerchantClient(
            $this->getConfigData('api_fields/api_url'),
            $this->getConfigData('api_fields/merchant_id'),
            $this->getConfigData('api_fields/project_id'),
            $this->getConfigData('debug_fields/debug_mode') == '1'
        );

        $this->scClient->setPrivateMerchantKey($this->getConfigData('api_fields/private_key'));

        $this->urlBuilder = $urlBuilder;
        $this->storeManager = $storeManager;
    }


    /**
     * @return SCMerchantClient
     */
    public function getSCClient() {
        return $this->scClient;
    }

    /**
     * @param Order $order
     * @return array
     */
    public function getSpectrocoinResponse(Order $order) {

        $orderId = $order->getIncrementId();
        $currency = $order->getOrderCurrencyCode();


        $uriCallback = $this->urlBuilder->getUrl('spectrocoin/statusPage/callback');
        $uriSuccess =  $this->urlBuilder->getUrl('checkout/onepage/success');
        $uriFailure =  $this->urlBuilder->getUrl('checkout/onepage/failure');
        $total = number_format($order->getGrandTotal(), 2, '.', '');

        $description = array();
        foreach ($order->getAllItems() as $item) {
            $description[] = number_format($item->getQtyOrdered(), 0) . ' × ' . $item->getName();
        }

        $description = implode(', ', $description);
        $description = '';

        // @todo should be loaded via DI, but today it doesn't work
        try {
            $locale = explode('_', \Magento\Framework\App\ObjectManager::getInstance()->get('Magento\Framework\Locale\Resolver')->getLocale())[0];
        }
        catch (\Exception $e) {
            $locale = 'en';
        }

        if ($this->getConfigData('payment_settings/order_payment_method') == 'pay') {
            $orderRequest = new CreateOrderRequest(
                $orderId,
                $currency,
                $total,
                $currency,
                null,
                $description,
                $locale,
                $uriCallback,
                $uriSuccess,
                $uriFailure
            );
        }
        else {
            $orderRequest = new CreateOrderRequest(
                $orderId,
                $currency,
                null,
                $currency,
                $total,
                $description,
                $locale,
                $uriCallback,
                $uriSuccess,
                $uriFailure
            );
        }

        try {
            $response = $this->scClient->createOrder($orderRequest);
        }
        catch (Exception $e) {
            return [
                'status' => 'error',
                'errorCode' => 1,
                'errorMsg' => 'Error: '.$e->getMessage()
            ];
        }

        if($response instanceof CreateOrderResponse) {
            return [
                'status' => 'ok',
                'redirect_url' => $response->getRedirectUrl()
            ];
        }
        elseif($response instanceof ApiError) {
            return [
                'status' => 'error',
                'errorCode' => $response->getCode(),
                'errorMsg' => $response->getMessage()
            ];
        }
        else {
            return [
                'status' => 'error',
                'errorCode' => 1,
                'errorMsg' => 'Unknown Spectrocoin error'
            ];
        }
    }

    /**
     * Returns order status from configuration
     * @param string $configOption
     * @param string $defaultValue
     * @return mixed|string
     */
    protected function getStatusDataOrDefault($configOption, $defaultValue = 'pending') {
        $data = $this->getConfigData($configOption);
        if (!$data) {
            $data = $defaultValue;
        }

        return $data;
    }

    /**
     * Returns order status mapped to spectrocoin status
     * @param string $spectrocoinStatus
     * @return mixed|string
     */
    protected function getOrderStatus($spectrocoinStatus) {
        switch($spectrocoinStatus) {
            case OrderStatusEnum::$New:
                $statusOption = $this->getStatusDataOrDefault(
                    'payment_settings/order_status_new',
                    'new'
                );
                break;

            case OrderStatusEnum::$Expired:
                $statusOption = $this->getStatusDataOrDefault(
                    'payment_settings/order_status_expired',
                    'canceled'
                );
                break;

            case OrderStatusEnum::$Failed:
                $statusOption = $this->getStatusDataOrDefault(
                    'payment_settings/order_status_failed',
                    'closed'
                );
                break;

            case OrderStatusEnum::$Paid:
                $statusOption = $this->getStatusDataOrDefault(
                    'payment_settings/order_status_paid',
                    'complete'
                );
                break;

            case OrderStatusEnum::$Pending:
                $statusOption = $this->getStatusDataOrDefault(
                    'payment_settings/order_status_pending',
                    'pending_payment'
                );
                break;

            case OrderStatusEnum::$Test:
                $statusOption = $this->getStatusDataOrDefault(
                    'payment_settings/order_status_test',
                    'payment_review'
                );
                break;

            default:
                $statusOption = $this->getStatusDataOrDefault(
                    'payment_settings/order_status_test',
                    'pending_payment'
                );
        }

        return $statusOption;
    }

    public function updateOrderStatus(OrderCallback $callback, Order $order) {
        try {
            $orderState = $this->getOrderStatus($callback->getStatus());

            $order
                ->setState($orderState, true)
                ->setStatus($order->getConfig()->getStateDefaultStatus($orderState))
                ->save();
            return true;
        }
        catch (\Exception $e) {
            exit('Error occurred: ' . $e);
        }
    }

}