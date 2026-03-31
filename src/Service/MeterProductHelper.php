<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class MeterProductHelper implements MeterProductHelperInterface
{
    private const DEFAULT_MIN_LENGTH = 1;
    private const DEFAULT_MAX_LENGTH = 10000;

    // Sync mit dynamic-price.plugin.js _roundUp()
    private const ROUNDING_STEPS = [
        DynamicPriceConstants::ROUNDING_NONE => 0,
        DynamicPriceConstants::ROUNDING_CM => 10,
        DynamicPriceConstants::ROUNDING_QUARTER_M => 250,
        DynamicPriceConstants::ROUNDING_HALF_M => 500,
        DynamicPriceConstants::ROUNDING_FULL_M => 1000,
    ];

    private const VALID_ROUNDING_MODES = [
        DynamicPriceConstants::ROUNDING_NONE,
        DynamicPriceConstants::ROUNDING_CM,
        DynamicPriceConstants::ROUNDING_QUARTER_M,
        DynamicPriceConstants::ROUNDING_HALF_M,
        DynamicPriceConstants::ROUNDING_FULL_M,
    ];

    /** @param EntityRepository<ProductCollection> $productRepository */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    /**
     * Lädt das Produkt und prüft ob es als Meterartikel markiert ist.
     * Lädt nur customFields, um den DB-Overhead minimal zu halten.
     */
    public function isMeterProduct(string $productId, Context $context): bool
    {
        $product = $this->loadProduct($productId, $context);

        return $product !== null && $this->isMeterProductEntity($product);
    }

    /** Prüft anhand einer bereits geladenen Produkt-Entity ob es ein Meterartikel ist. */
    public function isMeterProductEntity(ProductEntity $product): bool
    {
        return (bool) (($product->getCustomFields() ?? [])[DynamicPriceConstants::FIELD_METER_ACTIVE] ?? false);
    }

    /** Lädt das Produkt. Gibt null zurück wenn das Produkt nicht existiert. */
    public function loadProduct(string $productId, Context $context): ?ProductEntity
    {
        $criteria = new Criteria([$productId]);

        $product = $this->productRepository->search($criteria, $context)->first();

        return $product instanceof ProductEntity ? $product : null;
    }

    /** Ermittelt die Mindestlänge: Produktwert → globale Config → Standardwert. */
    public function getMinLength(ProductEntity $product, string $salesChannelId): int
    {
        $productValue = $this->getCustomFieldInt($product, DynamicPriceConstants::FIELD_MIN_LENGTH);
        if ($productValue !== null) {
            return $productValue;
        }

        return $this->systemConfigService->getInt(
            'RcDynamicPrice.config.minLength',
            $salesChannelId
        ) ?: self::DEFAULT_MIN_LENGTH;
    }

    /** Ermittelt die Maximallänge: Produktwert → globale Config → Standardwert. */
    public function getMaxLength(ProductEntity $product, string $salesChannelId): int
    {
        $productValue = $this->getCustomFieldInt($product, DynamicPriceConstants::FIELD_MAX_LENGTH);
        if ($productValue !== null) {
            return $productValue;
        }

        return $this->systemConfigService->getInt(
            'RcDynamicPrice.config.maxLength',
            $salesChannelId
        ) ?: self::DEFAULT_MAX_LENGTH;
    }

    /** Liest den Rundungsmodus aus den Custom Fields des Produkts. */
    public function getRoundingMode(ProductEntity $product): string
    {
        $value = ($product->getCustomFields() ?? [])[DynamicPriceConstants::FIELD_ROUNDING] ?? null;

        if (\is_string($value) && \in_array($value, self::VALID_ROUNDING_MODES, true)) {
            return $value;
        }

        return DynamicPriceConstants::ROUNDING_NONE;
    }

    /** Rundet auf die nächste volle Einheit gemäß Modus. Exakte Vielfache bleiben unverändert. */
    public function roundUp(int $mm, string $mode): int
    {
        $step = self::ROUNDING_STEPS[$mode] ?? 0;

        if ($step <= 0) {
            return $mm;
        }

        return (int) (ceil($mm / $step) * $step);
    }

    /** Liest ein Custom Field als positive Ganzzahl, gibt null zurück wenn nicht gesetzt oder ungültig. */
    private function getCustomFieldInt(ProductEntity $product, string $fieldName): ?int
    {
        $value = ($product->getCustomFields() ?? [])[$fieldName] ?? null;

        if ($value === null) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }
}
