<?php
namespace Spectrocoin\Merchant\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Class Environment
 */
class PaymentMethod implements ArrayInterface
{
    const RECEIVE_AMOUNT = 'receive';
    const PAY_AMOUNT = 'pay';

    /**
     * Possible environment types
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => self::RECEIVE_AMOUNT,
                'label' => 'Client',
            ],
            [
                'value' => self::PAY_AMOUNT,
                'label' => 'Shop'
            ]
        ];
    }
}

