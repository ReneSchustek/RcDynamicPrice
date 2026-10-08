<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Storefront\Subscriber;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\MeterConfigResolverInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelper;
use Ruhrcoder\RcDynamicPrice\Service\Metrics\MetricsRecorderInterface;
use Ruhrcoder\RcDynamicPrice\Storefront\Struct\RcDynamicPriceConfigStruct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Gibt der Produktseite eines Meterartikels die Angaben für das Widget mit und merkt sich die
 * Cache-Tags der Kategoriekette, damit eine geänderte Einstellung die Seite verwirft.
 */
final class ProductPageSubscriber implements EventSubscriberInterface
{
    /**
     * Das Request-Attribut, unter dem diese Erweiterung ihre Cache-Tags sammelt. Der
     * StorefrontResponseSubscriber schreibt sie in den Header `sw-cache-tags`, damit
     * `CacheInvalidator::invalidate()` gezielt greift.
     */
    private const CACHE_TAGS_REQUEST_ATTRIBUTE = '_rc_dynamic_price_cache_tags';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly MeterConfigResolverInterface $configResolver,
        private readonly RequestStack $requestStack,
        private readonly MetricsRecorderInterface $metrics,
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
                roundingSteps: MeterProductHelper::ROUNDING_STEPS,
                equalSplitBilling: $resolved->equalSplitBilling,
                equalSplitEnforceMin: $resolved->equalSplitEnforceMin,
            ),
        );

        // Zählt gezeigte Widgets; der Recorder wirft per Vertrag nie.
        $this->metrics->increment(DynamicPriceConstants::METRIC_PRODUCT_PAGE_WIDGET_SHOWN);
    }

    /**
     * Merkt die Cache-Tags am aktuellen Request vor, damit der StorefrontResponseSubscriber sie auf
     * die HTTP-Antwort setzt. Ohne Request, etwa auf der Kommandozeile, gibt es keinen HTTP-Cache
     * und nichts zu tun.
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

    /** Für den StorefrontResponseSubscriber, der die Tags wieder abholt. */
    public static function getCacheTagsRequestAttribute(): string
    {
        return self::CACHE_TAGS_REQUEST_ATTRIBUTE;
    }
}
