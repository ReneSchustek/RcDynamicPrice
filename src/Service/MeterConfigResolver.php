<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\ActiveState;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class MeterConfigResolver implements MeterConfigResolverInterface
{
    private const DEFAULT_MIN_LENGTH = 1;
    private const DEFAULT_MAX_LENGTH = 10000;

    private const VALID_ROUNDING_MODES = [
        DynamicPriceConstants::ROUNDING_NONE,
        DynamicPriceConstants::ROUNDING_CM,
        DynamicPriceConstants::ROUNDING_QUARTER_M,
        DynamicPriceConstants::ROUNDING_HALF_M,
        DynamicPriceConstants::ROUNDING_FULL_M,
    ];

    public function __construct(
        private readonly CategoryChainLoaderInterface $categoryChainLoader,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function resolveForProduct(ProductEntity $product, string $salesChannelId, Context $context): ResolvedMeterConfig
    {
        $productFields = $product->getCustomFields() ?? [];
        $primaryCategoryId = $this->primaryCategoryId($product);

        $categoryChain = $primaryCategoryId === null
            ? []
            : $this->categoryChainLoader->loadChain($primaryCategoryId, $context);

        return $this->resolve($productFields, $categoryChain, $salesChannelId);
    }

    public function resolve(array $productFields, array $categoryChain, string $salesChannelId): ResolvedMeterConfig
    {
        $cacheTags = $this->buildCacheTags($categoryChain);

        $productActive = ActiveState::fromMixed($productFields[DynamicPriceConstants::FIELD_METER_ACTIVE] ?? null);

        // Produkt-`off` kurzschliesst vollstaendig.
        if ($productActive === ActiveState::Off) {
            return ResolvedMeterConfig::disabled(ConfigScope::Product, $cacheTags);
        }

        [$active, $activeScope] = $this->resolveActive($productActive, $categoryChain, $salesChannelId);

        if (!$active) {
            return ResolvedMeterConfig::disabled($activeScope, $cacheTags);
        }

        [$minLength, $minScope] = $this->resolveInt(
            $productFields,
            $categoryChain,
            DynamicPriceConstants::FIELD_MIN_LENGTH,
            DynamicPriceConstants::CONFIG_MIN_LENGTH,
            $salesChannelId,
            self::DEFAULT_MIN_LENGTH,
        );

        [$maxLength, $maxScope] = $this->resolveInt(
            $productFields,
            $categoryChain,
            DynamicPriceConstants::FIELD_MAX_LENGTH,
            DynamicPriceConstants::CONFIG_MAX_LENGTH,
            $salesChannelId,
            self::DEFAULT_MAX_LENGTH,
        );

        [$roundingMode, $roundingScope] = $this->resolveRoundingMode($productFields, $categoryChain);

        [$splitMode, $splitModeScope] = $this->resolveSplitMode($productFields, $categoryChain, $salesChannelId);

        [$maxPieceLength, $maxPieceScope] = $this->resolveInt(
            $productFields,
            $categoryChain,
            DynamicPriceConstants::FIELD_MAX_PIECE_LENGTH,
            DynamicPriceConstants::CONFIG_MAX_PIECE_LENGTH,
            $salesChannelId,
            0,
            allowZeroFromGlobal: true,
        );

        [$splitHintTemplate, $splitHintScope] = $this->resolveString(
            $productFields,
            $categoryChain,
            DynamicPriceConstants::FIELD_SPLIT_HINT,
            DynamicPriceConstants::CONFIG_SPLIT_HINT_TEMPLATE,
            $salesChannelId,
        );

        if ($minLength > $maxLength) {
            // Fehlkonfiguration kommt durch, aber wir erhoehen maxLength auf minLength,
            // damit die Struct-Invariante (minLength <= maxLength) nicht bricht.
            $maxLength = $minLength;
            $maxScope = ConfigScope::Default;
        }

        return new ResolvedMeterConfig(
            active: true,
            activeScope: $activeScope,
            minLength: $minLength,
            minLengthScope: $minScope,
            maxLength: $maxLength,
            maxLengthScope: $maxScope,
            roundingMode: $roundingMode,
            roundingModeScope: $roundingScope,
            splitMode: $splitMode,
            splitModeScope: $splitModeScope,
            maxPieceLength: $maxPieceLength,
            maxPieceLengthScope: $maxPieceScope,
            splitHintTemplate: $splitHintTemplate,
            splitHintTemplateScope: $splitHintScope,
            cacheTags: $cacheTags,
        );
    }

    /**
     * @param list<array{id: string, customFields: array<string, mixed>}> $categoryChain
     *
     * @return array{0: bool, 1: ConfigScope}
     */
    private function resolveActive(ActiveState $productActive, array $categoryChain, string $salesChannelId): array
    {
        if ($productActive === ActiveState::On) {
            return [true, ConfigScope::Product];
        }

        // Produkt = inherit: Tree-Walk, erster expliziter Treffer gewinnt.
        foreach ($categoryChain as $entry) {
            $state = ActiveState::fromMixed($entry['customFields'][DynamicPriceConstants::FIELD_METER_ACTIVE] ?? null);
            if ($state === ActiveState::On) {
                return [true, ConfigScope::Category];
            }
            if ($state === ActiveState::Off) {
                return [false, ConfigScope::Category];
            }
        }

        $applyToAll = $this->systemConfigService->getBool(
            DynamicPriceConstants::CONFIG_APPLY_TO_ALL_PRODUCTS,
            $salesChannelId,
        );

        if ($applyToAll) {
            return [true, ConfigScope::Global];
        }

        return [false, ConfigScope::Default];
    }

    /**
     * @param array<string, mixed>                                          $productFields
     * @param list<array{id: string, customFields: array<string, mixed>}>   $categoryChain
     *
     * @return array{0: int, 1: ConfigScope}
     */
    private function resolveInt(
        array $productFields,
        array $categoryChain,
        string $fieldName,
        string $systemConfigKey,
        string $salesChannelId,
        int $default,
        bool $allowZeroFromGlobal = false,
    ): array {
        $productValue = $this->positiveInt($productFields[$fieldName] ?? null);
        if ($productValue !== null) {
            return [$productValue, ConfigScope::Product];
        }

        foreach ($categoryChain as $entry) {
            $categoryValue = $this->positiveInt($entry['customFields'][$fieldName] ?? null);
            if ($categoryValue !== null) {
                return [$categoryValue, ConfigScope::Category];
            }
        }

        $globalValue = $this->systemConfigService->getInt($systemConfigKey, $salesChannelId);
        if ($globalValue > 0) {
            return [$globalValue, ConfigScope::Global];
        }

        if ($allowZeroFromGlobal && $globalValue === 0) {
            return [0, ConfigScope::Global];
        }

        return [$default, ConfigScope::Default];
    }

    /**
     * @param array<string, mixed>                                          $productFields
     * @param list<array{id: string, customFields: array<string, mixed>}>   $categoryChain
     *
     * @return array{0: string, 1: ConfigScope}
     */
    private function resolveString(
        array $productFields,
        array $categoryChain,
        string $fieldName,
        string $systemConfigKey,
        string $salesChannelId,
    ): array {
        $productValue = $productFields[$fieldName] ?? null;
        if (\is_string($productValue) && $productValue !== '') {
            return [$productValue, ConfigScope::Product];
        }

        foreach ($categoryChain as $entry) {
            $value = $entry['customFields'][$fieldName] ?? null;
            if (\is_string($value) && $value !== '') {
                return [$value, ConfigScope::Category];
            }
        }

        $globalValue = $this->systemConfigService->getString($systemConfigKey, $salesChannelId);
        if ($globalValue !== '') {
            return [$globalValue, ConfigScope::Global];
        }

        return ['', ConfigScope::Default];
    }

    /**
     * @param array<string, mixed>                                          $productFields
     * @param list<array{id: string, customFields: array<string, mixed>}>   $categoryChain
     *
     * @return array{0: string, 1: ConfigScope}
     */
    private function resolveRoundingMode(array $productFields, array $categoryChain): array
    {
        $productValue = $productFields[DynamicPriceConstants::FIELD_ROUNDING] ?? null;
        if (\is_string($productValue) && \in_array($productValue, self::VALID_ROUNDING_MODES, true)) {
            return [$productValue, ConfigScope::Product];
        }

        foreach ($categoryChain as $entry) {
            $value = $entry['customFields'][DynamicPriceConstants::FIELD_ROUNDING] ?? null;
            if (\is_string($value) && \in_array($value, self::VALID_ROUNDING_MODES, true)) {
                return [$value, ConfigScope::Category];
            }
        }

        return [DynamicPriceConstants::ROUNDING_NONE, ConfigScope::Default];
    }

    /**
     * @param array<string, mixed>                                          $productFields
     * @param list<array{id: string, customFields: array<string, mixed>}>   $categoryChain
     *
     * @return array{0: ?SplitMode, 1: ConfigScope}
     */
    private function resolveSplitMode(array $productFields, array $categoryChain, string $salesChannelId): array
    {
        $productValue = SplitMode::tryFromString($productFields[DynamicPriceConstants::FIELD_SPLIT_MODE] ?? null);
        if ($productValue !== null) {
            return [$productValue, ConfigScope::Product];
        }

        foreach ($categoryChain as $entry) {
            $value = SplitMode::tryFromString($entry['customFields'][DynamicPriceConstants::FIELD_SPLIT_MODE] ?? null);
            if ($value !== null) {
                return [$value, ConfigScope::Category];
            }
        }

        $global = SplitMode::tryFromString($this->systemConfigService->getString(
            DynamicPriceConstants::CONFIG_SPLIT_MODE,
            $salesChannelId,
        ));
        if ($global !== null) {
            return [$global, ConfigScope::Global];
        }

        return [null, ConfigScope::Default];
    }

    /**
     * Positive Ganzzahl oder null. Werte <= 0 gelten wie bisher als "nicht gesetzt".
     */
    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!\is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function primaryCategoryId(ProductEntity $product): ?string
    {
        $ids = $product->getCategoryIds();
        if ($ids !== null && $ids !== []) {
            return $ids[array_key_first($ids)];
        }

        $categories = $product->getCategories();
        if ($categories !== null) {
            $first = $categories->first();
            if ($first !== null) {
                return $first->getId();
            }
        }

        return null;
    }

    /**
     * @param list<array{id: string, customFields: array<string, mixed>}> $categoryChain
     *
     * @return list<string>
     */
    private function buildCacheTags(array $categoryChain): array
    {
        $tags = [DynamicPriceConstants::CACHE_TAG_GLOBAL];
        foreach ($categoryChain as $entry) {
            $tags[] = DynamicPriceConstants::CACHE_TAG_CATEGORY_PREFIX . $entry['id'];
        }

        return array_values(array_unique($tags));
    }
}
