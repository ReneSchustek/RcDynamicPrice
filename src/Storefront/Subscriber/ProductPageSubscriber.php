<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Storefront\Subscriber;

use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelper;
use Ruhrcoder\RcDynamicPrice\Storefront\Struct\RcDynamicPriceConfigStruct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ProductPageSubscriber implements EventSubscriberInterface
{
    private const DEFAULT_HINT_TEXT = 'Bitte Länge in Millimetern eingeben – z. B. 1500 für 1,5 m';
    private const DEFAULT_MIN_LENGTH = 1;
    private const DEFAULT_MAX_LENGTH = 10000;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly MeterProductHelper $meterProductHelper,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $product = $event->getPage()->getProduct();

        // Eingabefeld nur für explizit als Meterartikel markierte Produkte einblenden
        if (!$this->meterProductHelper->isMeterProductEntity($product)) {
            return;
        }

        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();

        $hintText = $this->systemConfigService->getString(
            'RcDynamicPrice.config.hintText',
            $salesChannelId
        ) ?: self::DEFAULT_HINT_TEXT;

        $minLength = $this->systemConfigService->getInt(
            'RcDynamicPrice.config.minLength',
            $salesChannelId
        ) ?: self::DEFAULT_MIN_LENGTH;

        $maxLength = $this->systemConfigService->getInt(
            'RcDynamicPrice.config.maxLength',
            $salesChannelId
        ) ?: self::DEFAULT_MAX_LENGTH;

        $event->getPage()->addExtension(
            'rcDynamicPriceConfig',
            new RcDynamicPriceConfigStruct($hintText, $minLength, $maxLength)
        );
    }
}
