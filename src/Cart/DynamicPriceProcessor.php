<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class DynamicPriceProcessor implements CartProcessorInterface
{
    public function __construct(
        private readonly QuantityPriceCalculator $calculator,
    ) {
    }

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior,
    ): void {
        foreach ($toCalculate->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            $mmLength = $lineItem->getPayloadValue('meterLengthMm');

            if (!is_int($mmLength) || $mmLength <= 0) {
                continue;
            }

            $price = $lineItem->getPrice();
            if ($price === null) {
                continue;
            }

            // Grundpreis gilt pro 1.000 mm (1 m) – Preis proportional zur Länge skalieren
            $adjustedUnitPrice = ($price->getUnitPrice() / 1000.0) * $mmLength;

            $definition = new QuantityPriceDefinition(
                $adjustedUnitPrice,
                $price->getTaxRules(),
                $lineItem->getQuantity()
            );

            $lineItem->setPrice($this->calculator->calculate($definition, $context));

            // Längenbezeichnung für Warenkorb- und Bestellübersicht
            $lineItem->setPayloadValue(
                'rc_length_label',
                'Länge: ' . number_format($mmLength, 0, ',', '.') . ' mm'
            );
        }
    }
}
