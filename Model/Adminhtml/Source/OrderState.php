<?php
namespace Spectrocoin\Merchant\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;
use Magento\Sales\Model\Order;


class OrderState implements ArrayInterface {

    /**
     * Possible environment types
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => Order::STATE_NEW,
                'label' => 'New',
            ],
            [
                'value' => Order::STATE_PENDING_PAYMENT,
                'label' => 'Pending payment',
            ],
            [
                'value' => Order::STATE_PROCESSING,
                'label' => 'Processing',
            ],
            [
                'value' => Order::STATE_COMPLETE,
                'label' => 'Complete',
            ],
            [
                'value' => Order::STATE_CLOSED,
                'label' => 'Closed',
            ],
            [
                'value' => Order::STATE_CANCELED,
                'label' => 'Canceled',
            ],
            [
                'value' => Order::STATE_HOLDED,
                'label' => 'Holded',
            ],
            [
                'value' => Order::STATE_PAYMENT_REVIEW,
                'label' => 'Payment review',
            ],
        ];
    }
}