<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Storefront\Subscriber\ProductPageSubscriber;
use Ruhrcoder\RcDynamicPrice\Storefront\Subscriber\StorefrontResponseSubscriber;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pinning-Tests gegen die HTTP-Cache-Tag-Anhängung — der Header `sw-cache-tags`
 * ist Sicherheits-/Konsistenz-relevant, weil HTTP-Cache-Invalidierung darauf basiert.
 */
#[CoversClass(StorefrontResponseSubscriber::class)]
final class StorefrontResponseSubscriberTest extends TestCase
{
    private const CACHE_TAGS_HEADER = 'sw-cache-tags';

    public function testSubscribesToStorefrontRenderAndResponseEvents(): void
    {
        $events = StorefrontResponseSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(StorefrontRenderEvent::class, $events);
        self::assertArrayHasKey(KernelEvents::RESPONSE, $events);
        // Niedrige Priorität (-1024): läuft nach allen Standard-Subscribern, damit Tags nicht überschrieben werden.
        self::assertSame(['onResponse', -1024], $events[KernelEvents::RESPONSE]);
    }

    public function testOnResponseAddsTagsWhenAttributePresent(): void
    {
        $request = $this->createRequestWithTags(['rc-dynamic-price-global', 'rc-dynamic-price-category-abc']);
        $response = new Response();
        $event = $this->createResponseEvent($request, $response);

        (new StorefrontResponseSubscriber())->onResponse($event);

        $header = (string) $response->headers->get(self::CACHE_TAGS_HEADER);
        self::assertStringContainsString('rc-dynamic-price-global', $header);
        self::assertStringContainsString('rc-dynamic-price-category-abc', $header);
    }

    /**
     * Was: Der geschriebene Header wird exakt so gelesen, wie Shopware ihn liest.
     * Warum: REGRESSION. Der Subscriber schrieb `implode(',', $tags)`. Beide
     *        Leser im Core — `CacheStore::write()` und `ReverseProxyCache::write()` —
     *        rufen aber `json_decode($header, true, 512, JSON_THROW_ON_ERROR)`.
     *        Jede Produktseite mit Dynamic-Price-Tags starb daher mit einer
     *        `JsonException` und HTTP 500, sobald der HTTP-Cache die Antwort
     *        ablegen wollte (`SHOPWARE_HTTP_CACHE_ENABLED=1`).
     * Erwartet: `json_decode` mit `JSON_THROW_ON_ERROR` liefert ein Array mit
     *           genau den gesetzten Tags — kein Throw.
     */
    public function testHeaderIsValidJsonAsShopwareExpects(): void
    {
        $request = $this->createRequestWithTags(['rc-dynamic-price-global', 'rc-dynamic-price-category-abc']);
        $response = new Response();
        $event = $this->createResponseEvent($request, $response);

        (new StorefrontResponseSubscriber())->onResponse($event);

        $header = (string) $response->headers->get(self::CACHE_TAGS_HEADER);

        $decoded = json_decode($header, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame(['rc-dynamic-price-global', 'rc-dynamic-price-category-abc'], $decoded);
    }

    public function testOnResponseDoesNothingWhenAttributeMissing(): void
    {
        $request = Request::create('/');
        $response = new Response();
        $event = $this->createResponseEvent($request, $response);

        (new StorefrontResponseSubscriber())->onResponse($event);

        self::assertFalse($response->headers->has(self::CACHE_TAGS_HEADER));
    }

    public function testOnResponseMergesWithExistingTagsWithoutDuplicates(): void
    {
        $request = $this->createRequestWithTags(['rc-dynamic-price-global', 'rc-dynamic-price-category-foo']);
        $response = new Response();
        $response->headers->set(
            self::CACHE_TAGS_HEADER,
            json_encode(['sw-product-bar', 'rc-dynamic-price-global'], \JSON_THROW_ON_ERROR),
        );
        $event = $this->createResponseEvent($request, $response);

        (new StorefrontResponseSubscriber())->onResponse($event);

        $tags = $this->parseHeader((string) $response->headers->get(self::CACHE_TAGS_HEADER));

        self::assertContains('sw-product-bar', $tags);
        self::assertContains('rc-dynamic-price-global', $tags);
        self::assertContains('rc-dynamic-price-category-foo', $tags);
        // Duplikat: rc-dynamic-price-global darf nur einmal vorkommen.
        self::assertSame(1, $this->countOccurrences($tags, 'rc-dynamic-price-global'));
    }

    public function testOnResponseIgnoresNonStringAndEmptyTags(): void
    {
        $request = Request::create('/');
        $request->attributes->set(
            ProductPageSubscriber::getCacheTagsRequestAttribute(),
            ['rc-dynamic-price-global', '', 42, null, 'rc-dynamic-price-category-x'],
        );
        $response = new Response();
        $event = $this->createResponseEvent($request, $response);

        (new StorefrontResponseSubscriber())->onResponse($event);

        $tags = $this->parseHeader((string) $response->headers->get(self::CACHE_TAGS_HEADER));
        self::assertSame(['rc-dynamic-price-global', 'rc-dynamic-price-category-x'], $tags);
    }

    public function testOnResponseIgnoresNonArrayAttribute(): void
    {
        $request = Request::create('/');
        $request->attributes->set(ProductPageSubscriber::getCacheTagsRequestAttribute(), 'not-an-array');
        $response = new Response();
        $event = $this->createResponseEvent($request, $response);

        (new StorefrontResponseSubscriber())->onResponse($event);

        self::assertFalse($response->headers->has(self::CACHE_TAGS_HEADER));
    }

    public function testOnStorefrontRenderReinjectsTagsIntoRequest(): void
    {
        $request = $this->createRequestWithTags(['rc-dynamic-price-global']);
        $event = $this->createStorefrontRenderEvent($request);

        (new StorefrontResponseSubscriber())->onStorefrontRender($event);

        self::assertSame(
            ['rc-dynamic-price-global'],
            $request->attributes->get(ProductPageSubscriber::getCacheTagsRequestAttribute()),
        );
    }

    public function testOnStorefrontRenderDoesNothingWhenNoTags(): void
    {
        $request = Request::create('/');
        $event = $this->createStorefrontRenderEvent($request);

        (new StorefrontResponseSubscriber())->onStorefrontRender($event);

        self::assertSame([], $request->attributes->get(ProductPageSubscriber::getCacheTagsRequestAttribute(), []));
    }

    /**
     * @param list<string> $tags
     */
    private function createRequestWithTags(array $tags): Request
    {
        $request = Request::create('/');
        $request->attributes->set(ProductPageSubscriber::getCacheTagsRequestAttribute(), $tags);

        return $request;
    }

    private function createResponseEvent(Request $request, Response $response): ResponseEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }

    private function createStorefrontRenderEvent(Request $request): StorefrontRenderEvent
    {
        $event = $this->createMock(StorefrontRenderEvent::class);
        $event->method('getRequest')->willReturn($request);

        return $event;
    }

    /**
     * @return list<string>
     */
    private function parseHeader(string $value): array
    {
        // Exakt so liest Shopware den Header: CacheStore::write() und
        // ReverseProxyCache::write() rufen json_decode(..., JSON_THROW_ON_ERROR).
        $decoded = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'sw-cache-tags muss ein JSON-Array sein');

        return array_values(array_filter($decoded, 'is_string'));
    }

    /**
     * @param list<string> $tags
     */
    private function countOccurrences(array $tags, string $needle): int
    {
        return count(array_filter($tags, static fn (string $t): bool => $t === $needle));
    }
}
