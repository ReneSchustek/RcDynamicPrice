<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Integration\Cart;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcDynamicPrice\Cart\DynamicPriceProcessor;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Ruhrcoder\RcDynamicPrice\Service\Metrics\NullMetricsRecorder;
use Ruhrcoder\RcDynamicPrice\Tests\Integration\PriceCalculatorFactory;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\CashRounding;
use Shopware\Core\Checkout\Cart\Price\PercentagePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Checkout\Cart\Tax\PercentageTaxRuleBuilder;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\System\Locale\LanguageLocaleCodeProvider;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * Pipeline-Reihenfolge gegen den Live-Bug: ein nach dem DynamicPriceProcessor angewendeter
 * prozentualer Rabatt MUSS auf dem ausmultiplizierten Positions-Preis operieren (Meterpreis × Länge),
 * nicht auf dem rohen Meter-Stückpreis.
 *
 * Der Test simuliert die korrekte Cart-Processor-Reihenfolge gegen echte Shopware-Core-Rechner —
 * dadurch fängt er sowohl eine Drift in der services.xml-Priority als auch eine Regression in der
 * Preis-Definition des DynamicPriceProcessor.
 */
final class DynamicPricePromotionOrderIntegrationTest extends TestCase
{
    private DynamicPriceProcessor $processor;
    private PercentagePriceCalculator $percentagePriceCalculator;
    private SalesChannelContext&MockObject $context;

    protected function setUp(): void
    {
        $quantityPriceCalculator = PriceCalculatorFactory::create();
        $helper = $this->createMock(MeterProductHelperInterface::class);
        $helper->method('roundUp')->willReturnCallback(
            static fn (int $mm, string $mode): int => $mm,
        );

        $localeProvider = $this->createMock(LanguageLocaleCodeProvider::class);
        $localeProvider->method('getLocaleForLanguageId')->willReturn('de-DE');

        $this->processor = new DynamicPriceProcessor(
            $quantityPriceCalculator,
            $helper,
            new NullLogger(),
            new NullMetricsRecorder(),
            new IdentityTranslator(),
            $localeProvider,
        );

        $this->percentagePriceCalculator = new PercentagePriceCalculator(
            new CashRounding(),
            $quantityPriceCalculator,
            new PercentageTaxRuleBuilder(),
        );

        $this->context = $this->createMock(SalesChannelContext::class);
        $this->context->method('getTaxState')->willReturn('net');
        $this->context->method('getItemRounding')->willReturn(
            new CashRoundingConfig(2, 0.01, true),
        );
    }

    public function testThreePercentDiscountAppliesToMultipliedPositionPrice(): void
    {
        // Meterpreis 10 €, Länge 3 m → Positions-Preis 30 € → 3-%-Skonto = 0,90 €.
        // Bug-Verhalten vor Fix: 3 % von 10 € = 0,30 € (Skonto wirkt nur auf den Meterpreis).
        $lineItem = $this->createMeterLineItem(3000, 10.0);

        $this->processor->process(
            new CartDataCollection(),
            new Cart('token'),
            $this->cartFromItems([$lineItem]),
            $this->context,
            new CartBehavior(),
        );

        $itemPrice = $lineItem->getPrice();
        self::assertNotNull($itemPrice);
        self::assertSame(30.0, $itemPrice->getUnitPrice(), 'Positions-Preis nach DynamicPriceProcessor.');

        $discount = $this->percentagePriceCalculator->calculate(
            -3.0,
            new PriceCollection([$itemPrice]),
            $this->context,
        );

        self::assertEqualsWithDelta(-0.90, $discount->getTotalPrice(), 0.005, '3-%-Rabatt von 30 € muss 0,90 € sein.');
        self::assertEqualsWithDelta(29.10, $itemPrice->getTotalPrice() + $discount->getTotalPrice(), 0.005);
    }

    public function testDiscountScalesWithLengthVariations(): void
    {
        $cases = [
            // [Eingabe-mm, Meter-Stückpreis, erwarteter Positions-Preis, erwarteter Rabattbetrag, Endpreis]
            [1500, 10.0, 15.0, -0.45, 14.55],
            [5000, 10.0, 50.0, -1.50, 48.50],
            [6000, 10.0, 60.0, -1.80, 58.20],
        ];

        foreach ($cases as $case) {
            [$mm, $unit, $expectedPosition, $expectedDiscount, $expectedFinal] = $case;

            $lineItem = $this->createMeterLineItem($mm, $unit);

            $this->processor->process(
                new CartDataCollection(),
                new Cart('token'),
                $this->cartFromItems([$lineItem]),
                $this->context,
                new CartBehavior(),
            );

            $itemPrice = $lineItem->getPrice();
            self::assertNotNull($itemPrice);
            self::assertSame($expectedPosition, $itemPrice->getUnitPrice(), "Positions-Preis bei {$mm} mm × {$unit} €.");

            $discount = $this->percentagePriceCalculator->calculate(
                -3.0,
                new PriceCollection([$itemPrice]),
                $this->context,
            );

            self::assertEqualsWithDelta(
                $expectedDiscount,
                $discount->getTotalPrice(),
                0.005,
                "Rabattbetrag bei Positions-Preis {$expectedPosition} €.",
            );

            self::assertEqualsWithDelta(
                $expectedFinal,
                $itemPrice->getTotalPrice() + $discount->getTotalPrice(),
                0.005,
                "Endpreis bei {$mm} mm.",
            );
        }
    }

    private function createMeterLineItem(int $mmLength, float $basePrice): LineItem
    {
        $lineItem = new LineItem('meter-item', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, $mmLength);
        $lineItem->setPrice($this->basePrice($basePrice));

        return $lineItem;
    }

    private function basePrice(float $unit): CalculatedPrice
    {
        return new CalculatedPrice(
            $unit,
            $unit,
            new CalculatedTaxCollection(),
            new TaxRuleCollection([new TaxRule(19.0)]),
        );
    }

    /**
     * @param list<LineItem> $items
     */
    private function cartFromItems(array $items): Cart
    {
        $cart = new Cart(Defaults::CURRENCY);
        $cart->setLineItems(new LineItemCollection($items));

        return $cart;
    }
}
