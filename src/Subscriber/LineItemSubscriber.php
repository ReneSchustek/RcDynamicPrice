<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Subscriber;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelper;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class LineItemSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SystemConfigService $systemConfigService,
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

        $mmLength = $request->request->getInt('mmLength', 0);
        if ($mmLength <= 0) {
            return;
        }

        // Nur Produkte mit aktivem Meterpreis-Flag akzeptieren — verhindert Preismanipulation
        $productId = $event->getLineItem()->getReferencedId();
        if ($productId === null || !$this->meterProductHelper->isMeterProduct($productId, $event->getSalesChannelContext()->getContext())) {
            return;
        }

        // Serverseitige Bounds-Validierung als zweite Sicherheitsschicht
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();
        $minLength = $this->systemConfigService->getInt('RcDynamicPrice.config.minLength', $salesChannelId) ?: 1;
        $maxLength = $this->systemConfigService->getInt('RcDynamicPrice.config.maxLength', $salesChannelId) ?: 10000;

        if ($mmLength < $minLength || $mmLength > $maxLength) {
            return;
        }

        $lineItem = $event->getLineItem();
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, $mmLength);
        // Flag im Payload speichern damit der DynamicPriceProcessor es ohne DB-Zugriff prüfen kann
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
    }
}
