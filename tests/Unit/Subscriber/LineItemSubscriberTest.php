<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Service\CartItemSplitAssemblerInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterSplittingConfig;
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
    private CartItemSplitAssemblerInterface $assembler;
    private LineItemSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->meterProductHelper = $this->createMock(MeterProductHelperInterface::class);
        $this->assembler = $this->createMock(CartItemSplitAssemblerInterface::class);
        $this->subscriber = new LineItemSubscriber(
            $this->requestStack,
            $this->meterProductHelper,
            $this->assembler,
            new NullLogger(),
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
        $this->assembler->expects($this->never())->method('assemble');

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->expects($this->never())->method('getLineItem');

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenMmLengthMissing(): void
    {
        $this->setCurrentRequest([]);
        $this->assembler->expects($this->never())->method('assemble');

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->expects($this->never())->method('getLineItem');

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenMmLengthNonNumeric(): void
    {
        $this->setCurrentRequest(['mmLength' => '5000abc']);
        $this->assembler->expects($this->never())->method('assemble');

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->expects($this->never())->method('getLineItem');

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenMmLengthIsDecimal(): void
    {
        $this->setCurrentRequest(['mmLength' => '500.5']);
        $this->assembler->expects($this->never())->method('assemble');

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->expects($this->never())->method('getLineItem');

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenMmLengthIsZero(): void
    {
        $this->setCurrentRequest(['mmLength' => '0']);
        $this->assembler->expects($this->never())->method('assemble');

        $event = $this->createMock(BeforeLineItemAddedEvent::class);
        $event->expects($this->never())->method('getLineItem');

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenReferencedIdIsNull(): void
    {
        $this->setCurrentRequest(['mmLength' => '5000']);
        $this->assembler->expects($this->never())->method('assemble');

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE);
        // referencedId absichtlich nicht gesetzt
        $event = $this->createEvent($lineItem);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenProductNotFound(): void
    {
        $this->setCurrentRequest(['mmLength' => '500']);
        $this->meterProductHelper->method('loadProduct')->willReturn(null);
        $this->assembler->expects($this->never())->method('assemble');

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $event = $this->createEvent($lineItem);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenProductIsNotMeterProduct(): void
    {
        $this->setCurrentRequest(['mmLength' => '500']);
        $this->configureMeterProduct(isMeter: false);
        $this->assembler->expects($this->never())->method('assemble');

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $event = $this->createEvent($lineItem);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenMmLengthBelowProductMinimum(): void
    {
        $this->setCurrentRequest(['mmLength' => '500']);
        $this->configureMeterProduct(isMeter: true, minLength: 1000, maxLength: 6000);
        $this->assembler->expects($this->never())->method('assemble');

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $event = $this->createEvent($lineItem, 'sc-id');

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenMmLengthAboveProductMaximum(): void
    {
        $this->setCurrentRequest(['mmLength' => '7000']);
        $this->configureMeterProduct(isMeter: true, minLength: 1000, maxLength: 6000);
        $this->assembler->expects($this->never())->method('assemble');

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $event = $this->createEvent($lineItem, 'sc-id');

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testDelegatesToAssemblerWithConfigWhenInputIsValid(): void
    {
        $this->setCurrentRequest(['mmLength' => '2000']);
        $this->configureMeterProduct(
            isMeter: true,
            minLength: 1000,
            maxLength: 6000,
            splitMode: SplitMode::Equal,
            maxPieceLength: 5000,
        );

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $event = $this->createEvent($lineItem, 'sc-id', $cart);

        $this->assembler
            ->expects($this->once())
            ->method('assemble')
            ->with(
                $cart,
                $lineItem,
                2000,
                $this->callback(static function (MeterSplittingConfig $config): bool {
                    return $config->productId === 'product-id'
                        && $config->minLength === 1000
                        && $config->maxLength === 6000
                        && $config->maxPieceLength === 5000
                        && $config->splitMode === SplitMode::Equal;
                }),
            );

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testReducesSplitModeToHintWhenForeignIdControllerMarkerIsPresent(): void
    {
        // rcTmmsActive im Request -> Auto-Split muss auf Hint fallen, damit TMMS-Felder nicht verloren gehen
        $this->setCurrentRequest(['mmLength' => '8000', 'rcTmmsActive' => '1']);
        $this->configureMeterProduct(
            isMeter: true,
            minLength: 1,
            maxLength: 10000,
            splitMode: SplitMode::Equal,
            maxPieceLength: 5000,
        );

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');

        $this->assembler
            ->expects($this->once())
            ->method('assemble')
            ->with(
                $this->anything(),
                $this->anything(),
                8000,
                $this->callback(static fn (MeterSplittingConfig $c): bool => $c->splitMode === SplitMode::Hint),
            );

        $this->subscriber->onBeforeLineItemAdded($this->createEvent($lineItem, 'sc-id'));
    }

    /** @param array<string, mixed> $postData */
    private function setCurrentRequest(array $postData): void
    {
        $request = new Request();
        $request->request = new InputBag($postData);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
    }

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
