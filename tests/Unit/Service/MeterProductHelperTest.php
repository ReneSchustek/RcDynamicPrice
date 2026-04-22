<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelper;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
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

    public function testLoadProductRequestsCategoriesAssociation(): void
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn(new ProductEntity());

        $this->productRepository
            ->expects($this->once())
            ->method('search')
            ->with(
                $this->callback(static function (Criteria $criteria): bool {
                    return $criteria->hasAssociation('categories') && $criteria->getLimit() === 1;
                }),
                $this->isInstanceOf(Context::class),
            )
            ->willReturn($result);

        $this->helper->loadProduct('product-id', Context::createDefaultContext());
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
