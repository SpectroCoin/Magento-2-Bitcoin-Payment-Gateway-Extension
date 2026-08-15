<?php
/**
 * Creates something for a shopper to buy, then reindexes so the storefront
 * actually reflects it. Magento's catalog is index-backed; skipping the
 * reindex leaves a product invisible or unpriced on the frontend even though
 * it exists in the database.
 */

declare(strict_types=1);

require '/var/www/html/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om        = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$productRepo = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

try {
    $product = $productRepo->get('tier3-item');
} catch (\Throwable $e) {
    $product = $om->create(\Magento\Catalog\Model\Product::class);
    $product->setSku('tier3-item')
        ->setName('Tier 3 test item')
        ->setAttributeSetId(4)
        ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
        ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
        ->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE)
        ->setWebsiteIds([1])
        ->setPrice(12.34)
        ->setWeight(1)
        ->setStockData([
            'use_config_manage_stock' => 0,
            'manage_stock'            => 0,
            'is_in_stock'             => 1,
        ]);
    $product = $productRepo->save($product);
}

print json_encode(['id' => (int) $product->getId(), 'sku' => $product->getSku()]);
