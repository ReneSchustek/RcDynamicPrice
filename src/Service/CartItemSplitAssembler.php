<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;

/**
 * Schreibt einen Zuschnitt-Auftrag in eine Warenkorbposition: Eingabelänge, Teilstücke aus dem
 * LengthSplitter und die Angaben, die der Processor für den Preis braucht.
 *
 * Eine Position ist ein Auftrag, kein Teilstück. Die Teilstücke sind eine Fertigungsfolge im Payload
 * und keine eigenen Warenkorbeinträge; als eigene Einträge ließe sich ein Reststück einzeln löschen,
 * und der Kunde bestellte kommentarlos weniger, als er eingegeben hat.
 *
 * Den Modus einschließlich des Rückfalls bei fremden ID-Controllern bestimmt der Subscriber; der
 * Assembler kommt so ohne Request aus und bleibt ohne HTTP-Kontext testbar.
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

        // Beim Zusammenführen liefert Cart::get() eine andere Instanz als das eingehende Objekt;
        // beschrieben wird die im Warenkorb.
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
     * Werden Teilstücke unter der Mindestlänge mit der Mindestlänge abgerechnet?
     *
     * Geschnitten werden sie immer in ihrer tatsächlichen Länge; die Mindestlänge ist eine
     * Abrechnungsregel. Beim `max_rest`-Split gilt sie immer, weil ein Reststück mindestens ein
     * Mindeststück kostet. Beim `equal`-Split entscheidet die Händler-Option `equalSplitEnforceMin`.
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
