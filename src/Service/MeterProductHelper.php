<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Schlanke Utility-Klasse für Produkt-Ladung und Rundungs-Arithmetik.
 * Scope-abhängige Konfigurationsauflösung liegt im MeterConfigResolver.
 */
final class MeterProductHelper implements MeterProductHelperInterface
{
    // Muss identisch sein mit dynamic-price.plugin.js _roundUp()
    private const ROUNDING_STEPS = [
        DynamicPriceConstants::ROUNDING_NONE => 0,
        DynamicPriceConstants::ROUNDING_CM => 10,
        DynamicPriceConstants::ROUNDING_QUARTER_M => 250,
        DynamicPriceConstants::ROUNDING_HALF_M => 500,
        DynamicPriceConstants::ROUNDING_FULL_M => 1000,
    ];

    /** @param EntityRepository<ProductCollection> $productRepository */
    public function __construct(
        private readonly EntityRepository $productRepository,
    ) {
    }

    public function loadProduct(string $productId, Context $context): ?ProductEntity
    {
        $criteria = new Criteria([$productId]);
        $criteria->setLimit(1);
        // Kategorie-Ketten-Resolver braucht categoryIds — categories-Assoziation füllt das zuverlässig.
        $criteria->addAssociation('categories');

        $product = $this->productRepository->search($criteria, $context)->first();

        return $product instanceof ProductEntity ? $product : null;
    }

    public function roundUp(int $mm, string $mode): int
    {
        $step = self::ROUNDING_STEPS[$mode] ?? 0;

        if ($step <= 0) {
            return $mm;
        }

        return (int) (ceil($mm / $step) * $step);
    }
}
