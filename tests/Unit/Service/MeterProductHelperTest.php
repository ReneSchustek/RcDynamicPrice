<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelper;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class MeterProductHelperTest extends TestCase
{
    private EntityRepository $productRepository;
    private SystemConfigService $systemConfig;
    private MeterProductHelper $helper;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(EntityRepository::class);
        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->helper = new MeterProductHelper($this->productRepository, $this->systemConfig);
    }

    public function testIsMeterProductEntityReturnsTrueWhenFlagIsSet(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields(['rc_meter_price_active' => true]);

        $this->assertTrue($this->helper->isMeterProductEntity($product));
    }

    public function testIsMeterProductEntityReturnsFalseWhenFlagIsFalse(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields(['rc_meter_price_active' => false]);

        $this->assertFalse($this->helper->isMeterProductEntity($product));
    }

    public function testIsMeterProductEntityReturnsFalseWhenCustomFieldsMissing(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields([]);

        $this->assertFalse($this->helper->isMeterProductEntity($product));
    }

    public function testIsMeterProductEntityReturnsFalseWhenCustomFieldsNull(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields(null);

        $this->assertFalse($this->helper->isMeterProductEntity($product));
    }

    public function testIsMeterProductReturnsTrueWhenProductIsMeterProduct(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields(['rc_meter_price_active' => true]);

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn($product);

        $this->productRepository->method('search')->willReturn($result);

        $this->assertTrue($this->helper->isMeterProduct('product-id', Context::createDefaultContext()));
    }

    public function testIsMeterProductReturnsFalseWhenProductNotFound(): void
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn(null);

        $this->productRepository->method('search')->willReturn($result);

        $this->assertFalse($this->helper->isMeterProduct('product-id', Context::createDefaultContext()));
    }

    public function testIsMeterProductReturnsFalseWhenFlagNotSet(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields([]);

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn($product);

        $this->productRepository->method('search')->willReturn($result);

        $this->assertFalse($this->helper->isMeterProduct('product-id', Context::createDefaultContext()));
    }

    public function testLoadProductReturnsEntityWhenFound(): void
    {
        $product = new ProductEntity();

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn($product);

        $this->productRepository->method('search')->willReturn($result);

        $this->assertSame($product, $this->helper->loadProduct('product-id', Context::createDefaultContext()));
    }

    public function testLoadProductReturnsNullWhenNotFound(): void
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn(null);

        $this->productRepository->method('search')->willReturn($result);

        $this->assertNull($this->helper->loadProduct('product-id', Context::createDefaultContext()));
    }

    public function testGetMinLengthReturnsProductValueWhenSet(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields(['rc_meter_price_min_length' => 500]);

        $this->assertSame(500, $this->helper->getMinLength($product, 'sc-id'));
    }

    public function testGetMinLengthFallsBackToGlobalConfig(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields(['rc_meter_price_active' => true]);

        $this->systemConfig->method('getInt')->willReturn(100);

        $this->assertSame(100, $this->helper->getMinLength($product, 'sc-id'));
    }

    public function testGetMinLengthFallsBackToDefaultWhenGlobalConfigIsZero(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields([]);

        $this->systemConfig->method('getInt')->willReturn(0);

        $this->assertSame(1, $this->helper->getMinLength($product, 'sc-id'));
    }

    public function testGetMaxLengthReturnsProductValueWhenSet(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields(['rc_meter_price_max_length' => 6000]);

        $this->assertSame(6000, $this->helper->getMaxLength($product, 'sc-id'));
    }

    public function testGetMaxLengthFallsBackToGlobalConfig(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields([]);

        $this->systemConfig->method('getInt')->willReturn(8000);

        $this->assertSame(8000, $this->helper->getMaxLength($product, 'sc-id'));
    }

    public function testGetMaxLengthFallsBackToDefaultWhenGlobalConfigIsZero(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields([]);

        $this->systemConfig->method('getInt')->willReturn(0);

        $this->assertSame(10000, $this->helper->getMaxLength($product, 'sc-id'));
    }

    public function testGetMinLengthIgnoresZeroProductValue(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields(['rc_meter_price_min_length' => 0]);

        $this->systemConfig->method('getInt')->willReturn(50);

        $this->assertSame(50, $this->helper->getMinLength($product, 'sc-id'));
    }

    public function testGetMaxLengthIgnoresNegativeProductValue(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields(['rc_meter_price_max_length' => -100]);

        $this->systemConfig->method('getInt')->willReturn(5000);

        $this->assertSame(5000, $this->helper->getMaxLength($product, 'sc-id'));
    }

    public function testGetMinLengthWithNullCustomFields(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields(null);

        $this->systemConfig->method('getInt')->willReturn(200);

        $this->assertSame(200, $this->helper->getMinLength($product, 'sc-id'));
    }

    // --- Rundungsmodus ---

    public function testGetRoundingModeReturnsValueWhenValid(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields(['rc_meter_price_rounding' => 'quarter_m']);

        $this->assertSame('quarter_m', $this->helper->getRoundingMode($product));
    }

    public function testGetRoundingModeReturnsNoneWhenNotSet(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields([]);

        $this->assertSame('none', $this->helper->getRoundingMode($product));
    }

    public function testGetRoundingModeReturnsNoneForInvalidValue(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields(['rc_meter_price_rounding' => 'invalid']);

        $this->assertSame('none', $this->helper->getRoundingMode($product));
    }

    public function testGetRoundingModeReturnsNoneForNullCustomFields(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields(null);

        $this->assertSame('none', $this->helper->getRoundingMode($product));
    }

    // --- roundUp: Modus none ---

    public function testRoundUpNoneReturnsExactValue(): void
    {
        $this->assertSame(4050, $this->helper->roundUp(4050, 'none'));
    }

    // --- roundUp: Modus cm (10 mm) ---

    public function testRoundUpCmRoundsUp(): void
    {
        $this->assertSame(1510, $this->helper->roundUp(1505, 'cm'));
    }

    public function testRoundUpCmKeepsExactMultiple(): void
    {
        $this->assertSame(1500, $this->helper->roundUp(1500, 'cm'));
    }

    public function testRoundUpCmSmallValue(): void
    {
        $this->assertSame(10, $this->helper->roundUp(1, 'cm'));
    }

    // --- roundUp: Modus quarter_m (250 mm) ---

    public function testRoundUpQuarterMeterRoundsUp(): void
    {
        $this->assertSame(1500, $this->helper->roundUp(1300, 'quarter_m'));
    }

    public function testRoundUpQuarterMeterKeepsExactMultiple(): void
    {
        $this->assertSame(1250, $this->helper->roundUp(1250, 'quarter_m'));
    }

    public function testRoundUpQuarterMeterSmallValue(): void
    {
        $this->assertSame(250, $this->helper->roundUp(1, 'quarter_m'));
    }

    // --- roundUp: Modus half_m (500 mm) ---

    public function testRoundUpHalfMeterRoundsUp(): void
    {
        $this->assertSame(2500, $this->helper->roundUp(2100, 'half_m'));
    }

    public function testRoundUpHalfMeterKeepsExactMultiple(): void
    {
        $this->assertSame(2000, $this->helper->roundUp(2000, 'half_m'));
    }

    // --- roundUp: Modus full_m (1000 mm) ---

    public function testRoundUpFullMeterRoundsUp(): void
    {
        $this->assertSame(5000, $this->helper->roundUp(4050, 'full_m'));
    }

    public function testRoundUpFullMeterKeepsExactMeter(): void
    {
        $this->assertSame(3000, $this->helper->roundUp(3000, 'full_m'));
    }

    public function testRoundUpFullMeterSmallValue(): void
    {
        $this->assertSame(1000, $this->helper->roundUp(1, 'full_m'));
    }

    // --- roundUp: unbekannter Modus ---

    public function testRoundUpUnknownModeReturnsExactValue(): void
    {
        $this->assertSame(4050, $this->helper->roundUp(4050, 'invalid'));
    }
}
