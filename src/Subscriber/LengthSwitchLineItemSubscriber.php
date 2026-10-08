<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Subscriber;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\LengthSettingsResolver;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Kennzeichnet Positionen von Artikeln mit Längenschalter, damit der Warenkorb die eingegebene Länge
 * statt der Anleitung zeigt.
 *
 * Der Schalter kann an einer Kategorie stehen; die Position kennt nur ihr Produkt. Entschieden wird
 * deshalb einmal beim Hinzufügen, mit derselben Auflösung wie auf der Produktseite.
 */
final class LengthSwitchLineItemSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MeterProductHelperInterface $productHelper,
        private readonly LengthSettingsResolver $resolver,
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
        $lineItem = $event->getLineItem();

        // Eine zusammengelegte Position trägt das Kennzeichen schon vom ersten Hinzufügen.
        if ($event->isMerged() || $lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE || $lineItem->getReferencedId() === null) {
            return;
        }

        $context = $event->getSalesChannelContext();
        $product = $this->productHelper->loadProduct($lineItem->getReferencedId(), $context->getContext());
        if ($product === null) {
            return;
        }

        $settings = $this->resolver->forProduct($product, $context->getSalesChannelId(), $context->getContext());

        // Die Nummer des Längenfeldes, nicht nur „an": Ein Artikel trägt weitere Eingaben wie Endkappen,
        // und nur das Längenfeld darf im Warenkorb als Länge erscheinen.
        if ($settings->lengthSwitch && $settings->lengthFieldNumber !== null) {
            $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_SWITCH, $settings->lengthFieldNumber);
        }
    }
}
