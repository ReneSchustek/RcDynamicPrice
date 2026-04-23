<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Storefront\Subscriber;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\MeterConfigResolverInterface;
use Ruhrcoder\RcDynamicPrice\Storefront\Struct\RcDynamicPriceConfigStruct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class ProductPageSubscriber implements EventSubscriberInterface
{
    /**
     * Attributname, unter dem Shopware-Storefront Cache-Tags für den aktuellen Request sammelt.
     * Der Storefront-Response-Subscriber liest die Liste und setzt sie als HTTP-Cache-Tags,
     * sodass gezielte Invalidierung über `CacheInvalidator::invalidate()` greift.
     */
    private const CACHE_TAGS_REQUEST_ATTRIBUTE = '_rc_dynamic_price_cache_tags';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly MeterConfigResolverInterface $configResolver,
        private readonly RequestStack $requestStack,
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
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();
        $context = $event->getSalesChannelContext()->getContext();

        $resolved = $this->configResolver->resolveForProduct($product, $salesChannelId, $context);

        $this->rememberCacheTags($resolved->cacheTags);

        if (!$resolved->active) {
            return;
        }

        $hintText = $this->systemConfigService->getString(
            DynamicPriceConstants::CONFIG_HINT_TEXT,
            $salesChannelId,
        );

        $event->getPage()->addExtension(
            'rcDynamicPriceConfig',
            new RcDynamicPriceConfigStruct(
                hintText: $hintText,
                minLength: $resolved->minLength,
                maxLength: $resolved->maxLength,
                roundingMode: $resolved->roundingMode,
                splitMode: $resolved->splitMode?->value ?? '',
                maxPieceLength: $resolved->maxPieceLength,
                splitHintTemplate: $resolved->splitHintTemplate,
            ),
        );
    }

    /**
     * Merkt die Cache-Tags am aktuellen Request vor, damit der StorefrontResponse-Subscriber
     * sie auf die HTTP-Antwort setzen kann. Ohne aktiven Request (z. B. CLI-Aufruf) wird
     * still übersprungen — HTTP-Cache ist im CLI-Pfad irrelevant.
     *
     * @param list<string> $tags
     */
    private function rememberCacheTags(array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return;
        }

        /** @var list<string> $existing */
        $existing = $request->attributes->get(self::CACHE_TAGS_REQUEST_ATTRIBUTE, []);
        $merged = array_values(array_unique([...$existing, ...$tags]));

        $request->attributes->set(self::CACHE_TAGS_REQUEST_ATTRIBUTE, $merged);
    }

    /** Wird vom StorefrontResponseSubscriber gelesen — keine Geschäftslogik. */
    public static function getCacheTagsRequestAttribute(): string
    {
        return self::CACHE_TAGS_REQUEST_ATTRIBUTE;
    }
}
