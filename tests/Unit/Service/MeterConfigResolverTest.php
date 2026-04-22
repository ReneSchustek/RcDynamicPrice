<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Service\CategoryChainLoaderInterface;
use Ruhrcoder\RcDynamicPrice\Service\ConfigScope;
use Ruhrcoder\RcDynamicPrice\Service\MeterConfigResolver;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class MeterConfigResolverTest extends TestCase
{
    private SystemConfigService $systemConfig;
    private CategoryChainLoaderInterface $chainLoader;
    private MeterConfigResolver $resolver;

    protected function setUp(): void
    {
        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->chainLoader = $this->createMock(CategoryChainLoaderInterface::class);
        $this->resolver = new MeterConfigResolver($this->chainLoader, $this->systemConfig);

        $this->systemConfig->method('getBool')->willReturn(false);
        $this->systemConfig->method('getInt')->willReturn(0);
        $this->systemConfig->method('getString')->willReturn('');
    }

    // --- Active-Logik: Produkt-Entscheidung ---

    public function testProductOffShortCircuitsAlways(): void
    {
        $this->stubApplyToAllProducts(true);
        $config = $this->resolver->resolve(
            ['rc_meter_price_active' => 'off'],
            [$this->category(['rc_meter_price_active' => 'on'])],
            'sc-id',
        );

        $this->assertFalse($config->active);
        $this->assertSame(ConfigScope::Product, $config->activeScope);
    }

    public function testProductOnActivatesRegardlessOfRest(): void
    {
        $config = $this->resolver->resolve(['rc_meter_price_active' => 'on'], [], 'sc-id');

        $this->assertTrue($config->active);
        $this->assertSame(ConfigScope::Product, $config->activeScope);
    }

    public function testProductInheritWithoutAnythingElseIsInactive(): void
    {
        $config = $this->resolver->resolve(['rc_meter_price_active' => 'inherit'], [], 'sc-id');

        $this->assertFalse($config->active);
        $this->assertSame(ConfigScope::Default, $config->activeScope);
    }

    public function testProductInheritWithGlobalApplyToAllActivates(): void
    {
        $this->stubApplyToAllProducts(true);
        $config = $this->resolver->resolve(['rc_meter_price_active' => 'inherit'], [], 'sc-id');

        $this->assertTrue($config->active);
        $this->assertSame(ConfigScope::Global, $config->activeScope);
    }

    public function testProductInheritCategoryOnBeatsGlobalOff(): void
    {
        $this->stubApplyToAllProducts(false);
        $chain = [$this->category(['rc_meter_price_active' => 'on'])];
        $config = $this->resolver->resolve(['rc_meter_price_active' => 'inherit'], $chain, 'sc-id');

        $this->assertTrue($config->active);
        $this->assertSame(ConfigScope::Category, $config->activeScope);
    }

    public function testProductInheritCategoryOffBeatsGlobalOn(): void
    {
        $this->stubApplyToAllProducts(true);
        $chain = [$this->category(['rc_meter_price_active' => 'off'])];
        $config = $this->resolver->resolve(['rc_meter_price_active' => 'inherit'], $chain, 'sc-id');

        $this->assertFalse($config->active);
        $this->assertSame(ConfigScope::Category, $config->activeScope);
    }

    public function testNearestCategoryWinsOverRoot(): void
    {
        $chain = [
            $this->category(['rc_meter_price_active' => 'inherit']),      // leaf
            $this->category(['rc_meter_price_active' => 'on']),           // mid
            $this->category(['rc_meter_price_active' => 'off']),          // root — ignoriert, weil mid gewonnen hat
        ];

        $config = $this->resolver->resolve(['rc_meter_price_active' => 'inherit'], $chain, 'sc-id');

        $this->assertTrue($config->active);
        $this->assertSame(ConfigScope::Category, $config->activeScope);
    }

    public function testAllInheritFallsBackToGlobalOrDefault(): void
    {
        $chain = [
            $this->category(['rc_meter_price_active' => 'inherit']),
            $this->category(['rc_meter_price_active' => 'inherit']),
        ];
        $this->stubApplyToAllProducts(false);

        $config = $this->resolver->resolve(['rc_meter_price_active' => 'inherit'], $chain, 'sc-id');

        $this->assertFalse($config->active);
        $this->assertSame(ConfigScope::Default, $config->activeScope);
    }

    public function testLegacyBoolTrueIsTreatedAsOn(): void
    {
        $config = $this->resolver->resolve(['rc_meter_price_active' => true], [], 'sc-id');

        $this->assertTrue($config->active);
        $this->assertSame(ConfigScope::Product, $config->activeScope);
    }

    // --- Numeric fields: Produkt > Kategorie > Global > Default ---

    public function testMinLengthFromProduct(): void
    {
        $config = $this->activeConfig(
            productFields: ['rc_meter_price_active' => 'on', 'rc_meter_price_min_length' => 500],
            chain: [$this->category(['rc_meter_price_min_length' => 999])],
            salesChannelId: 'sc-id',
        );

        $this->assertSame(500, $config->minLength);
        $this->assertSame(ConfigScope::Product, $config->minLengthScope);
    }

    public function testMinLengthFallsThroughToCategoryChain(): void
    {
        $chain = [
            $this->category([]),                                    // leaf: kein Wert
            $this->category(['rc_meter_price_min_length' => 250]),  // mid: setzt Wert
            $this->category(['rc_meter_price_min_length' => 750]),  // root: ignoriert
        ];

        $config = $this->activeConfig(
            productFields: ['rc_meter_price_active' => 'on'],
            chain: $chain,
            salesChannelId: 'sc-id',
        );

        $this->assertSame(250, $config->minLength);
        $this->assertSame(ConfigScope::Category, $config->minLengthScope);
    }

    public function testMinLengthFallsBackToGlobal(): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getBool')->willReturn(false);
        $systemConfig->method('getInt')->willReturnCallback(
            static fn (string $key): int => $key === 'RcDynamicPrice.config.minLength' ? 120 : 0,
        );
        $systemConfig->method('getString')->willReturn('');

        $resolver = new MeterConfigResolver($this->chainLoader, $systemConfig);

        $config = $resolver->resolve(
            ['rc_meter_price_active' => 'on'],
            [],
            'sc-id',
        );

        $this->assertSame(120, $config->minLength);
        $this->assertSame(ConfigScope::Global, $config->minLengthScope);
    }

    public function testMinLengthFallsBackToDefaultWhenGlobalZero(): void
    {
        $config = $this->activeConfig(['rc_meter_price_active' => 'on'], [], 'sc-id');

        $this->assertSame(1, $config->minLength);
        $this->assertSame(ConfigScope::Default, $config->minLengthScope);
    }

    public function testMaxLengthDefaultIs10000(): void
    {
        $config = $this->activeConfig(['rc_meter_price_active' => 'on'], [], 'sc-id');

        $this->assertSame(10000, $config->maxLength);
        $this->assertSame(ConfigScope::Default, $config->maxLengthScope);
    }

    public function testMaxPieceLengthZeroFromGlobalIsAccepted(): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getBool')->willReturn(false);
        $systemConfig->method('getInt')->willReturn(0);
        $systemConfig->method('getString')->willReturn('');

        $resolver = new MeterConfigResolver($this->chainLoader, $systemConfig);

        $config = $resolver->resolve(['rc_meter_price_active' => 'on'], [], 'sc-id');

        // Global liefert 0 = explizit kein Splitting. Default greift, weil wir 0 akzeptieren.
        $this->assertSame(0, $config->maxPieceLength);
    }

    // --- Rounding ---

    public function testRoundingFromProductWins(): void
    {
        $config = $this->activeConfig(
            ['rc_meter_price_active' => 'on', 'rc_meter_price_rounding' => 'quarter_m'],
            [$this->category(['rc_meter_price_rounding' => 'full_m'])],
            'sc-id',
        );

        $this->assertSame('quarter_m', $config->roundingMode);
        $this->assertSame(ConfigScope::Product, $config->roundingModeScope);
    }

    public function testRoundingFromCategoryWhenProductMissing(): void
    {
        $config = $this->activeConfig(
            ['rc_meter_price_active' => 'on'],
            [$this->category(['rc_meter_price_rounding' => 'full_m'])],
            'sc-id',
        );

        $this->assertSame('full_m', $config->roundingMode);
        $this->assertSame(ConfigScope::Category, $config->roundingModeScope);
    }

    public function testRoundingDefaultIsNone(): void
    {
        $config = $this->activeConfig(['rc_meter_price_active' => 'on'], [], 'sc-id');

        $this->assertSame('none', $config->roundingMode);
        $this->assertSame(ConfigScope::Default, $config->roundingModeScope);
    }

    public function testRoundingIgnoresInvalidValues(): void
    {
        $config = $this->activeConfig(
            ['rc_meter_price_active' => 'on', 'rc_meter_price_rounding' => 'bogus'],
            [$this->category(['rc_meter_price_rounding' => 'half_m'])],
            'sc-id',
        );

        $this->assertSame('half_m', $config->roundingMode);
        $this->assertSame(ConfigScope::Category, $config->roundingModeScope);
    }

    // --- SplitMode ---

    public function testSplitModeFromProduct(): void
    {
        $config = $this->activeConfig(
            ['rc_meter_price_active' => 'on', 'rc_meter_price_split_mode' => 'equal'],
            [$this->category(['rc_meter_price_split_mode' => 'max_rest'])],
            'sc-id',
        );

        $this->assertSame(SplitMode::Equal, $config->splitMode);
        $this->assertSame(ConfigScope::Product, $config->splitModeScope);
    }

    public function testSplitModeFallsThroughToCategory(): void
    {
        $config = $this->activeConfig(
            ['rc_meter_price_active' => 'on'],
            [$this->category(['rc_meter_price_split_mode' => 'hint'])],
            'sc-id',
        );

        $this->assertSame(SplitMode::Hint, $config->splitMode);
        $this->assertSame(ConfigScope::Category, $config->splitModeScope);
    }

    public function testSplitModeNullWhenEverywhereEmpty(): void
    {
        $config = $this->activeConfig(['rc_meter_price_active' => 'on'], [], 'sc-id');

        $this->assertNull($config->splitMode);
        $this->assertSame(ConfigScope::Default, $config->splitModeScope);
    }

    // --- Invariante: minLength <= maxLength ---

    public function testSwappedMinMaxIsNormalised(): void
    {
        $config = $this->activeConfig(
            ['rc_meter_price_active' => 'on', 'rc_meter_price_min_length' => 9000, 'rc_meter_price_max_length' => 200],
            [],
            'sc-id',
        );

        // minLength darf maxLength nicht ueberschreiten; maxLength wird angehoben.
        $this->assertLessThanOrEqual($config->maxLength, $config->minLength);
    }

    // --- Cache-Tags ---

    public function testCacheTagsContainGlobalAndEachCategory(): void
    {
        $chain = [
            $this->category([], id: 'leaf-id'),
            $this->category([], id: 'mid-id'),
            $this->category([], id: 'root-id'),
        ];

        $config = $this->activeConfig(['rc_meter_price_active' => 'on'], $chain, 'sc-id');

        $this->assertContains('rc-dynamic-price-global', $config->cacheTags);
        $this->assertContains('rc-dynamic-price-category-leaf-id', $config->cacheTags);
        $this->assertContains('rc-dynamic-price-category-mid-id', $config->cacheTags);
        $this->assertContains('rc-dynamic-price-category-root-id', $config->cacheTags);
    }

    public function testCacheTagsPresentEvenWhenInactive(): void
    {
        $chain = [$this->category([], id: 'cat-id')];

        $config = $this->resolver->resolve(['rc_meter_price_active' => 'inherit'], $chain, 'sc-id');

        $this->assertFalse($config->active);
        // Auch auf inaktiven Seiten braucht es Invalidierungs-Tags, damit Kategorie-Aenderungen dort greifen.
        $this->assertContains('rc-dynamic-price-category-cat-id', $config->cacheTags);
    }

    // --- Hilfsfunktionen ---

    /**
     * @param array<string, mixed>                                          $productFields
     * @param list<array{id: string, customFields: array<string, mixed>}>   $chain
     */
    private function activeConfig(array $productFields, array $chain, string $salesChannelId): \Ruhrcoder\RcDynamicPrice\Service\ResolvedMeterConfig
    {
        return $this->resolver->resolve($productFields, $chain, $salesChannelId);
    }

    /**
     * @param array<string, mixed> $customFields
     *
     * @return array{id: string, customFields: array<string, mixed>}
     */
    private function category(array $customFields, string $id = 'cat-id'): array
    {
        return ['id' => $id, 'customFields' => $customFields];
    }

    private function stubApplyToAllProducts(bool $value): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getBool')->willReturn($value);
        $systemConfig->method('getInt')->willReturn(0);
        $systemConfig->method('getString')->willReturn('');

        $this->resolver = new MeterConfigResolver($this->chainLoader, $systemConfig);
    }
}
