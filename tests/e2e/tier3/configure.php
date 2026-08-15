<?php
/**
 * Configures the extension the way a merchant would through the admin screen.
 *
 * Done in PHP rather than `bin/magento config:set` because the merchant private
 * key is a multi-line PEM, which does not survive a shell argument.
 */

declare(strict_types=1);

require '/var/www/html/app/bootstrap.php';

$om     = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$writer = $om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);

$values = [
    'payment/spectrocoin_merchant/active'                     => '1',
    // The shipped default already points at spectrocoin.com, which is the stub.
    'payment/spectrocoin_merchant/api_fields/api_url'         => 'https://spectrocoin.com/api/merchant/1',
    'payment/spectrocoin_merchant/api_fields/merchant_id'     => 'tier3-merchant',
    'payment/spectrocoin_merchant/api_fields/project_id'      => 'tier3-project',
    'payment/spectrocoin_merchant/api_fields/private_key'     => file_get_contents('/certs/merchant-private.pem'),
    // Needed for the quote to produce a shippable order.
    'carriers/flatrate/active'                                => '1',
    // Magento requires a region for countries that declare one, and the test
    // fixture has no interest in address validation.
    'general/region/state_required'                           => '',
    // A shopper is walked through checkout with no account, so the storefront
    // has to allow it.
    'checkout/options/guest_checkout'                         => '1',
    // The control payment method for the decisive checkout assertion: if the
    // payment step is empty for every method, that's a fixture problem, not
    // evidence against our extension.
    'payment/checkmo/active'                                  => '1',
];

foreach ($values as $path => $value) {
    $writer->save($path, $value);
}

print 'CONFIGURED';
