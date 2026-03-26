<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Ruhrcoder\RcDynamicPrice\Subscriber\LineItemSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LineItemSubscriberTest extends TestCase
{
    private RequestStack $requestStack;
    private MeterProductHelperInterface $meterProductHelper;
    private LineItemSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->meterProductHelper = $this->createMock(MeterProductHelperInterface::class);
        $this->subscriber = new LineItemSubscriber(
            $this->requestStack,
            $this->meterProductHelper,
        );
    }

    public function testGetSubscribedEventsReturnsArray(): void
    {
        $events = LineItemSubscriber::getSubscribedEvents();
        $this->assertIsArray($events);
        $this->assertArrayHasKey(BeforeLineItemAddedEvent::class, $events);
    }

    public function testSkipsWhenNoRequest(): void
    {
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->expects($this->never())->method('getLineItem');

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenMmLengthIsZero(): void
    {
        $request = new Request();
        $request->request = new InputBag(['mmLength' => 0]);

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->expects($this->never())->method('getLineItem');

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenProductNotFound(): void
    {
        $request = new Request();
        $request->request = new InputBag(['mmLength' => 500]);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $this->meterProductHelper->method('loadProduct')->willReturn(null);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $lineItem = $this->createMock(LineItem::class);
        $lineItem->method('getReferencedId')->willReturn('product-id');
        $lineItem->expects($this->never())->method('setPayloadValue');

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);
        $event->method('getLineItem')->willReturn($lineItem);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenProductIsNotMeterProduct(): void
    {
        $request = new Request();
        $request->request = new InputBag(['mmLength' => 500]);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $product = new ProductEntity();
        $this->meterProductHelper->method('loadProduct')->willReturn($product);
        $this->meterProductHelper->method('isMeterProductEntity')->willReturn(false);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $lineItem = $this->createMock(LineItem::class);
        $lineItem->method('getReferencedId')->willReturn('product-id');
        $lineItem->expects($this->never())->method('setPayloadValue');

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);
        $event->method('getLineItem')->willReturn($lineItem);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenMmLengthBelowProductMinimum(): void
    {
        $request = new Request();
        $request->request = new InputBag(['mmLength' => 500]);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $product = new ProductEntity();
        $this->meterProductHelper->method('loadProduct')->willReturn($product);
        $this->meterProductHelper->method('isMeterProductEntity')->willReturn(true);
        $this->meterProductHelper->method('getMinLength')->willReturn(1000);
        $this->meterProductHelper->method('getMaxLength')->willReturn(6000);

        $salesChannel = $this->createMock(SalesChannelEntity::class);
        $salesChannel->method('getId')->willReturn('sc-id');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannel')->willReturn($salesChannel);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $lineItem = $this->createMock(LineItem::class);
        $lineItem->method('getReferencedId')->willReturn('product-id');
        $lineItem->expects($this->never())->method('setPayloadValue');

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);
        $event->method('getLineItem')->willReturn($lineItem);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenMmLengthAboveProductMaximum(): void
    {
        $request = new Request();
        $request->request = new InputBag(['mmLength' => 7000]);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $product = new ProductEntity();
        $this->meterProductHelper->method('loadProduct')->willReturn($product);
        $this->meterProductHelper->method('isMeterProductEntity')->willReturn(true);
        $this->meterProductHelper->method('getMinLength')->willReturn(1000);
        $this->meterProductHelper->method('getMaxLength')->willReturn(6000);

        $salesChannel = $this->createMock(SalesChannelEntity::class);
        $salesChannel->method('getId')->willReturn('sc-id');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannel')->willReturn($salesChannel);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $lineItem = $this->createMock(LineItem::class);
        $lineItem->method('getReferencedId')->willReturn('product-id');
        $lineItem->expects($this->never())->method('setPayloadValue');

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);
        $event->method('getLineItem')->willReturn($lineItem);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSetsPayloadForValidMeterProduct(): void
    {
        $request = new Request();
        $request->request = new InputBag(['mmLength' => 2000]);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $product = new ProductEntity();
        $this->meterProductHelper->method('loadProduct')->willReturn($product);
        $this->meterProductHelper->method('isMeterProductEntity')->willReturn(true);
        $this->meterProductHelper->method('getMinLength')->willReturn(1000);
        $this->meterProductHelper->method('getMaxLength')->willReturn(6000);

        $salesChannel = $this->createMock(SalesChannelEntity::class);
        $salesChannel->method('getId')->willReturn('sc-id');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannel')->willReturn($salesChannel);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $lineItem = $this->createMock(LineItem::class);
        $lineItem->method('getReferencedId')->willReturn('product-id');
        $lineItem->method('getId')->willReturn('line-item-id');
        $expectedKeys = [
            DynamicPriceConstants::PAYLOAD_LENGTH_MM,
            DynamicPriceConstants::PAYLOAD_METER_ACTIVE,
            DynamicPriceConstants::PAYLOAD_ROUND_UP,
            DynamicPriceConstants::PAYLOAD_MIN_LENGTH,
            DynamicPriceConstants::PAYLOAD_MAX_LENGTH,
        ];
        $lineItem->expects($this->exactly(5))->method('setPayloadValue')
            ->willReturnCallback(function (string $key, mixed $value) use ($lineItem, $expectedKeys): LineItem {
                $this->assertContains($key, $expectedKeys);
                return $lineItem;
            });

        // Cart gibt dasselbe Item zurück — simuliert den Normalfall
        $cart = $this->createMock(Cart::class);
        $cart->method('get')->with('line-item-id')->willReturn($lineItem);

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);
        $event->method('getLineItem')->willReturn($lineItem);
        $event->method('getCart')->willReturn($cart);

        $this->subscriber->onBeforeLineItemAdded($event);
    }
}
