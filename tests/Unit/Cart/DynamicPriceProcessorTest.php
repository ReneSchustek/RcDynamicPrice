<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Cart;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Cart\DynamicPriceProcessor;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class DynamicPriceProcessorTest extends TestCase
{
    private QuantityPriceCalculator $calculator;
    private MeterProductHelperInterface $helper;
    private DynamicPriceProcessor $processor;
    private SalesChannelContext $context;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(QuantityPriceCalculator::class);
        $this->helper = $this->createMock(MeterProductHelperInterface::class);
        $this->processor = new DynamicPriceProcessor($this->calculator, $this->helper);
        $this->context = $this->createMock(SalesChannelContext::class);
    }

    public function testSkipsLineItemWithoutMeterPriceActiveFlag(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('meterLengthMm', 1500);

        $this->calculator->expects($this->never())->method('calculate');

        $this->process([$lineItem]);
    }

    public function testSkipsLineItemWithoutMeterLengthPayload(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('rc_meter_price_active', true);

        $this->calculator->expects($this->never())->method('calculate');

        $this->process([$lineItem]);
    }

    public function testSkipsLineItemWithZeroLength(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('rc_meter_price_active', true);
        $lineItem->setPayloadValue('meterLengthMm', 0);

        $this->calculator->expects($this->never())->method('calculate');

        $this->process([$lineItem]);
    }

    public function testSkipsLineItemWithNegativeLength(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('rc_meter_price_active', true);
        $lineItem->setPayloadValue('meterLengthMm', -100);

        $this->calculator->expects($this->never())->method('calculate');

        $this->process([$lineItem]);
    }

    public function testSkipsLineItemWithNullPrice(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('rc_meter_price_active', true);
        $lineItem->setPayloadValue('meterLengthMm', 1500);

        $this->calculator->expects($this->never())->method('calculate');

        $this->process([$lineItem]);
    }

    public function testSkipsLineItemBelowMinLength(): void
    {
        $lineItem = $this->createMeterLineItem(500, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, 1000);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH, 6000);

        $this->calculator->expects($this->never())->method('calculate');

        $this->process([$lineItem]);
    }

    public function testSkipsLineItemAboveMaxLength(): void
    {
        $lineItem = $this->createMeterLineItem(7000, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, 1000);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH, 6000);

        $this->calculator->expects($this->never())->method('calculate');

        $this->process([$lineItem]);
    }

    public function testCalculatesPriceForValidMeterLengthItem(): void
    {
        $lineItem = $this->createMeterLineItem(1500, 100.0);
        $adjustedPrice = $this->createPrice(150.0);

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->with(
                $this->callback(fn (QuantityPriceDefinition $def) => $def->getPrice() === 150.0),
                $this->context,
            )
            ->willReturn($adjustedPrice);

        $this->process([$lineItem]);

        $this->assertSame($adjustedPrice, $lineItem->getPrice());
    }

    public function testSetsLengthLabelPayload(): void
    {
        $lineItem = $this->createMeterLineItem(1500, 100.0);

        $this->calculator->method('calculate')->willReturn($this->createPrice(150.0));

        $this->process([$lineItem]);

        $this->assertSame('Länge: 1.500 mm', $lineItem->getPayloadValue('rc_length_label'));
    }

    public function testCalculatesPriceWithRoundUpToMeter(): void
    {
        $lineItem = $this->createMeterLineItem(4050, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUND_UP, true);

        $this->helper->method('roundUpToMeter')->with(4050)->willReturn(5000);

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->with(
                $this->callback(fn (QuantityPriceDefinition $def) => $def->getPrice() === 500.0),
                $this->context,
            )
            ->willReturn($this->createPrice(500.0));

        $this->process([$lineItem]);
    }

    public function testLabelShowsRoundUpInfo(): void
    {
        $lineItem = $this->createMeterLineItem(4050, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUND_UP, true);

        $this->helper->method('roundUpToMeter')->willReturn(5000);
        $this->calculator->method('calculate')->willReturn($this->createPrice(500.0));

        $this->process([$lineItem]);

        $this->assertSame(
            'Länge: 4.050 mm (berechnet: 5.000 mm)',
            $lineItem->getPayloadValue('rc_length_label')
        );
    }

    public function testNoRoundUpWhenFlagIsFalse(): void
    {
        $lineItem = $this->createMeterLineItem(4050, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUND_UP, false);

        $this->helper->expects($this->never())->method('roundUpToMeter');

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->with(
                $this->callback(fn (QuantityPriceDefinition $def) => $def->getPrice() === 405.0),
                $this->context,
            )
            ->willReturn($this->createPrice(405.0));

        $this->process([$lineItem]);
    }

    public function testNoRoundUpWhenFlagMissing(): void
    {
        $lineItem = $this->createMeterLineItem(4050, 100.0);

        $this->helper->expects($this->never())->method('roundUpToMeter');

        $this->calculator->method('calculate')->willReturn($this->createPrice(405.0));

        $this->process([$lineItem]);

        $this->assertSame('Länge: 4.050 mm', $lineItem->getPayloadValue('rc_length_label'));
    }

    public function testAcceptsLengthWithinBounds(): void
    {
        $lineItem = $this->createMeterLineItem(3000, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, 1000);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH, 6000);

        $this->calculator->expects($this->once())->method('calculate')->willReturn($this->createPrice(300.0));

        $this->process([$lineItem]);
    }

    private function createMeterLineItem(int $mm, float $unitPrice): LineItem
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, $mm);
        $lineItem->setPrice($this->createPrice($unitPrice));

        return $lineItem;
    }

    private function createPrice(float $unitPrice): CalculatedPrice
    {
        return new CalculatedPrice(
            $unitPrice,
            $unitPrice,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
        );
    }

    /** @param LineItem[] $items */
    private function process(array $items): void
    {
        $cart = new Cart('to-calculate');
        foreach ($items as $item) {
            $cart->getLineItems()->add($item);
        }

        $this->processor->process(
            new CartDataCollection(),
            new Cart('original'),
            $cart,
            $this->context,
            new CartBehavior(),
        );
    }
}
