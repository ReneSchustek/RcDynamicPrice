<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;

/**
 * Baut die finale Cart-Situation für einen Meter-Artikel:
 *  - nutzt den LengthSplitter für die Split-Mathematik
 *  - mutiert das eingehende LineItem auf das erste Teilstück
 *  - erzeugt bei Bedarf Sibling-LineItems als eigene Cart-Einträge
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
            $config->minLength,
            $config->splitMode,
        );

        // Bei Merging liefert Shopware eine abweichende Instanz ueber Cart::get() — immer den Cart-Stand verwenden
        $cartItem = $cart->get($incoming->getId()) ?? $incoming;
        $this->writePayload($cartItem, $pieces[0], $config);

        if (\count($pieces) === 1 || $config->splitMode === null || $config->splitMode === SplitMode::Hint) {
            return;
        }

        $this->logger->info('RcDynamicPrice: LineItem wird in Teilstuecke aufgeteilt', [
            'lineItemId' => $cartItem->getId(),
            'productId' => $config->productId,
            'splitMode' => $config->splitMode->value,
            'pieces' => $pieces,
        ]);

        $this->appendSiblingPieces($cart, $cartItem->getId(), $pieces, $config);
    }

    /**
     * @param non-empty-list<int> $pieces
     */
    private function appendSiblingPieces(Cart $cart, string $originalId, array $pieces, MeterSplittingConfig $config): void
    {
        $pieceCount = \count($pieces);

        for ($i = 1; $i < $pieceCount; $i++) {
            $sibling = new LineItem(
                $originalId . '-piece' . $i,
                LineItem::PRODUCT_LINE_ITEM_TYPE,
                $config->productId,
                1,
            );

            $this->writePayload($sibling, $pieces[$i], $config);

            $cart->add($sibling);
        }
    }

    private function writePayload(LineItem $lineItem, int $lengthMm, MeterSplittingConfig $config): void
    {
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, $lengthMm);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, $config->roundingMode);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, $config->minLength);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH, $config->maxLength);
    }
}
