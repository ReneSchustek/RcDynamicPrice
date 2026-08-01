<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Integration\Subscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\CartItemSplitAssembler;
use Ruhrcoder\RcDynamicPrice\Service\CategoryChainLoaderInterface;
use Ruhrcoder\RcDynamicPrice\Service\LengthSplitter;
use Ruhrcoder\RcDynamicPrice\Service\MeterConfigResolver;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Ruhrcoder\RcDynamicPrice\Subscriber\LineItemSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Integrationstest: der komplette Add-to-Cart-Pfad mit echtem Resolver, echtem Assembler
 * und echtem Splitter. Nur DAL-/Product-Helper-Grenzen sind gestubt. Fängt Regressions
 * in der Wiring-Logik, die reine Subscriber-Unit-Tests mit gemockten Services übersehen.
 */
final class LineItemSubscriberIntegrationTest extends TestCase
{
    private const PRODUCT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SALES_CHANNEL_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testAddToCartResolvesConfigAndInitializesPayload(): void
    {
        $request = new Request();
        $request->request->set('mmLength', '1500');

        $cart = new Cart('token');
        $lineItem = new LineItem('line-item-id', LineItem::PRODUCT_LINE_ITEM_TYPE, self::PRODUCT_ID);
        $cart->add($lineItem);

        $this->buildSubscriber(
            productCustomFields: [DynamicPriceConstants::FIELD_METER_ACTIVE => 'on'],
            splitMode: null,
            request: $request,
        )->onBeforeLineItemAdded(
            $this->event($cart, $lineItem)
        );

        self::assertSame(1500, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
        self::assertTrue($lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE));
        self::assertSame(1, $cart->getLineItems()->count(), 'Kein Split bei Standard-Länge');
    }

    public function testAutoSplitAddsSiblingLineItemsViaRealSplitter(): void
    {
        $request = new Request();
        $request->request->set('mmLength', '8000');

        $cart = new Cart('token');
        $lineItem = new LineItem('primary-id', LineItem::PRODUCT_LINE_ITEM_TYPE, self::PRODUCT_ID);
        $cart->add($lineItem);

        $this->buildSubscriber(
            productCustomFields: [
                DynamicPriceConstants::FIELD_METER_ACTIVE => 'on',
                DynamicPriceConstants::FIELD_SPLIT_MODE => 'max_rest',
                DynamicPriceConstants::FIELD_MAX_PIECE_LENGTH => 5000,
                DynamicPriceConstants::FIELD_MAX_LENGTH => 10000,
            ],
            splitMode: 'max_rest',
            request: $request,
        )->onBeforeLineItemAdded(
            $this->event($cart, $lineItem)
        );

        self::assertSame(1, $cart->getLineItems()->count(), 'Ein Zuschnitt-Auftrag bleibt eine Position');
        self::assertSame(8000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
        self::assertSame(
            [5000, 3000],
            $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES),
            '8000 bei maxPiece=5000 -> Fertigungsfolge 5000 + 3000',
        );
    }

    public function testSplitPiecesAreNotSeparatelyRemovableLineItems(): void
    {
        $request = new Request();
        $request->request->set('mmLength', '15000');

        $cart = new Cart('token');
        $lineItem = new LineItem('primary-id', LineItem::PRODUCT_LINE_ITEM_TYPE, self::PRODUCT_ID);
        $cart->add($lineItem);

        $this->buildSubscriber(
            productCustomFields: [
                DynamicPriceConstants::FIELD_METER_ACTIVE => 'on',
                DynamicPriceConstants::FIELD_SPLIT_MODE => 'equal',
                DynamicPriceConstants::FIELD_MAX_PIECE_LENGTH => 5000,
                DynamicPriceConstants::FIELD_MAX_LENGTH => 20000,
            ],
            splitMode: 'equal',
            request: $request,
        )->onBeforeLineItemAdded(
            $this->event($cart, $lineItem)
        );

        // 15000 bei maxPiece=5000 -> 3 gleichgroße Teilstücke, aber nur eine Position.
        // Wären die Teilstücke eigene Positionen, könnte der Kunde eines davon einzeln entfernen
        // und bestellte kommentarlos 10.000 mm statt der eingegebenen 15.000 mm.
        self::assertSame(1, $cart->getLineItems()->count());
        self::assertSame(
            [5000, 5000, 5000],
            $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES),
        );

