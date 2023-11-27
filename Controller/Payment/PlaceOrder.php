<?php

namespace Spectrocoin\Merchant\Controller\Payment;

use Spectrocoin\Merchant\Model\Payment as PaymentModel;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\OrderFactory;

/**
 * Class PlaceOrder
 * @package Spectrocoin\Merchant\Controller\Payment
 */
class PlaceOrder extends Action {

    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var PaymentModel
     */
    protected $paymentModel; // Explicitly declare the property here

    /**
     * @param Context $context
     * @param OrderFactory $orderFactory
     * @param Session $checkoutSession
     * @param PaymentModel $paymentModel
     */
    public function __construct(
        Context $context,
        OrderFactory $orderFactory,
        Session $checkoutSession,
        PaymentModel $paymentModel
    ) {
        parent::__construct($context);
        $this->orderFactory = $orderFactory;
        $this->paymentModel = $paymentModel;
        $this->checkoutSession = $checkoutSession;
    }

    public function execute() {
        $id = $this->checkoutSession->getLastOrderId();
        $order = $this->orderFactory->create()->load($id);
        if (!$order->getIncrementId()) {
            $this->getResponse()->setBody(json_encode(array(
                'status' => false,
                'reason' => 'Order Not Found',
            )));
            return;
        }
        $this->getResponse()->setBody(
            json_encode($this->paymentModel->getSpectrocoinResponse($order))
        );
        return;
    }
}
