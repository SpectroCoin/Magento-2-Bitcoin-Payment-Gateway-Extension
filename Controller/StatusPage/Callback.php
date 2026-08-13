<?php
namespace Spectrocoin\Merchant\Controller\StatusPage;

use Spectrocoin\Merchant\Model\Payment as PaymentModel;
use Spectrocoin\Merchant\Library\SCMerchantClient\Data\OrderCallback;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\App\Request\Http;


class Callback extends Action implements CsrfAwareActionInterface {
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
     * This is an unauthenticated, externally reachable webhook. Magento's
     * form-key CSRF protection does not apply to server-to-server callbacks,
     * so we opt out of it here — the cryptographic signature check in
     * execute() is what actually authenticates the request.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Handle a SpectroCoin order-status callback.
     *
     * The reported status is caller-supplied, so nothing here may act on it
     * until the RSA signature has been verified against SpectroCoin's public
     * certificate. That check is what authenticates the request.
     *
     * @return void
     */
    public function execute() {
        // 1. Callbacks are server-to-server POSTs; nothing else is accepted.
        if (!$this->httpRequest->isPost()) {
            $this->getResponse()->setBody('*error*');
            return;
        }

        // 2. Parse from the POST body only (never $_REQUEST, which also mixes
        //    in GET/cookie data).
        $orderCallback = $this->client->parseCreateOrderCallback($this->httpRequest->getPostValue());
        if (is_null($orderCallback)) {
            $this->getResponse()->setBody('*error*');
            return;
        }

        // 3. Verify the signature before loading or mutating any order.
        if (!$this->client->validateCreateOrderCallback($orderCallback)) {
            $this->getResponse()->setBody('*error*');
            return;
        }

        $order = $this->order->loadByIncrementId($orderCallback->getOrderId());

        // 4. The order must exist and must actually be a SpectroCoin payment
        //    order, so a valid callback for one merchant/order cannot be
        //    replayed against an unrelated order.
        if (!$order->getId()
            || $order->getPayment() === null
            || $order->getPayment()->getMethod() !== PaymentModel::CODE) {
            $this->getResponse()->setBody('*error*');
            return;
        }

        // 5. The signed amount/currency must match the Magento order.
        if (!$this->paymentModel->matchesOrder($orderCallback, $order)) {
            $this->getResponse()->setBody('*error*');
            return;
        }

        if ($this->paymentModel->updateOrderStatus($orderCallback, $order)) {
            $this->getResponse()->setBody('*ok*');
        }
        else {
            $this->getResponse()->setBody('*error*');
        }
    }
}
