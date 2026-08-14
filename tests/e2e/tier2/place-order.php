<?php
/**
 * Places a real Magento order paid with this extension, and lets the extension
 * itself create the matching SpectroCoin order.
 *
 * The order goes through Magento's own quote -> order pipeline, and the
 * SpectroCoin side through the extension's own getSpectrocoinResponse(), so the
 * outbound payload is assembled by the code under test rather than reproduced
 * here.
 */

declare(strict_types=1);

require '/var/www/html/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om        = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

$productRepo  = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
$storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$store        = $storeManager->getStore();

try {
    $product = $productRepo->get('tier2-item');
} catch (\Throwable $e) {
    $product = $om->create(\Magento\Catalog\Model\Product::class);
    $product->setSku('tier2-item')
        ->setName('Tier 2 test item')
        ->setAttributeSetId(4)
        ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
        ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
        ->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE)
        ->setPrice(12.34)
        ->setWeight(1)
        ->setStockData([
            'use_config_manage_stock' => 0,
            'manage_stock'            => 0,
            'is_in_stock'             => 1,
        ]);
    $product = $productRepo->save($product);
}

$address = [
    'firstname'  => 'Tier',
    'lastname'   => 'Two',
    'street'     => ['1 Test Street'],
    'city'       => 'Vilnius',
    'country_id' => 'LT',
    'postcode'   => '01100',
    'telephone'  => '0000000',
];

$quote = $om->create(\Magento\Quote\Model\Quote::class);
$quote->setStore($store);
$quote->setCurrency();
$quote->setCustomerEmail('tier2@example.com');
$quote->setCustomerIsGuest(true);
$quote->addProduct($product, 1);
$quote->getBillingAddress()->addData($address);

$shipping = $quote->getShippingAddress()->addData($address);
$shipping->setCollectShippingRates(true)
    ->collectShippingRates()
    ->setShippingMethod('flatrate_flatrate');

$quote->setPaymentMethod(\Spectrocoin\Merchant\Model\Payment::CODE);
$quote->setInventoryProcessed(false);
$quote->save();
$quote->getPayment()->importData(['method' => \Spectrocoin\Merchant\Model\Payment::CODE]);
$quote->collectTotals()->save();

$order = $om->get(\Magento\Quote\Model\QuoteManagement::class)->submit($quote);

// The extension's own call: builds the payload, signs it, posts it.
$response = $om->get(\Spectrocoin\Merchant\Model\Payment::class)->getSpectrocoinResponse($order);

file_put_contents('/tmp/tier2-order.json', json_encode([
    'increment_id' => (string) $order->getIncrementId(),
    'entity_id'    => (int) $order->getId(),
    'grand_total'  => (float) $order->getGrandTotal(),
    'currency'     => (string) $order->getOrderCurrencyCode(),
    'status'       => (string) $order->getStatus(),
    'response'     => is_object($response) ? get_class($response) : var_export($response, true),
]));
