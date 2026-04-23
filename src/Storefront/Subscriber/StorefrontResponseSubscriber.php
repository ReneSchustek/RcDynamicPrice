<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Storefront\Subscriber;

use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Heftet die vom ProductPageSubscriber im Request hinterlegten Cache-Tags an
 * die HTTP-Antwort. Shopware's Reverse-Proxy/HTTP-Cache liest den Header
 * `sw-cache-tags` und ermöglicht damit gezielte Invalidierung pro Kategorie
 * bzw. für den Plugin-Global-Scope.
 *
 * Die Header-Sammlung im Request existiert bereits — wir ergänzen nur die
 * Meterpreis-spezifischen Tags, ohne Shopware-Defaults zu überschreiben.
 */
final class StorefrontResponseSubscriber implements EventSubscriberInterface
{
    /** Shopware-konformer Header-Name für HTTP-Cache-Tags. */
    private const CACHE_TAGS_HEADER = 'sw-cache-tags';

    public static function getSubscribedEvents(): array
    {
        return [
            // Schreibt während des Rendervorgangs, sodass der Response-Listener die Tags sieht.
            StorefrontRenderEvent::class => 'onStorefrontRender',
            KernelEvents::RESPONSE => ['onResponse', -1024],
        ];
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        $tags = $this->pullTags($event->getRequest());
        if ($tags === []) {
            return;
        }

        // Re-Injektion, damit der später feuernde ResponseEvent die Tags wieder findet.
        $event->getRequest()->attributes->set(
            ProductPageSubscriber::getCacheTagsRequestAttribute(),
            $tags,
        );
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $tags = $this->pullTags($request);
        if ($tags === []) {
            return;
        }

        $existing = (string) $event->getResponse()->headers->get(self::CACHE_TAGS_HEADER, '');
        $parts = $existing === '' ? [] : array_filter(array_map('trim', explode(',', $existing)));

        $merged = array_values(array_unique([...$parts, ...$tags]));

        $event->getResponse()->headers->set(self::CACHE_TAGS_HEADER, implode(',', $merged));
    }

    /** @return list<string> */
    private function pullTags(\Symfony\Component\HttpFoundation\Request $request): array
    {
        $raw = $request->attributes->get(ProductPageSubscriber::getCacheTagsRequestAttribute(), []);
        if (!\is_array($raw)) {
            return [];
        }

        $tags = [];
        foreach ($raw as $tag) {
            if (\is_string($tag) && $tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
