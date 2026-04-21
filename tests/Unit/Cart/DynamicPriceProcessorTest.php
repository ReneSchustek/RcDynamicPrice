<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Cart;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
    private LoggerInterface $logger;
    private DynamicPriceProcessor $processor;
    private SalesChannelContext $context;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(QuantityPriceCalculator::class);
        $this->helper = $this->createMock(MeterProductHelperInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->processor = new DynamicPriceProcessor($this->calculator, $this->helper, $this->logger);
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

    public function testSetsBilledLengthPayloadWithoutRounding(): void
    {
        $lineItem = $this->createMeterLineItem(1500, 100.0);

        $this->calculator->method('calculate')->willReturn($this->createPrice(150.0));

        $this->process([$lineItem]);

        $this->assertSame(1500, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
    }

    public function testCalculatesPriceWithFullMeterRounding(): void
    {
        $lineItem = $this->createMeterLineItem(4050, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'full_m');

        $this->helper->method('roundUp')->with(4050, 'full_m')->willReturn(5000);

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

    public function testBilledLengthReflectsRounding(): void
    {
        $lineItem = $this->createMeterLineItem(4050, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'full_m');

        $this->helper->method('roundUp')->willReturn(5000);
        $this->calculator->method('calculate')->willReturn($this->createPrice(500.0));

        $this->process([$lineItem]);

        $this->assertSame(5000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
    }

    public function testCalculatesPriceWithQuarterMeterRounding(): void
    {
        $lineItem = $this->createMeterLineItem(1300, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'quarter_m');

        $this->helper->method('roundUp')->with(1300, 'quarter_m')->willReturn(1500);

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->with(
                $this->callback(fn (QuantityPriceDefinition $def) => $def->getPrice() === 150.0),
                $this->context,
            )
            ->willReturn($this->createPrice(150.0));

        $this->process([$lineItem]);
    }

    public function testCalculatesPriceWithHalfMeterRounding(): void
    {
        $lineItem = $this->createMeterLineItem(2100, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'half_m');

        $this->helper->method('roundUp')->with(2100, 'half_m')->willReturn(2500);

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->with(
                $this->callback(fn (QuantityPriceDefinition $def) => $def->getPrice() === 250.0),
                $this->context,
            )
            ->willReturn($this->createPrice(250.0));

        $this->process([$lineItem]);
    }

    public function testCalculatesPriceWithCmRounding(): void
    {
        $lineItem = $this->createMeterLineItem(1505, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'cm');

        $this->helper->method('roundUp')->with(1505, 'cm')->willReturn(1510);

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->with(
                $this->callback(fn (QuantityPriceDefinition $def) => $def->getPrice() === 151.0),
                $this->context,
            )
            ->willReturn($this->createPrice(151.0));

        $this->process([$lineItem]);
    }

    public function testNoRoundingWhenModeIsNone(): void
    {
        $lineItem = $this->createMeterLineItem(4050, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'none');

        $this->helper->method('roundUp')->with(4050, 'none')->willReturn(4050);

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

    public function testNoRoundingWhenModeIsMissing(): void
    {
        $lineItem = $this->createMeterLineItem(4050, 100.0);

        $this->calculator->method('calculate')->willReturn($this->createPrice(405.0));

        $this->process([$lineItem]);

        $this->assertSame(4050, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
    }

    public function testAcceptsLengthWithinBounds(): void
    {
        $lineItem = $this->createMeterLineItem(3000, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, 1000);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH, 6000);

        $this->calculator->expects($this->once())->method('calculate')->willReturn($this->createPrice(300.0));

        $this->process([$lineItem]);
    }

    public function testRoundingAppliesPerSiblingWhenCartHasSplitItems(): void
    {
        // Szenario BRIEF16: 3x 4750 mm landen im Cart (vom Subscriber erzeugt), full_m rundet pro Teilstueck auf 5000 mm.
        $primary = $this->createMeterLineItemWithId('primary-id', 4750, 100.0);
        $primary->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'full_m');

        $sibling1 = $this->createMeterLineItemWithId('primary-id-piece1', 4750, 100.0);
        $sibling1->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'full_m');

        $sibling2 = $this->createMeterLineItemWithId('primary-id-piece2', 4750, 100.0);
        $sibling2->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'full_m');

        $this->helper->method('roundUp')->with(4750, 'full_m')->willReturn(5000);
        $this->calculator->method('calculate')->willReturn($this->createPrice(500.0));

        $this->process([$primary, $sibling1, $sibling2]);

        // Jeder LineItem wird unabhaengig gerundet und behaelt seine eigene billed_length
        $this->assertSame(5000, $primary->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
        $this->assertSame(5000, $sibling1->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
        $this->assertSame(5000, $sibling2->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
    }

    private function createMeterLineItemWithId(string $id, int $mm, float $unitPrice): LineItem
    {
        $lineItem = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, $mm);
        $lineItem->setPrice($this->createPrice($unitPrice));

        return $lineItem;
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
