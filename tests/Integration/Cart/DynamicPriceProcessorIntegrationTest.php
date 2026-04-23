<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Integration\Cart;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcDynamicPrice\Cart\DynamicPriceProcessor;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Ruhrcoder\RcDynamicPrice\Tests\Integration\PriceCalculatorFactory;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Integrationstest: DynamicPriceProcessor gegen einen echten QuantityPriceCalculator aus
 * Shopware-Core. Stellt sicher, dass unsere Preis-Definition von der Kern-Engine korrekt
 * verarbeitet wird — Mock-basierte Unit-Tests faengen hier Signatur-Drifts nicht ab.
 */
final class DynamicPriceProcessorIntegrationTest extends TestCase
{
    private DynamicPriceProcessor $processor;
    private SalesChannelContext $context;

    protected function setUp(): void
    {
        $calculator = PriceCalculatorFactory::create();
        $helper = $this->createMock(MeterProductHelperInterface::class);
        $helper->method('roundUp')->willReturnCallback(
            static fn (int $mm, string $mode): int => match ($mode) {
                'full_m' => (int) \ceil($mm / 1000) * 1000,
                'half_m' => (int) \ceil($mm / 500) * 500,
                default => $mm,
            }
        );

        $this->processor = new DynamicPriceProcessor($calculator, $helper, new NullLogger());

        $this->context = $this->createMock(SalesChannelContext::class);
        $this->context->method('getTaxState')->willReturn('net');
        $this->context->method('getItemRounding')->willReturn(
            new CashRoundingConfig(2, 0.01, true),
        );
    }

    public function testCalculatesExpectedPriceForMeterLineItem(): void
    {
        $lineItem = $this->createMeterLineItem(1500, 100.0);

        $this->processor->process(
            new CartDataCollection(),
            new Cart('token'),
            $this->cartFromItems([$lineItem]),
            $this->context,
            new CartBehavior(),
        );

        $price = $lineItem->getPrice();
        self::assertNotNull($price);
        self::assertSame(150.0, $price->getUnitPrice());
        self::assertSame(150.0, $price->getTotalPrice());
        self::assertSame(1500, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
    }

    public function testRoundingRaisesBilledLengthAndPrice(): void
    {
        $lineItem = $this->createMeterLineItem(4050, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'full_m');

        $this->processor->process(
            new CartDataCollection(),
            new Cart('token'),
            $this->cartFromItems([$lineItem]),
            $this->context,
            new CartBehavior(),
        );

        $price = $lineItem->getPrice();
        self::assertNotNull($price);
        self::assertSame(500.0, $price->getUnitPrice(), 'Aufgerundet auf 5000 mm × 0,10 EUR/mm');
        self::assertSame(5000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
    }

    public function testSkipsItemsWithoutMeterActiveFlag(): void
    {
        $lineItem = new LineItem('plain-item', LineItem::PRODUCT_LINE_ITEM_TYPE, 'plain-product-id');
        $lineItem->setPrice($this->basePrice(42.0));

        $this->processor->process(
            new CartDataCollection(),
            new Cart('token'),
            $this->cartFromItems([$lineItem]),
            $this->context,
            new CartBehavior(),
        );

        // Preis bleibt unveraendert, weil das Item kein Meterartikel ist.
        $price = $lineItem->getPrice();
        self::assertNotNull($price);
        self::assertSame(42.0, $price->getUnitPrice());
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
