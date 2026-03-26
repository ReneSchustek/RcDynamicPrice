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

    public function testRoundUpToMeterRoundsUp(): void
    {
        $this->assertSame(5000, $this->helper->roundUpToMeter(4050));
    }

    public function testRoundUpToMeterKeepsExactMeter(): void
    {
        $this->assertSame(3000, $this->helper->roundUpToMeter(3000));
    }

    public function testRoundUpToMeterRoundsSmallValue(): void
    {
        $this->assertSame(1000, $this->helper->roundUpToMeter(1));
    }

    public function testShouldRoundUpToMeterReturnsTrueWhenSet(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields(['rc_meter_price_round_up_meter' => true]);

        $this->assertTrue($this->helper->shouldRoundUpToMeter($product));
    }

    public function testShouldRoundUpToMeterReturnsFalseWhenNotSet(): void
    {
        $product = new ProductEntity();
        $product->setCustomFields([]);

        $this->assertFalse($this->helper->shouldRoundUpToMeter($product));
    }
}
