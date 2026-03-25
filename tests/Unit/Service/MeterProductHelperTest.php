<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelper;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

final class MeterProductHelperTest extends TestCase
{
    private EntityRepository $productRepository;
    private MeterProductHelper $helper;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(EntityRepository::class);
        $this->helper = new MeterProductHelper($this->productRepository);
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
        // setCustomFields(null) setzt das Feld auf null — Test prüft null-sicheren Zugriff
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
}
