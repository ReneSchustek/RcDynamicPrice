<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Service\ConfigScope;
use Ruhrcoder\RcDynamicPrice\Service\MeterConfigResolverInterface;
use Ruhrcoder\RcDynamicPrice\Service\ResolvedMeterConfig;
use Ruhrcoder\RcDynamicPrice\Storefront\Struct\RcDynamicPriceConfigStruct;
use Ruhrcoder\RcDynamicPrice\Storefront\Subscriber\ProductPageSubscriber;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ProductPageSubscriberTest extends TestCase
{
    private SystemConfigService $systemConfig;
    private MeterConfigResolverInterface $configResolver;
    private RequestStack $requestStack;
    private ProductPageSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->configResolver = $this->createMock(MeterConfigResolverInterface::class);
        $this->requestStack = new RequestStack();
        $this->subscriber = new ProductPageSubscriber(
            $this->systemConfig,
            $this->configResolver,
            $this->requestStack,
        );
    }

    public function testGetSubscribedEventsReturnsArray(): void
    {
        $events = ProductPageSubscriber::getSubscribedEvents();
        $this->assertIsArray($events);
        $this->assertArrayHasKey(ProductPageLoadedEvent::class, $events);
    }

    public function testDoesNotAddExtensionWhenResolverReportsInactive(): void
    {
        $this->configResolver
            ->method('resolveForProduct')
            ->willReturn(ResolvedMeterConfig::disabled(ConfigScope::Default));

        $page = $this->createMock(ProductPage::class);
        $page->method('getProduct')->willReturn(new SalesChannelProductEntity());
        $page->expects($this->never())->method('addExtension');

        $this->subscriber->onProductPageLoaded($this->createProductPageEvent($page, 'sc-id'));
    }

    public function testAddsExtensionWhenResolverReportsActive(): void
    {
        $this->configResolver
            ->method('resolveForProduct')
            ->willReturn($this->activeResolved(minLength: 1000, maxLength: 6000));

        $this->systemConfig->method('getString')->willReturn('Bitte Laenge eingeben');

        $captured = null;

        $page = $this->createMock(ProductPage::class);
        $page->method('getProduct')->willReturn(new SalesChannelProductEntity());
        $page->method('addExtension')->willReturnCallback(
            function (string $name, mixed $struct) use (&$captured): void {
                $captured = $struct;
            },
        );

        $this->subscriber->onProductPageLoaded($this->createProductPageEvent($page, 'sc-id'));

        $this->assertInstanceOf(RcDynamicPriceConfigStruct::class, $captured);
        $this->assertSame('Bitte Laenge eingeben', $captured->getHintText());
        $this->assertSame(1000, $captured->getMinLength());
        $this->assertSame(6000, $captured->getMaxLength());
    }

    public function testExtensionContainsSplitConfiguration(): void
    {
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(
            splitMode: SplitMode::MaxRest,
            maxPieceLength: 5000,
            splitHintTemplate: 'Template {length}',
        ));

        $this->systemConfig->method('getString')->willReturn('');

        $captured = null;
        $page = $this->createMock(ProductPage::class);
        $page->method('getProduct')->willReturn(new SalesChannelProductEntity());
        $page->method('addExtension')->willReturnCallback(
            function (string $name, mixed $struct) use (&$captured): void {
                $captured = $struct;
            },
        );

        $this->subscriber->onProductPageLoaded($this->createProductPageEvent($page, 'sc-id'));

        $this->assertInstanceOf(RcDynamicPriceConfigStruct::class, $captured);
        $this->assertSame('max_rest', $captured->getSplitMode());
        $this->assertSame(5000, $captured->getMaxPieceLength());
        $this->assertSame('Template {length}', $captured->getSplitHintTemplate());
    }

    public function testAttachesCacheTagsToRequestAttributes(): void
    {
        $request = new Request();
        $this->requestStack->push($request);

        $this->configResolver
            ->method('resolveForProduct')
            ->willReturn($this->activeResolved(cacheTags: ['rc-dynamic-price-global', 'rc-dynamic-price-category-root']));

        $this->systemConfig->method('getString')->willReturn('');

        $page = $this->createMock(ProductPage::class);
        $page->method('getProduct')->willReturn(new SalesChannelProductEntity());

        $this->subscriber->onProductPageLoaded($this->createProductPageEvent($page, 'sc-id'));

        $attribute = $request->attributes->get(ProductPageSubscriber::getCacheTagsRequestAttribute());
        $this->assertIsArray($attribute);
        $this->assertContains('rc-dynamic-price-global', $attribute);
        $this->assertContains('rc-dynamic-price-category-root', $attribute);
    }

    /** @param list<string> $cacheTags */
    private function activeResolved(
        int $minLength = 1,
        int $maxLength = 10000,
        ?SplitMode $splitMode = null,
        int $maxPieceLength = 0,
        string $splitHintTemplate = '',
        array $cacheTags = [],
    ): ResolvedMeterConfig {
        return new ResolvedMeterConfig(
            active: true,
            activeScope: ConfigScope::Product,
            minLength: $minLength,
            minLengthScope: ConfigScope::Product,
            maxLength: $maxLength,
            maxLengthScope: ConfigScope::Product,
            roundingMode: 'none',
            roundingModeScope: ConfigScope::Default,
            splitMode: $splitMode,
            splitModeScope: ConfigScope::Default,
            maxPieceLength: $maxPieceLength,
            maxPieceLengthScope: ConfigScope::Default,
            splitHintTemplate: $splitHintTemplate,
            splitHintTemplateScope: ConfigScope::Default,
            cacheTags: $cacheTags,
        );
    }

    private function createProductPageEvent(ProductPage $page, string $salesChannelId): ProductPageLoadedEvent
    {
        $salesChannel = $this->createMock(SalesChannelEntity::class);
        $salesChannel->method('getId')->willReturn($salesChannelId);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return new ProductPageLoadedEvent($page, $context, new Request());
    }
}
