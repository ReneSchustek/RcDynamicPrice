<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Subscriber;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Service\LengthSplitterInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LineItemSubscriber implements EventSubscriberInterface
{
    /**
     * Marker-Keys, die andere Ruhrcoder-Plugins bei aktiven TMMS-/Custom-Field-Eingaben in den Request schreiben.
     * Ist einer davon gesetzt, besitzt ein hoeher priorisiertes Plugin die LineItem-ID — der Auto-Split
     * wird dann auf Hint-Verhalten reduziert, damit keine Sibling-Positionen ohne TMMS-Payload entstehen.
     */
    private const FOREIGN_ID_CONTROLLER_KEYS = [
        'rcTmmsActive',
        'rcCustomFieldsActive',
    ];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly MeterProductHelperInterface $meterProductHelper,
        private readonly LengthSplitterInterface $lengthSplitter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeLineItemAddedEvent::class => 'onBeforeLineItemAdded',
        ];
    }

    public function onBeforeLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }

        $rawLength = $request->request->get('mmLength', '');
        if ($rawLength === '' || $rawLength === null) {
            return;
        }

        $mmLength = (int) $rawLength;
        if ($mmLength <= 0) {
            return;
        }

        // referencedId ist immer die echte Produkt-ID, auch wenn die LineItem-ID den mm-Suffix enthält
        $productId = $event->getLineItem()->getReferencedId();
        if ($productId === null) {
            return;
        }

        $context = $event->getSalesChannelContext()->getContext();
        $product = $this->meterProductHelper->loadProduct($productId, $context);

        if ($product === null || !$this->meterProductHelper->isMeterProductEntity($product)) {
            return;
        }

        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();
        $minLength = $this->meterProductHelper->getMinLength($product, $salesChannelId);
        $maxLength = $this->meterProductHelper->getMaxLength($product, $salesChannelId);

        if ($mmLength < $minLength || $mmLength > $maxLength) {
            return;
        }

        $roundingMode = $this->meterProductHelper->getRoundingMode($product);
        $splitMode = $this->effectiveSplitMode($product, $salesChannelId, $request);
        $maxPieceLength = $this->meterProductHelper->getMaxPieceLength($product, $salesChannelId);

        $pieces = $this->lengthSplitter->split($mmLength, $maxPieceLength, $minLength, $splitMode);

        // Immer den Cart-Eintrag nehmen: bei Merging ist das eine andere Instanz als $event->getLineItem()
        $cartItem = $event->getCart()->get($event->getLineItem()->getId()) ?? $event->getLineItem();
        $this->writeLineItemPayload($cartItem, $pieces[0], $roundingMode, $minLength, $maxLength);

        if (\count($pieces) === 1 || $splitMode === null || $splitMode === SplitMode::Hint) {
            return;
        }

        $this->appendSiblingPieces(
            cart: $event->getCart(),
            originalId: $cartItem->getId(),
            productId: $productId,
            pieces: $pieces,
            roundingMode: $roundingMode,
            minLength: $minLength,
            maxLength: $maxLength,
        );
    }

    /**
     * Liefert den anwendbaren Split-Modus.
     * Wenn ein Plugin mit hoeherer ID-Prioritaet (RcCartSplitter, RcCustomFields) am Request beteiligt ist,
     * wird Auto-Split deaktiviert, um Payload-Verlust auf Sibling-LineItems zu vermeiden.
     */
    private function effectiveSplitMode(
        \Shopware\Core\Content\Product\ProductEntity $product,
        string $salesChannelId,
        Request $request,
    ): ?SplitMode {
        $configured = $this->meterProductHelper->getSplitMode($product, $salesChannelId);

        if ($configured === null || $configured === SplitMode::Hint) {
            return $configured;
        }

        foreach (self::FOREIGN_ID_CONTROLLER_KEYS as $key) {
            if ($request->request->get($key, '') !== '' && $request->request->get($key) !== null) {
                return SplitMode::Hint;
            }
        }

        return $configured;
    }

    /**
     * Haengt alle Teilstuecke ab Index 1 als neue LineItems an den Cart.
     *
     * @param non-empty-list<int> $pieces
     */
    private function appendSiblingPieces(
        Cart $cart,
        string $originalId,
        string $productId,
        array $pieces,
        string $roundingMode,
        int $minLength,
        int $maxLength,
    ): void {
        $pieceCount = \count($pieces);

        for ($i = 1; $i < $pieceCount; $i++) {
            $sibling = new LineItem(
                $originalId . '-piece' . $i,
                LineItem::PRODUCT_LINE_ITEM_TYPE,
                $productId,
                1,
            );

            $this->writeLineItemPayload($sibling, $pieces[$i], $roundingMode, $minLength, $maxLength);

            $cart->add($sibling);
        }
    }

    /**
     * Schreibt die validierten Laengendaten in den LineItem-Payload.
     * Der Processor liest diese Werte, ohne das Produkt erneut laden zu muessen.
     */
    private function writeLineItemPayload(
        LineItem $lineItem,
        int $lengthMm,
        string $roundingMode,
        int $minLength,
        int $maxLength,
    ): void {
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, $lengthMm);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, $roundingMode);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, $minLength);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH, $maxLength);
    }
}
