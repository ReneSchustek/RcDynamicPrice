<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Storefront\Subscriber;

use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Ruhrcoder\RcDynamicPrice\Storefront\Struct\RcDynamicPriceConfigStruct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ProductPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly MeterProductHelperInterface $meterProductHelper,
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

        if (!$this->meterProductHelper->isMeterProductEntity($product)) {
            return;
        }

        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();

        $hintText = $this->systemConfigService->getString(
            'RcDynamicPrice.config.hintText',
            $salesChannelId
        );

        $minLength = $this->meterProductHelper->getMinLength($product, $salesChannelId);
        $maxLength = $this->meterProductHelper->getMaxLength($product, $salesChannelId);
        $roundingMode = $this->meterProductHelper->getRoundingMode($product);

        $event->getPage()->addExtension(
            'rcDynamicPriceConfig',
            new RcDynamicPriceConfigStruct($hintText, $minLength, $maxLength, $roundingMode)
        );
    }
}
