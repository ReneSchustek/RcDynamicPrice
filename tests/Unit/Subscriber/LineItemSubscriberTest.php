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
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
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
        $this->setCurrentRequest(['mmLength' => 0]);

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->expects($this->never())->method('getLineItem');

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenProductNotFound(): void
    {
        $this->setCurrentRequest(['mmLength' => 500]);
        $this->meterProductHelper->method('loadProduct')->willReturn(null);

        $lineItem = $this->createMock(LineItem::class);
        $lineItem->method('getReferencedId')->willReturn('product-id');
        $lineItem->expects($this->never())->method('setPayloadValue');

        $event = $this->createEvent($lineItem);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenProductIsNotMeterProduct(): void
    {
        $this->setCurrentRequest(['mmLength' => 500]);
        $this->configureMeterProduct(isMeter: false);

        $lineItem = $this->createMock(LineItem::class);
        $lineItem->method('getReferencedId')->willReturn('product-id');
        $lineItem->expects($this->never())->method('setPayloadValue');

        $event = $this->createEvent($lineItem);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenMmLengthBelowProductMinimum(): void
    {
        $this->setCurrentRequest(['mmLength' => 500]);
        $this->configureMeterProduct(isMeter: true, minLength: 1000, maxLength: 6000);

        $lineItem = $this->createMock(LineItem::class);
        $lineItem->method('getReferencedId')->willReturn('product-id');
        $lineItem->expects($this->never())->method('setPayloadValue');

        $event = $this->createEvent($lineItem, 'sc-id');

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenMmLengthAboveProductMaximum(): void
    {
        $this->setCurrentRequest(['mmLength' => 7000]);
        $this->configureMeterProduct(isMeter: true, minLength: 1000, maxLength: 6000);

        $lineItem = $this->createMock(LineItem::class);
        $lineItem->method('getReferencedId')->willReturn('product-id');
        $lineItem->expects($this->never())->method('setPayloadValue');

        $event = $this->createEvent($lineItem, 'sc-id');

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSetsPayloadForValidMeterProduct(): void
    {
        $this->setCurrentRequest(['mmLength' => 2000]);
        $this->configureMeterProduct(isMeter: true, minLength: 1000, maxLength: 6000);

        $lineItem = $this->createMock(LineItem::class);
        $lineItem->method('getReferencedId')->willReturn('product-id');
        $lineItem->method('getId')->willReturn('line-item-id');
        $expectedKeys = [
            DynamicPriceConstants::PAYLOAD_LENGTH_MM,
            DynamicPriceConstants::PAYLOAD_METER_ACTIVE,
            DynamicPriceConstants::PAYLOAD_ROUNDING,
            DynamicPriceConstants::PAYLOAD_MIN_LENGTH,
            DynamicPriceConstants::PAYLOAD_MAX_LENGTH,
        ];
        $lineItem->expects($this->exactly(5))->method('setPayloadValue')
            ->willReturnCallback(function (string $key, mixed $value) use ($lineItem, $expectedKeys): LineItem {
                $this->assertContains($key, $expectedKeys);
                return $lineItem;
            });

        $cart = $this->createMock(Cart::class);
        $cart->method('get')->with('line-item-id')->willReturn($lineItem);

        $event = $this->createEvent($lineItem, 'sc-id', $cart);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    /** Setzt einen Request mit den gegebenen POST-Parametern auf den RequestStack. */
    private function setCurrentRequest(array $postData): void
    {
        $request = new Request();
        $request->request = new InputBag($postData);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
    }

    /** Konfiguriert den MeterProductHelper-Mock für Produkt-Lookups. */
    private function configureMeterProduct(bool $isMeter, int $minLength = 1, int $maxLength = 10000): void
    {
        $product = new ProductEntity();
        $this->meterProductHelper->method('loadProduct')->willReturn($product);
        $this->meterProductHelper->method('isMeterProductEntity')->willReturn($isMeter);
        $this->meterProductHelper->method('getMinLength')->willReturn($minLength);
        $this->meterProductHelper->method('getMaxLength')->willReturn($maxLength);
    }

    /** Erstellt ein BeforeLineItemAddedEvent mit SalesChannelContext-Mock. */
    private function createEvent(
        LineItem $lineItem,
        string $salesChannelId = 'sc-id',
        ?Cart $cart = null,
    ): BeforeLineItemAddedEvent {
        $salesChannel = $this->createMock(SalesChannelEntity::class);
        $salesChannel->method('getId')->willReturn($salesChannelId);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->method('getSalesChannelContext')->willReturn($context);
        $event->method('getLineItem')->willReturn($lineItem);

        if ($cart !== null) {
            $event->method('getCart')->willReturn($cart);
        }

        return $event;
    }
}
