<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Integration;

use Shopware\Core\Checkout\Cart\Price\CashRounding;
use Shopware\Core\Checkout\Cart\Price\GrossPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\NetPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Tax\TaxCalculator;

/**
 * Factory fuer eine echte QuantityPriceCalculator-Instanz aus Shopware-Core-Bausteinen.
 * Integration-Tests fahren damit gegen die produktive Rechen-Logik, nicht gegen Mocks.
 */
final class PriceCalculatorFactory
{
    public static function create(): QuantityPriceCalculator
    {
        $taxCalculator = new TaxCalculator();
        $rounding = new CashRounding();

        return new QuantityPriceCalculator(
            new GrossPriceCalculator($taxCalculator, $rounding),
            new NetPriceCalculator($taxCalculator, $rounding),
        );
    }
}
