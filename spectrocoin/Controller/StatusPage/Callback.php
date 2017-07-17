<?php
namespace Spectrocoin\Merchant\Controller\StatusPage;

use Spectrocoin\Merchant\Model\Payment as PaymentModel;
use Spectrocoin\Merchant\Library\SCMerchantClient\Data\OrderCallback;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\Order;
use Magento\Framework\App\Request\Http;


class Callback extends Action {
    protected $order;
    protected $paymentModel;
    protected $client;
    protected $httpRequest;

    /**
     * @param Context $context
     * @param Order $order
     * @param PaymentModel $paymentModel
     * @internal param \Magento\Framework\View\Result\PageFactory $resultPageFactory
     * @param Http $request
     */
    public function __construct(
        Context $context,
        Order $order,
        PaymentModel $paymentModel,
        Http $request
    ) {
        parent::__construct($context);
        $this->order = $order;
        $this->paymentModel = $paymentModel;
        $this->client = $paymentModel->getSCClient();
        $this->httpRequest = $request;
    }


    /**
     * Default customer account page
     *
     * @return void
     */
    public function execute() {
        $orderCallback = $this->client->parseCreateOrderCallback($_REQUEST);

        if (!is_null($orderCallback)) {
            $order = $this->order->loadByIncrementId($orderCallback->getOrderId());
            if ($this->paymentModel->updateOrderStatus($orderCallback, $order)) {
                $this->getResponse()->setBody('*ok*');
            }
            else {
                $this->getResponse()->setBody('*error*');
            }
        }
        else {
            $this->getResponse()->setBody('*error*');
        }
    }
}