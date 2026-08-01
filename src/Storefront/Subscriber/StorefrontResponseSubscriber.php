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
 *
 * Header-Format: **JSON-Array**, nicht kommasepariert. Der Core schreibt ihn mit
 * `json_encode` und liest ihn in `CacheStore::write()` sowie
 * `ReverseProxyCache::write()` mit `json_decode(..., JSON_THROW_ON_ERROR)`.
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
     * Beide Leser im Core — `CacheStore::write()` und `ReverseProxyCache::write()` —
     * rufen `json_decode($tagHeader, true, 512, JSON_THROW_ON_ERROR)`. Ein
     * `implode(',', …)` liess deshalb jede betroffene Seite mit einer
     * `JsonException` und HTTP 500 aussteigen, sobald der HTTP-Cache die Antwort
     * ablegen wollte. Geschrieben wird im Core mit `json_encode` (siehe
     * `ScriptController`).
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
            // Fremder Header in unerwartetem Format: lieber verwerfen als die
            // Antwort mit einer Exception zu zerreissen.
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
