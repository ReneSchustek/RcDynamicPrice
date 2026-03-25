<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

final class MeterProductHelper
{
    public function __construct(
        private readonly EntityRepository $productRepository,
    ) {
    }

    /**
     * Lädt das Produkt und prüft ob es als Meterartikel markiert ist.
     * Lädt nur customFields, um den DB-Overhead minimal zu halten.
     */
    public function isMeterProduct(string $productId, Context $context): bool
    {
        $criteria = new Criteria([$productId]);
        $criteria->addFields(['customFields']);

        $product = $this->productRepository->search($criteria, $context)->first();
        if (!$product instanceof ProductEntity) {
            return false;
        }

        return $this->isMeterProductEntity($product);
    }

    /** Prüft anhand einer bereits geladenen Produkt-Entity ob es ein Meterartikel ist. */
    public function isMeterProductEntity(ProductEntity $product): bool
    {
        return (bool) (($product->getCustomFields() ?? [])[DynamicPriceConstants::FIELD_METER_ACTIVE] ?? false);
    }
}