        foreach (['primary-id-piece1', 'primary-id-piece2'] as $siblingId) {
            self::assertNull(
                $cart->get($siblingId),
                \sprintf('Teilstück %s darf keine eigene, einzeln löschbare Position sein', $siblingId),
            );
        }
    }

    /**
     * Eine Länge jenseits der Grenzen wird nicht bepreist — die Position wird aber als
     * Meter-Position markiert, damit der Processor sie ablehnt und die Bestellung blockiert.
     * Ohne Markierung überspringt er sie und der Zuschnitt-Artikel ginge zum Stückpreis durch.
     */
    public function testLengthOutsideResolvedBoundsIsNotPricedButBlocks(): void
    {
        $request = new Request();
        $request->request->set('mmLength', '20000');

        $cart = new Cart('token');
        $lineItem = new LineItem('line-item-id', LineItem::PRODUCT_LINE_ITEM_TYPE, self::PRODUCT_ID);
        $cart->add($lineItem);

        $this->buildSubscriber(
            productCustomFields: [
                DynamicPriceConstants::FIELD_METER_ACTIVE => 'on',
                DynamicPriceConstants::FIELD_MAX_LENGTH => 10000,
            ],
            splitMode: null,
            request: $request,
        )->onBeforeLineItemAdded(
            $this->event($cart, $lineItem)
        );

        self::assertTrue($lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE));
        self::assertSame(10000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH));
        self::assertSame(20000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
        self::assertNull(
            $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES),
            'Eine unzulässige Länge darf nicht in eine Schnittfolge übersetzt werden.',
        );
    }

    /**
     * @param array<string, mixed> $productCustomFields
     */
    private function buildSubscriber(
        array $productCustomFields,
        ?string $splitMode,
        Request $request,
    ): LineItemSubscriber {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $product = new ProductEntity();
        $product->setId(self::PRODUCT_ID);
        $product->setCustomFields($productCustomFields);

        $helper = $this->createMock(MeterProductHelperInterface::class);
        $helper->method('loadProduct')->willReturn($product);
        $helper->method('roundUp')->willReturnArgument(0);

        $categoryChainLoader = $this->createMock(CategoryChainLoaderInterface::class);
        $categoryChainLoader->method('loadChain')->willReturn([]);

        $systemConfig = new StaticSystemConfigService([
            DynamicPriceConstants::CONFIG_APPLY_TO_ALL_PRODUCTS => false,
            DynamicPriceConstants::CONFIG_MIN_LENGTH => 1,
            DynamicPriceConstants::CONFIG_MAX_LENGTH => 10000,
            DynamicPriceConstants::CONFIG_SPLIT_MODE => $splitMode ?? '',
            DynamicPriceConstants::CONFIG_MAX_PIECE_LENGTH => 0,
            DynamicPriceConstants::CONFIG_SPLIT_HINT_TEMPLATE => '',
        ]);

        $resolver = new MeterConfigResolver($categoryChainLoader, $systemConfig);
        $assembler = new CartItemSplitAssembler(new LengthSplitter(), new NullLogger());

        return new LineItemSubscriber(
            $requestStack,
            $helper,
            $resolver,
            $assembler,
            new NullLogger(),
        );
    }

    private function event(Cart $cart, LineItem $lineItem): BeforeLineItemAddedEvent
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId(self::SALES_CHANNEL_ID);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $context->method('getSalesChannel')->willReturn($salesChannel);

        return new BeforeLineItemAddedEvent($lineItem, $cart, $context);
    }
}
