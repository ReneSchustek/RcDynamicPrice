<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Subscriber;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class LineItemSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly MeterProductHelperInterface $meterProductHelper,
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

        $roundUp = $this->meterProductHelper->shouldRoundUpToMeter($product);

        // Originaleingabe + Konfiguration als Payload speichern.
        // Der Processor liest diese Werte, ohne das Produkt erneut laden zu müssen.
        $cartItem = $event->getCart()->get($event->getLineItem()->getId()) ?? $event->getLineItem();
        $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, $mmLength);
        $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
        $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUND_UP, $roundUp);
        $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, $minLength);
        $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH, $maxLength);
    }
}
