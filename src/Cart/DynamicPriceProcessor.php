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
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class DynamicPriceProcessor implements CartProcessorInterface
{
    public function __construct(
        private readonly QuantityPriceCalculator $calculator,
        private readonly MeterProductHelperInterface $meterProductHelper,
        private readonly LoggerInterface $logger,
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
                $this->logger->warning('RcDynamicPrice: Ungueltige Laenge im LineItem', [
                    'lineItemId' => $lineItem->getId(),
                    'meterLengthMm' => $mmLength,
                ]);
                continue;
            }

            // Serverseitige Bounds-Validierung — Min/Max wurde vom Subscriber im Payload gespeichert
            $minLength = $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH);
            $maxLength = $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH);

            if (is_int($minLength) && $mmLength < $minLength) {
                $this->logger->warning('RcDynamicPrice: Laenge unter Minimum', [
                    'lineItemId' => $lineItem->getId(),
                    'meterLengthMm' => $mmLength,
                    'minLength' => $minLength,
                ]);
                continue;
            }

            if (is_int($maxLength) && $mmLength > $maxLength) {
                $this->logger->warning('RcDynamicPrice: Laenge ueber Maximum', [
                    'lineItemId' => $lineItem->getId(),
                    'meterLengthMm' => $mmLength,
                    'maxLength' => $maxLength,
                ]);
                continue;
            }

            $price = $lineItem->getPrice();
            if ($price === null || $price->getUnitPrice() <= 0.0) {
                $this->logger->warning('RcDynamicPrice: Kein oder ungültiger Preis am LineItem', [
                    'lineItemId' => $lineItem->getId(),
                    'unitPrice' => $price?->getUnitPrice(),
                ]);
                continue;
            }

            // Rundungsmodus vom Subscriber im Payload gespeichert
            $roundingMode = $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING);
            $billedLength = \is_string($roundingMode)
                ? $this->meterProductHelper->roundUp($mmLength, $roundingMode)
                : $mmLength;

            $adjustedUnitPrice = ($price->getUnitPrice() / 1000.0) * $billedLength;

            $definition = new QuantityPriceDefinition(
                $adjustedUnitPrice,
                $price->getTaxRules(),
                $lineItem->getQuantity()
            );

            $lineItem->setPrice($this->calculator->calculate($definition, $context));

            $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM, $billedLength);
        }
    }
}
