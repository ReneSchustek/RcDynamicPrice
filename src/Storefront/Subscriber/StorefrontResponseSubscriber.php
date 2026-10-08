<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Storefront\Subscriber;

use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Heftet die vom ProductPageSubscriber am Request gesammelten Cache-Tags an die HTTP-Antwort. Shopwares
 * HTTP-Cache und Reverse-Proxy lesen den Header `sw-cache-tags`; so lässt sich je Kategorie oder für
 * die Grundeinstellung gezielt verwerfen.
 *
 * Tags, die schon im Header stehen, bleiben erhalten; ergänzt werden nur die des Meterpreises.
 *
 * Der Header ist ein JSON-Array, keine kommagetrennte Liste. Der Kern schreibt ihn mit `json_encode`
 * und liest ihn in `CacheStore::write()` und `ReverseProxyCache::write()` mit
 * `json_decode(..., JSON_THROW_ON_ERROR)`.
 */
final class StorefrontResponseSubscriber implements EventSubscriberInterface
{
    /** Shopware-konformer Header-Name für HTTP-Cache-Tags. */
    private const CACHE_TAGS_HEADER = 'sw-cache-tags';

    public static function getSubscribedEvents(): array
    {
        return [
            // Schreibt während des Renderns, damit der Response-Listener die Tags noch findet.
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

        // Wieder ans Request-Attribut, damit das später ausgelöste ResponseEvent die Tags findet.
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

        $merged = array_values(array_unique([
            ...$this->decodeExistingTags($event->getResponse()->headers->get(self::CACHE_TAGS_HEADER)),
            ...$tags,
        ]));

        $event->getResponse()->headers->set(
            self::CACHE_TAGS_HEADER,
            json_encode($merged, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Der Header transportiert ein JSON-Array, keine kommaseparierte Liste.
     *
     * Beide Leser im Kern, `CacheStore::write()` und `ReverseProxyCache::write()`, rufen
     * `json_decode($tagHeader, true, 512, JSON_THROW_ON_ERROR)`. Ein `implode(',', …)` ließe jede
     * betroffene Seite mit einer `JsonException` und HTTP 500 aussteigen, sobald der HTTP-Cache die
     * Antwort ablegen will. Der Kern schreibt mit `json_encode` (siehe `ScriptController`).
     *
     * @return list<string>
     */
    private function decodeExistingTags(?string $header): array
    {
        if ($header === null || $header === '') {
            return [];
        }

        try {
            $decoded = json_decode($header, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Ein fremder Header in unerwartetem Format wird verworfen, statt die Antwort mit einer
            // Exception abzubrechen.
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        $tags = [];
        foreach ($decoded as $tag) {
            if (\is_string($tag) && $tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
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
