<?php
/**
 * Prints the grand total of the most recent order, so the test can assert
 * against what the shop actually charged - product price plus flat-rate
 * shipping - rather than a literal that ignores shipping.
 */

declare(strict_types=1);

require '/var/www/html/app/bootstrap.php';

$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();

$collection = $om->create(\Magento\Sales\Model\ResourceModel\Order\Collection::class)
    ->setOrder('entity_id', 'DESC')
    ->setPageSize(1);
$order = $collection->getFirstItem();

print json_encode([
    'increment_id' => (string) $order->getIncrementId(),
    'grand_total'  => (float) $order->getGrandTotal(),
    'currency'     => (string) $order->getOrderCurrencyCode(),
]);
