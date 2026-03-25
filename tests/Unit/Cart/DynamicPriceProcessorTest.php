<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Cart;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Cart\DynamicPriceProcessor;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class DynamicPriceProcessorTest extends TestCase
{
    private QuantityPriceCalculator $calculator;
    private DynamicPriceProcessor $processor;
    private SalesChannelContext $context;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(QuantityPriceCalculator::class);
        $this->processor = new DynamicPriceProcessor($this->calculator);
        $this->context = $this->createMock(SalesChannelContext::class);
    }

    public function testSkipsLineItemWithoutMeterPriceActiveFlag(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('meterLengthMm', 1500);
        // rc_meter_price_active nicht gesetzt — Processor darf nicht rechnen

        $toCalculate = $this->createCartWithItems([$lineItem]);

        $this->calculator->expects($this->never())->method('calculate');

        $this->processor->process(
            new CartDataCollection(),
            new Cart('original'),
            $toCalculate,
            $this->context,
            new CartBehavior(),
        );
    }

    public function testSkipsLineItemWithoutMeterLengthPayload(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('rc_meter_price_active', true);

        $toCalculate = $this->createCartWithItems([$lineItem]);

        $this->calculator->expects($this->never())->method('calculate');

        $this->processor->process(
            new CartDataCollection(),
            new Cart('original'),
            $toCalculate,
            $this->context,
            new CartBehavior(),
        );
    }

    public function testSkipsLineItemWithZeroLength(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('rc_meter_price_active', true);
        $lineItem->setPayloadValue('meterLengthMm', 0);

        $toCalculate = $this->createCartWithItems([$lineItem]);

        $this->calculator->expects($this->never())->method('calculate');

        $this->processor->process(
            new CartDataCollection(),
            new Cart('original'),
            $toCalculate,
            $this->context,
            new CartBehavior(),
        );
    }

    public function testSkipsLineItemWithNegativeLength(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('rc_meter_price_active', true);
        $lineItem->setPayloadValue('meterLengthMm', -100);

        $toCalculate = $this->createCartWithItems([$lineItem]);

        $this->calculator->expects($this->never())->method('calculate');

        $this->processor->process(
            new CartDataCollection(),
            new Cart('original'),
            $toCalculate,
            $this->context,
            new CartBehavior(),
        );
    }

    public function testSkipsLineItemWithNullPrice(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('rc_meter_price_active', true);
        $lineItem->setPayloadValue('meterLengthMm', 1500);
        // Kein Preis gesetzt → getPrice() gibt null zurück

        $toCalculate = $this->createCartWithItems([$lineItem]);

        $this->calculator->expects($this->never())->method('calculate');

        $this->processor->process(
            new CartDataCollection(),
            new Cart('original'),
            $toCalculate,
            $this->context,
            new CartBehavior(),
        );
    }

    public function testCalculatesPriceForValidMeterLengthItem(): void
    {
        $taxRules = new TaxRuleCollection();
        $originalPrice = new CalculatedPrice(
            100.0,
            100.0,
            new CalculatedTaxCollection(),
            $taxRules,
        );

        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('rc_meter_price_active', true);
        $lineItem->setPayloadValue('meterLengthMm', 1500);
        $lineItem->setPrice($originalPrice);

        $adjustedPrice = new CalculatedPrice(
            150.0,
            150.0,
            new CalculatedTaxCollection(),
            $taxRules,
        );

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->with(
                $this->callback(fn(QuantityPriceDefinition $def) => $def->getPrice() === 150.0),
                $this->context,
            )
            ->willReturn($adjustedPrice);

        $toCalculate = $this->createCartWithItems([$lineItem]);

        $this->processor->process(
            new CartDataCollection(),
            new Cart('original'),
            $toCalculate,
            $this->context,
            new CartBehavior(),
        );

        $this->assertSame($adjustedPrice, $lineItem->getPrice());
    }

    public function testSetsLengthLabelPayload(): void
    {
        $taxRules = new TaxRuleCollection();
        $originalPrice = new CalculatedPrice(
            100.0,
            100.0,
            new CalculatedTaxCollection(),
            $taxRules,
        );

        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('rc_meter_price_active', true);
        $lineItem->setPayloadValue('meterLengthMm', 1500);
        $lineItem->setPrice($originalPrice);

        $this->calculator->method('calculate')->willReturn($originalPrice);

        $toCalculate = $this->createCartWithItems([$lineItem]);

        $this->processor->process(
            new CartDataCollection(),
            new Cart('original'),
            $toCalculate,
            $this->context,
            new CartBehavior(),
        );

        $this->assertSame('Länge: 1.500 mm', $lineItem->getPayloadValue('rc_length_label'));
    }

    /** @param LineItem[] $items */
    private function createCartWithItems(array $items): Cart
    {
        $cart = new Cart('to-calculate');
        foreach ($items as $item) {
            $cart->getLineItems()->add($item);
        }
        return $cart;
    }
}
