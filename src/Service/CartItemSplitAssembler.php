<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;

/**
 * Baut die Cart-Situation für einen Meter-Artikel:
 *  - nutzt den LengthSplitter für die Split-Mathematik
 *  - schreibt Eingabelänge und Aufteilung in den Payload der Position
 *
 * Eine Position bildet einen **Zuschnitt-Auftrag** ab, kein Teilstück. Die Teilstücke sind eine
 * Fertigungsfolge und werden als Payload mitgeführt, nicht als eigene Cart-Einträge. Vorher war
 * jedes Teilstück ein eigenes LineItem — der Kunde konnte damit ein Reststück einzeln löschen und
 * bestellte kommentarlos eine kürzere Länge als eingegeben.
 *
 * Der Service ist Request-agnostisch — die Mode-Ermittlung inkl. ID-Controller-Fallback
 * liegt im Subscriber, damit der Assembler ohne HTTP-Kontext testbar bleibt.
 */
final class CartItemSplitAssembler implements CartItemSplitAssemblerInterface
{
    public function __construct(
        private readonly LengthSplitterInterface $lengthSplitter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function assemble(Cart $cart, LineItem $incoming, int $mmLength, MeterSplittingConfig $config): void
    {
        $pieces = $this->lengthSplitter->split(
            $mmLength,
            $config->maxPieceLength,
            $config->splitMode,
            $config->equalSplitBilling,
        );

        // Bei Merging liefert Shopware eine abweichende Instanz über Cart::get() — immer den Cart-Stand verwenden
        $cartItem = $cart->get($incoming->getId()) ?? $incoming;

        if (\count($pieces) > 1) {
            $this->logger->info('RcDynamicPrice: Zuschnitt-Auftrag wird in Teilstücke gefertigt', [
                'lineItemId' => $cartItem->getId(),
                'productId' => $config->productId,
                'splitMode' => $config->splitMode?->value,
                'requestedLengthMm' => $mmLength,
                'pieces' => $pieces,
            ]);
        }

        $this->writePayload($cartItem, $mmLength, $pieces, $config);
    }

    /**
     * @param non-empty-list<int> $pieces
     */
    private function writePayload(LineItem $lineItem, int $mmLength, array $pieces, MeterSplittingConfig $config): void
    {
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, $mmLength);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES, $pieces);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, $config->roundingMode);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, $config->minLength);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH, $config->maxLength);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_BILLING, $this->billsShortPiecesAtMinimum($config));
    }

    /**
     * Werden Teilstücke unter der Mindestlänge mit der Mindestlänge **abgerechnet**?
     *
     * Geschnitten werden sie immer in ihrer tatsächlichen Länge — die Mindestlänge ist eine
     * Abrechnungsregel. Beim `max_rest`-Split gilt sie immer (ein Reststück kostet mindestens ein
     * Mindeststück), beim `equal`-Split entscheidet die Händler-Option `equalSplitEnforceMin`.
     */
    private function billsShortPiecesAtMinimum(MeterSplittingConfig $config): bool
    {
        return match ($config->splitMode) {
            SplitMode::MaxRest => true,
            SplitMode::Equal => $config->equalSplitEnforceMin,
            default => false,
        };
    }
}
