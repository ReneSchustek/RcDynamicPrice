<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Service\LengthSplitter;
use Ruhrcoder\RcDynamicPrice\Service\LengthSplitterInterface;
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
    private LengthSplitterInterface $lengthSplitter;
    private LineItemSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->meterProductHelper = $this->createMock(MeterProductHelperInterface::class);
        // Reale Splitter-Instanz, damit die End-to-End-Mathematik mitgetestet wird
        $this->lengthSplitter = new LengthSplitter();
        $this->subscriber = new LineItemSubscriber(
            $this->requestStack,
            $this->meterProductHelper,
            $this->lengthSplitter,
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

    public function testSetsPayloadForValidMeterProductWithoutSplit(): void
    {
        $this->setCurrentRequest(['mmLength' => 2000]);
        $this->configureMeterProduct(
            isMeter: true,
            minLength: 1000,
            maxLength: 6000,
            splitMode: null,
            maxPieceLength: 0,
        );

        $lineItem = $this->createMock(LineItem::class);
        $lineItem->method('getReferencedId')->willReturn('product-id');
        $lineItem->method('getId')->willReturn('line-item-id');
        $lineItem->expects($this->exactly(5))->method('setPayloadValue')->willReturnSelf();

        $cart = $this->createMock(Cart::class);
        $cart->method('get')->with('line-item-id')->willReturn($lineItem);
        $cart->expects($this->never())->method('add');

        $event = $this->createEvent($lineItem, 'sc-id', $cart);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSplitsLineItemInEqualModeAndAppendsSiblings(): void
    {
        $this->setCurrentRequest(['mmLength' => 8000]);
        $this->configureMeterProduct(
            isMeter: true,
            minLength: 1,
            maxLength: 10000,
            splitMode: SplitMode::Equal,
            maxPieceLength: 5000,
        );

        $lineItem = new LineItem('primary-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id', 1);

        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $event = $this->createEvent($lineItem, 'sc-id', $cart);

        $this->subscriber->onBeforeLineItemAdded($event);

        // Ein Zusatz-LineItem erwartet (equal: 8000 -> 2x 4000)
        $this->assertCount(2, $cart->getLineItems());

        $primary = $cart->get('primary-id');
        $this->assertNotNull($primary);
        $this->assertSame(4000, $primary->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
        $this->assertTrue($primary->getPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE));

        $sibling = $cart->get('primary-id-piece1');
        $this->assertNotNull($sibling);
        $this->assertSame(4000, $sibling->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
        $this->assertSame('product-id', $sibling->getReferencedId());
        $this->assertSame(1, $sibling->getQuantity());
    }

    public function testSplitsLineItemInMaxRestModeWithRemainderAndMinFallback(): void
    {
        // 6000 mm bei max 5000 und Min 2000 → [5000, 2000] (Rest unter Min angehoben)
        $this->setCurrentRequest(['mmLength' => 6000]);
        $this->configureMeterProduct(
            isMeter: true,
            minLength: 2000,
            maxLength: 10000,
            splitMode: SplitMode::MaxRest,
            maxPieceLength: 5000,
        );

        $lineItem = new LineItem('primary-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id', 1);

        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $event = $this->createEvent($lineItem, 'sc-id', $cart);

        $this->subscriber->onBeforeLineItemAdded($event);

        $this->assertCount(2, $cart->getLineItems());
        $this->assertSame(5000, $cart->get('primary-id')?->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
        $this->assertSame(2000, $cart->get('primary-id-piece1')?->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
    }

    public function testHintModeDoesNotSplit(): void
    {
        $this->setCurrentRequest(['mmLength' => 4000]);
        $this->configureMeterProduct(
            isMeter: true,
            minLength: 1,
            maxLength: 10000,
            splitMode: SplitMode::Hint,
            maxPieceLength: 5000,
        );

        $lineItem = new LineItem('primary-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id', 1);

        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $event = $this->createEvent($lineItem, 'sc-id', $cart);

        $this->subscriber->onBeforeLineItemAdded($event);

        $this->assertCount(1, $cart->getLineItems());
        $this->assertSame(4000, $cart->get('primary-id')?->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
    }

    public function testDisablesAutoSplitWhenForeignIdControllerMarkerInRequest(): void
    {
        // TMMS-Marker im Request -> Auto-Split muss auf Hint-Verhalten fallen, sonst ginge der TMMS-Payload
        // auf den Sibling-Positionen verloren (siehe plugin-interaction.md).
        $this->setCurrentRequest(['mmLength' => 8000, 'rcTmmsActive' => '1']);
        $this->configureMeterProduct(
            isMeter: true,
            minLength: 1,
            maxLength: 10000,
            splitMode: SplitMode::Equal,
            maxPieceLength: 5000,
        );

        $lineItem = new LineItem('primary-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id', 1);
        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $event = $this->createEvent($lineItem, 'sc-id', $cart);

        $this->subscriber->onBeforeLineItemAdded($event);

        // Nur eine LineItem, kein Sibling, Payload enthaelt die volle eingegebene Laenge
        $this->assertCount(1, $cart->getLineItems());
        $this->assertSame(8000, $cart->get('primary-id')?->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
    }

    public function testUsesCartItemIdAsSiblingBaseWhenMergedItemReplacesEventLineItem(): void
    {
        // Beim Merging reicht Shopware eine andere LineItem-Instanz durch. Die Sibling-IDs
        // muessen sich am Cart-Item orientieren, nicht am Event-Ursprung.
        $this->setCurrentRequest(['mmLength' => 8000]);
        $this->configureMeterProduct(
            isMeter: true,
            minLength: 1,
            maxLength: 10000,
            splitMode: SplitMode::Equal,
            maxPieceLength: 5000,
        );

        $existingCartItem = new LineItem('merged-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id', 2);
        $incomingLineItem = new LineItem('merged-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id', 1);

        $cart = new Cart('test-token');
        $cart->add($existingCartItem);

        $event = $this->createEvent($incomingLineItem, 'sc-id', $cart);

        $this->subscriber->onBeforeLineItemAdded($event);

        $this->assertNotNull($cart->get('merged-id-piece1'));
        $this->assertSame(
            'product-id',
            $cart->get('merged-id-piece1')?->getReferencedId()
        );
    }

    /** Setzt einen Request mit den gegebenen POST-Parametern auf den RequestStack. */
    private function setCurrentRequest(array $postData): void
    {
        $request = new Request();
        $request->request = new InputBag($postData);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
    }

    /** Konfiguriert den MeterProductHelper-Mock fuer Produkt-Lookups. */
    private function configureMeterProduct(
        bool $isMeter,
        int $minLength = 1,
        int $maxLength = 10000,
        ?SplitMode $splitMode = null,
        int $maxPieceLength = 0,
    ): void {
        $product = new ProductEntity();
        $this->meterProductHelper->method('loadProduct')->willReturn($product);
        $this->meterProductHelper->method('isMeterProductEntity')->willReturn($isMeter);
        $this->meterProductHelper->method('getMinLength')->willReturn($minLength);
        $this->meterProductHelper->method('getMaxLength')->willReturn($maxLength);
        $this->meterProductHelper->method('getRoundingMode')->willReturn('none');
        $this->meterProductHelper->method('getSplitMode')->willReturn($splitMode);
        $this->meterProductHelper->method('getMaxPieceLength')->willReturn($maxPieceLength);
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
