<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelper;
use Ruhrcoder\RcDynamicPrice\Storefront\Struct\RcDynamicPriceConfigStruct;
use Ruhrcoder\RcDynamicPrice\Storefront\Subscriber\ProductPageSubscriber;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannel;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

final class ProductPageSubscriberTest extends TestCase
{
    private SystemConfigService $systemConfig;
    private MeterProductHelper $meterProductHelper;
    private ProductPageSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->meterProductHelper = $this->createMock(MeterProductHelper::class);
        $this->subscriber = new ProductPageSubscriber($this->systemConfig, $this->meterProductHelper);
    }

    public function testGetSubscribedEventsReturnsArray(): void
    {
        $events = ProductPageSubscriber::getSubscribedEvents();
        $this->assertIsArray($events);
        $this->assertArrayHasKey(ProductPageLoadedEvent::class, $events);
    }

    public function testDoesNotAddExtensionWhenProductIsNotMeterProduct(): void
    {
        $this->meterProductHelper->method('isMeterProductEntity')->willReturn(false);

        $page = $this->createMock(ProductPage::class);
        $page->method('getProduct')->willReturn(new ProductEntity());
        $page->expects($this->never())->method('addExtension');

        $this->subscriber->onProductPageLoaded($this->createProductPageEvent($page, 'sc-id'));
    }

    public function testAddsExtensionWithConfigWhenProductIsMeterProduct(): void
    {
        $this->meterProductHelper->method('isMeterProductEntity')->willReturn(true);
        $this->meterProductHelper->method('getMinLength')->willReturn(1);
        $this->meterProductHelper->method('getMaxLength')->willReturn(10000);

        $this->systemConfig->method('getString')->willReturn('');

        $page = $this->createMock(ProductPage::class);
        $page->method('getProduct')->willReturn(new ProductEntity());
        $page->expects($this->once())
            ->method('addExtension')
            ->with('rcDynamicPriceConfig', $this->isInstanceOf(RcDynamicPriceConfigStruct::class));

        $this->subscriber->onProductPageLoaded($this->createProductPageEvent($page, 'sc-id'));
    }

    public function testExtensionContainsProductSpecificValues(): void
    {
        $this->meterProductHelper->method('isMeterProductEntity')->willReturn(true);
        $this->meterProductHelper->method('getMinLength')->willReturn(1000);
        $this->meterProductHelper->method('getMaxLength')->willReturn(6000);

        $capturedStruct = null;

        $page = $this->createMock(ProductPage::class);
        $page->method('getProduct')->willReturn(new ProductEntity());
        $page->method('addExtension')->willReturnCallback(
            function (string $name, mixed $struct) use (&$capturedStruct): void {
                $capturedStruct = $struct;
            }
        );

        $this->systemConfig->method('getString')->willReturn('Bitte Länge eingeben');

        $this->subscriber->onProductPageLoaded($this->createProductPageEvent($page, 'sc-id'));

        $this->assertInstanceOf(RcDynamicPriceConfigStruct::class, $capturedStruct);
        $this->assertSame('Bitte Länge eingeben', $capturedStruct->getHintText());
        $this->assertSame(1000, $capturedStruct->getMinLength());
        $this->assertSame(6000, $capturedStruct->getMaxLength());
    }

    private function createProductPageEvent(ProductPage $page, string $salesChannelId): ProductPageLoadedEvent
    {
        $salesChannel = $this->createMock(SalesChannel::class);
        $salesChannel->method('getId')->willReturn($salesChannelId);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);

        return new ProductPageLoadedEvent($page, $context, new Request());
    }
}
