<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Service\CategoryChainLoaderInterface;
use Ruhrcoder\RcDynamicPrice\Service\ConfigScope;
use Ruhrcoder\RcDynamicPrice\Service\MeterConfigResolver;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Seo\MainCategory\MainCategoryCollection;
use Shopware\Core\Content\Seo\MainCategory\MainCategoryEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Der Resolver entscheidet je Artikel über Meterpreis, Grenzen, Rundung und Aufteilung, in der
 * Reihenfolge Produkt, Kategoriekette, Grundeinstellung, Vorgabe. Eine falsch aufgelöste Ebene
 * verkauft einen Zuschnitt zum Stückpreis oder rundet anders, als der Betreiber eingestellt hat.
 */
final class MeterConfigResolverTest extends TestCase
{
    private SystemConfigService&MockObject $systemConfig;
    private CategoryChainLoaderInterface&MockObject $chainLoader;
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

    // Ob der Meterpreis gilt: Das Produkt entscheidet zuerst.

    public function testProductOffShortCircuitsAlways(): void
    {
        $this->stubApplyToAllProducts(true);
        $config = $this->resolver->resolve(
            ['rc_meter_price_active' => 'off'],
            [$this->category(['rc_meter_price_cat_active' => 'on'])],
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
        $chain = [$this->category(['rc_meter_price_cat_active' => 'on'])];
        $config = $this->resolver->resolve(['rc_meter_price_active' => 'inherit'], $chain, 'sc-id');

        $this->assertTrue($config->active);
        $this->assertSame(ConfigScope::Category, $config->activeScope);
    }

    public function testProductInheritCategoryOffBeatsGlobalOn(): void
    {
        $this->stubApplyToAllProducts(true);
        $chain = [$this->category(['rc_meter_price_cat_active' => 'off'])];
        $config = $this->resolver->resolve(['rc_meter_price_active' => 'inherit'], $chain, 'sc-id');

        $this->assertFalse($config->active);
        $this->assertSame(ConfigScope::Category, $config->activeScope);
    }

    public function testNearestCategoryWinsOverRoot(): void
    {
        $chain = [
            $this->category(['rc_meter_price_cat_active' => 'inherit']),      // leaf
            $this->category(['rc_meter_price_cat_active' => 'on']),           // mid
            $this->category(['rc_meter_price_cat_active' => 'off']),          // root, zählt nicht mehr, mid hat entschieden
        ];

        $config = $this->resolver->resolve(['rc_meter_price_active' => 'inherit'], $chain, 'sc-id');

        $this->assertTrue($config->active);
        $this->assertSame(ConfigScope::Category, $config->activeScope);
    }

    public function testAllInheritFallsBackToGlobalOrDefault(): void
    {
        $chain = [
            $this->category(['rc_meter_price_cat_active' => 'inherit']),
            $this->category(['rc_meter_price_cat_active' => 'inherit']),
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

    // Zahlenfelder: Produkt vor Kategorie vor Grundeinstellung vor Vorgabe.

    public function testMinLengthFromProduct(): void
    {
        $config = $this->activeConfig(
            productFields: ['rc_meter_price_active' => 'on', 'rc_meter_price_min_length' => 500],
            chain: [$this->category(['rc_meter_price_cat_min_length' => 999])],
            salesChannelId: 'sc-id',
        );

        $this->assertSame(500, $config->minLength);
        $this->assertSame(ConfigScope::Product, $config->minLengthScope);
    }

    public function testMinLengthFallsThroughToCategoryChain(): void
    {
        $chain = [
            $this->category([]),                                    // leaf: kein Wert
            $this->category(['rc_meter_price_cat_min_length' => 250]),  // mid: setzt Wert
            $this->category(['rc_meter_price_cat_min_length' => 750]),  // root: ignoriert
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

        // Die Grundeinstellung 0 heißt ausdrücklich „nicht aufteilen" und wird übernommen, statt auf
        // eine Vorgabe zurückzufallen.
        $this->assertSame(0, $config->maxPieceLength);
    }

    // Rundung

    public function testRoundingFromProductWins(): void
    {
        $config = $this->activeConfig(
            ['rc_meter_price_active' => 'on', 'rc_meter_price_rounding' => 'quarter_m'],
            [$this->category(['rc_meter_price_cat_rounding' => 'full_m'])],
            'sc-id',
        );

        $this->assertSame('quarter_m', $config->roundingMode);
        $this->assertSame(ConfigScope::Product, $config->roundingModeScope);
    }

    public function testRoundingFromCategoryWhenProductMissing(): void
    {
        $config = $this->activeConfig(
            ['rc_meter_price_active' => 'on'],
            [$this->category(['rc_meter_price_cat_rounding' => 'full_m'])],
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
            [$this->category(['rc_meter_price_cat_rounding' => 'half_m'])],
            'sc-id',
        );

        $this->assertSame('half_m', $config->roundingMode);
        $this->assertSame(ConfigScope::Category, $config->roundingModeScope);
    }

    // Aufteilung

    public function testSplitModeFromProduct(): void
    {
        $config = $this->activeConfig(
            ['rc_meter_price_active' => 'on', 'rc_meter_price_split_mode' => 'equal'],
            [$this->category(['rc_meter_price_cat_split_mode' => 'max_rest'])],
            'sc-id',
        );

        $this->assertSame(SplitMode::Equal, $config->splitMode);
        $this->assertSame(ConfigScope::Product, $config->splitModeScope);
    }

    public function testSplitModeFallsThroughToCategory(): void
    {
        $config = $this->activeConfig(
            ['rc_meter_price_active' => 'on'],
            [$this->category(['rc_meter_price_cat_split_mode' => 'hint'])],
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

    // Die Mindestlänge liegt nie über der Höchstlänge.

    public function testSwappedMinMaxIsNormalised(): void
    {
        $config = $this->activeConfig(
            ['rc_meter_price_active' => 'on', 'rc_meter_price_min_length' => 9000, 'rc_meter_price_max_length' => 200],
            [],
            'sc-id',
        );

        // minLength darf maxLength nicht überschreiten; maxLength wird angehoben.
        $this->assertLessThanOrEqual($config->maxLength, $config->minLength);
    }

    // Cache-Tags

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
        // Auch Seiten ohne Meterpreis brauchen die Tags; sonst bliebe eine Kategorie, an der der
        // Meterpreis eingeschaltet wird, im Cache ohne ihn stehen.
        $this->assertContains('rc-dynamic-price-category-cat-id', $config->cacheTags);
    }

    // Die maßgebliche Kategorie ist immer dieselbe.

    public function testPrimaryCategoryUsesSmallestIdDeterministicallyWhenNoMainCategory(): void
    {
        // Die Reihenfolge der DAL ist nicht fest; ohne Sortierung erbte das Produkt je nach
        // Ladereihenfolge andere Einstellungen. Die kleinste Kennung gewinnt.
        $product = new ProductEntity();
        $product->setId('p-1');
        $product->setCategoryIds(['cat-zzz', 'cat-aaa', 'cat-mmm']);

        $captured = null;
        $this->chainLoader->method('loadChain')->willReturnCallback(
            function (string $categoryId) use (&$captured): array {
                $captured = $categoryId;

                return [];
            },
        );

        $this->resolver->resolveForProduct($product, 'sc-1', Context::createDefaultContext());

        $this->assertSame('cat-aaa', $captured);
    }

    public function testPrimaryCategoryPrefersMainCategoryOfSalesChannel(): void
    {
        $product = new ProductEntity();
        $product->setId('p-1');
        $product->setCategoryIds(['cat-aaa', 'cat-bbb']);

        $main = new MainCategoryEntity();
        $main->setId('mc-1');
        $main->setSalesChannelId('sc-1');
        $main->setCategoryId('cat-bbb');
        $product->setMainCategories(new MainCategoryCollection([$main]));

        $captured = null;
        $this->chainLoader->method('loadChain')->willReturnCallback(
            function (string $categoryId) use (&$captured): array {
                $captured = $categoryId;

                return [];
            },
        );

        $this->resolver->resolveForProduct($product, 'sc-1', Context::createDefaultContext());

        // Die gepflegte Hauptkategorie gewinnt gegen die sortierte Ersatzkategorie (cat-aaa).
        $this->assertSame('cat-bbb', $captured);
    }

    // Hilfsfunktionen

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
