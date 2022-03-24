define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'spectrocoin_merchant',
                component: 'Spectrocoin_Merchant/js/view/payment/method-renderer/spectrocoin-method'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);