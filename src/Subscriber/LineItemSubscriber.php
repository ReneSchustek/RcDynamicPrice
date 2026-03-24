<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Subscriber;

use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class LineItemSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SystemConfigService $systemConfigService,
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

        // Serverseitige Bounds-Validierung als zweite Sicherheitsschicht
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();
        $minLength = $this->systemConfigService->getInt('RcDynamicPrice.config.minLength', $salesChannelId) ?: 1;
        $maxLength = $this->systemConfigService->getInt('RcDynamicPrice.config.maxLength', $salesChannelId) ?: 10000;

        if ($mmLength < $minLength || $mmLength > $maxLength) {
            return;
        }

        $event->getLineItem()->setPayloadValue('meterLengthMm', $mmLength);
    }
}
