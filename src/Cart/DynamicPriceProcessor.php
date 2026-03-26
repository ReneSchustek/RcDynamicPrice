<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Cart;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
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
            if ($lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE) !== true) {
                continue;
            }

            $mmLength = $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM);

            if (!is_int($mmLength) || $mmLength <= 0) {
                continue;
            }

            $price = $lineItem->getPrice();
            if ($price === null) {
                continue;
            }

            // Aufrunden prüfen — Custom Fields werden vom ProductCartProcessor im Payload gespeichert
            $customFields = $lineItem->getPayloadValue('customFields') ?? [];
            $roundUp = (bool) ($customFields[DynamicPriceConstants::FIELD_ROUND_UP_METER] ?? false);
            $billedLength = $roundUp ? (int) (ceil($mmLength / 1000) * 1000) : $mmLength;

            // Grundpreis gilt pro 1.000 mm (1 m) – Preis proportional zur berechneten Länge skalieren
            $adjustedUnitPrice = ($price->getUnitPrice() / 1000.0) * $billedLength;

            $definition = new QuantityPriceDefinition(
                $adjustedUnitPrice,
                $price->getTaxRules(),
                $lineItem->getQuantity()
            );

            $lineItem->setPrice($this->calculator->calculate($definition, $context));

            // Längenbezeichnung für Warenkorb- und Bestellübersicht
            $label = 'Länge: ' . number_format($mmLength, 0, ',', '.') . ' mm';
            if ($roundUp && $billedLength !== $mmLength) {
                $label .= ' (berechnet: ' . number_format($billedLength, 0, ',', '.') . ' mm)';
            }

            $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_LABEL, $label);
        }
    }
}
