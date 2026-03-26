<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Subscriber;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelper;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class LineItemSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly MeterProductHelper $meterProductHelper,
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

        // Originaleingabe speichern — das ist die Schnittlänge für die Bestellung.
        // Aufrunden für die Preisberechnung erfolgt im DynamicPriceProcessor.
        $cartItem = $event->getCart()->get($event->getLineItem()->getId()) ?? $event->getLineItem();
        $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, $mmLength);
        $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
    }
}
