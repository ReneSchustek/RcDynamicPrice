<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelper;
use Ruhrcoder\RcDynamicPrice\Subscriber\LineItemSubscriber;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannel;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LineItemSubscriberTest extends TestCase
{
    private RequestStack $requestStack;
    private SystemConfigService $systemConfig;
    private MeterProductHelper $meterProductHelper;
    private LineItemSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->meterProductHelper = $this->createMock(MeterProductHelper::class);
        $this->subscriber = new LineItemSubscriber(
            $this->requestStack,
            $this->systemConfig,
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

    public function testSkipsWhenProductIsNotMeterProduct(): void
    {
        $request = new Request();
        $request->request = new InputBag(['mmLength' => 500]);

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $this->meterProductHelper->method('isMeterProduct')->willReturn(false);

        $lineItem = $this->createMock(LineItem::class);
        $lineItem->method('getReferencedId')->willReturn('product-id');
        $lineItem->expects($this->never())->method('setPayloadValue');

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);
        $event->method('getLineItem')->willReturn($lineItem);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenMmLengthBelowMinimum(): void
    {
        $request = new Request();
        $request->request = new InputBag(['mmLength' => 5]);

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $salesChannel = $this->createMock(SalesChannel::class);
        $salesChannel->method('getId')->willReturn('sc-id');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannel')->willReturn($salesChannel);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $this->meterProductHelper->method('isMeterProduct')->willReturn(true);

        $this->systemConfig->method('getInt')->willReturnCallback(
            fn(string $key) => match(true) {
                str_ends_with($key, 'minLength') => 100,
                str_ends_with($key, 'maxLength') => 10000,
                default => 0,
            }
        );

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
        $request->request = new InputBag(['mmLength' => 500]);

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $salesChannel = $this->createMock(SalesChannel::class);
        $salesChannel->method('getId')->willReturn('sc-id');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannel')->willReturn($salesChannel);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $this->meterProductHelper->method('isMeterProduct')->willReturn(true);

        $this->systemConfig->method('getInt')->willReturnCallback(
            fn(string $key) => match(true) {
                str_ends_with($key, 'minLength') => 1,
                str_ends_with($key, 'maxLength') => 10000,
                default => 0,
            }
        );

        $lineItem = $this->createMock(LineItem::class);
        $lineItem->method('getReferencedId')->willReturn('product-id');
        $lineItem->expects($this->exactly(2))->method('setPayloadValue')
            ->willReturnCallback(function (string $key, mixed $value): void {
                $this->assertContains($key, [DynamicPriceConstants::PAYLOAD_LENGTH_MM, DynamicPriceConstants::PAYLOAD_METER_ACTIVE]);
            });

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);
        $event->method('getLineItem')->willReturn($lineItem);

        $this->subscriber->onBeforeLineItemAdded($event);
    }
}
