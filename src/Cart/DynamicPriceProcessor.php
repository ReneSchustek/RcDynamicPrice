<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Cart;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
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
        private readonly MeterProductHelperInterface $meterProductHelper,
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

            // Serverseitige Bounds-Validierung — Min/Max wurde vom Subscriber im Payload gespeichert
            $minLength = $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH);
            $maxLength = $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH);

            if (is_int($minLength) && $mmLength < $minLength) {
                continue;
            }

            if (is_int($maxLength) && $mmLength > $maxLength) {
                continue;
            }

            $price = $lineItem->getPrice();
            if ($price === null) {
                continue;
            }

            // Aufrunden — Flag vom Subscriber gesetzt, Logik aus Helper (kein Duplikat)
            $roundUp = $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_ROUND_UP) === true;
            $billedLength = $roundUp
                ? $this->meterProductHelper->roundUpToMeter($mmLength)
                : $mmLength;

            $adjustedUnitPrice = ($price->getUnitPrice() / 1000.0) * $billedLength;

            $definition = new QuantityPriceDefinition(
                $adjustedUnitPrice,
                $price->getTaxRules(),
                $lineItem->getQuantity()
            );

            $lineItem->setPrice($this->calculator->calculate($definition, $context));

            $label = 'Länge: ' . number_format($mmLength, 0, ',', '.') . ' mm';
            if ($roundUp && $billedLength !== $mmLength) {
                $label .= ' (berechnet: ' . number_format($billedLength, 0, ',', '.') . ' mm)';
            }

            $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_LABEL, $label);
        }
    }
}
