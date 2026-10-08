<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Subscriber;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Service\CartItemSplitAssemblerInterface;
use Ruhrcoder\RcDynamicPrice\Service\ConfigScope;
use Ruhrcoder\RcDynamicPrice\Service\MeterConfigResolverInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterSplittingConfig;
use Ruhrcoder\RcDynamicPrice\Service\ResolvedMeterConfig;
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

/**
 * Der Subscriber liest beim Weg in den Warenkorb die gewünschte Länge und bereitet die Position
 * für den Prozessor vor. Was er übersieht, verkauft der Shop zum Stückpreis; was er fälschlich
 * markiert, sperrt eine Bestellung, die nichts mit dem Meterpreis zu tun hat.
 */
final class LineItemSubscriberTest extends TestCase
{
    private RequestStack&MockObject $requestStack;
    private MeterProductHelperInterface&MockObject $meterProductHelper;
    private MeterConfigResolverInterface&MockObject $configResolver;
    private CartItemSplitAssemblerInterface&MockObject $assembler;
    private LineItemSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->meterProductHelper = $this->createMock(MeterProductHelperInterface::class);
        $this->configResolver = $this->createMock(MeterConfigResolverInterface::class);
        $this->assembler = $this->createMock(CartItemSplitAssemblerInterface::class);
        $this->subscriber = new LineItemSubscriber(
            $this->requestStack,
            $this->meterProductHelper,
            $this->configResolver,
            $this->assembler,
            new NullLogger(),
        );
    }

    public function testGetSubscribedEventsReturnsArray(): void
    {
        $events = LineItemSubscriber::getSubscribedEvents();
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

    // Ein Meterartikel ohne verwertbare Länge wird nicht still zum Stückpreis verkauft. Die Position
    // trägt das Meterkennzeichen, und der Prozessor lehnt sie ab und sperrt die Bestellung. Kehrte
    // der Subscriber ohne Länge vorher zurück, überspränge der Prozessor die Position, und der Kunde
    // bekäme den Zuschnitt zum Stückpreis.

    #[DataProvider('unusableLengthProvider')]
    public function testMeterItemWithUnusableLengthIsMarkedUnpriceable(mixed $rawLength): void
    {
        $this->setCurrentRequest($rawLength === null ? [] : ['mmLength' => $rawLength]);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(
            minLength: 1000,
            maxLength: 6000,
        ));
        $this->assembler->expects($this->never())->method('assemble');

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $this->subscriber->onBeforeLineItemAdded($this->createEvent($lineItem, 'sc-id', $cart));

        $this->assertTrue(
            $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE),
            'Die Position muss als Meter-Position markiert sein, sonst überspringt der Processor sie.',
        );
        $this->assertNull(
            $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM),
            'Eine unbrauchbare Eingabe darf nicht als Länge im Payload landen.',
        );
        $this->assertSame(1000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH));
        $this->assertSame(6000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableLengthProvider(): array
    {
        return [
            'Feld fehlt ganz' => [null],
            'Buchstaben angehängt' => ['5000abc'],
            'Kommazahl als String' => ['500.5'],
            'Kommazahl als Zahl' => [500.5],
            'Null' => ['0'],
            'Null als Zahl' => [0],
            'negative Zahl' => [-5],
            'Wahrheitswert' => [true],
            'Array' => [['5100']],
        ];
    }

    /**
     * Ein JSON-Client über die Store-API sendet `{"mmLength": 5100}` als Zahl. Nähme der Subscriber
     * nur Zeichenketten an, verwürfe er die Länge still, und die Position liefe zum Stückpreis.
     */
    public function testAcceptsIntegerLengthFromJsonClient(): void
    {
        $this->setCurrentRequest(['mmLength' => 5100]);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(
            minLength: 1000,
            maxLength: 6000,
        ));

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $this->assembler
            ->expects($this->once())
            ->method('assemble')
            ->with($cart, $lineItem, 5100, $this->anything());

        $this->subscriber->onBeforeLineItemAdded($this->createEvent($lineItem, 'sc-id', $cart));
    }

    /**
     * Ein Artikel ohne Meterpreis bleibt unberührt, auch ohne Längenangabe. Der Subscriber lädt für
     * jede Position das Produkt; normale Artikel darf er dabei weder markieren noch sperren.
     */
    public function testNonMeterProductWithoutLengthStaysUntouched(): void
    {
        $this->setCurrentRequest([]);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn(
            ResolvedMeterConfig::disabled(ConfigScope::Product),
        );
        $this->assembler->expects($this->never())->method('assemble');

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $this->subscriber->onBeforeLineItemAdded($this->createEvent($lineItem, 'sc-id', $cart));

        $this->assertSame([], $lineItem->getPayload());
    }

    public function testSkipsWhenReferencedIdIsNull(): void
    {
        $this->setCurrentRequest(['mmLength' => '5000']);
        $this->assembler->expects($this->never())->method('assemble');

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $event = $this->createEvent($lineItem);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenProductNotFound(): void
    {
        $this->setCurrentRequest(['mmLength' => '500']);
        $this->meterProductHelper->method('loadProduct')->willReturn(null);
        $this->configResolver->expects($this->never())->method('resolveForProduct');
        $this->assembler->expects($this->never())->method('assemble');

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $event = $this->createEvent($lineItem);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    public function testSkipsWhenResolverReportsInactive(): void
    {
        $this->setCurrentRequest(['mmLength' => '500']);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn(
            ResolvedMeterConfig::disabled(ConfigScope::Product),
        );
        $this->assembler->expects($this->never())->method('assemble');

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $event = $this->createEvent($lineItem);

        $this->subscriber->onBeforeLineItemAdded($event);
    }

    // Auch eine Länge außerhalb der Grenzen fällt nicht still auf den Stückpreis zurück. Sie wird
    // mitgeschrieben, damit der Prozessor sie gegen die hinterlegten Grenzen prüfen und dem Kunden
    // sagen kann, ob sie zu kurz oder zu lang war.

    #[DataProvider('outOfBoundsLengthProvider')]
    public function testOutOfBoundsLengthIsMarkedUnpriceableAndKeepsTheLength(string $rawLength, int $expected): void
    {
        $this->setCurrentRequest(['mmLength' => $rawLength]);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(
            minLength: 1000,
            maxLength: 6000,
        ));
        $this->assembler->expects($this->never())->method('assemble');

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $this->subscriber->onBeforeLineItemAdded($this->createEvent($lineItem, 'sc-id', $cart));

        $this->assertTrue($lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE));
        $this->assertSame($expected, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
        $this->assertSame(1000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH));
        $this->assertSame(6000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function outOfBoundsLengthProvider(): array
    {
        return [
            'unter der Mindestlänge' => ['500', 500],
            'über der Maximallänge' => ['7000', 7000],
        ];
    }

    public function testDelegatesToAssemblerWithConfig(): void
    {
        $this->setCurrentRequest(['mmLength' => '2000']);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(
            minLength: 1000,
            maxLength: 6000,
            splitMode: SplitMode::Equal,
            maxPieceLength: 5000,
        ));

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

    // Die Menge einer Meterposition ist auf dem Server immer 1. Die Kaufbox sendet ohnehin 1; über
    // die Store-API oder eine veränderte Anfrage käme sonst eine höhere Menge durch, und der Preis
    // im Warenkorb passte nicht mehr zur Schnittfolge, die für ein Stück berechnet ist.

    public function testForcesQuantityToOneOnManipulatedRequest(): void
    {
        $this->setCurrentRequest(['mmLength' => '2000']);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(
            minLength: 1000,
            maxLength: 6000,
        ));

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id', 5);
        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $this->subscriber->onBeforeLineItemAdded($this->createEvent($lineItem, 'sc-id', $cart));

        self::assertSame(1, $lineItem->getQuantity(), 'Menge einer Meter-Position muss auf 1 erzwungen werden.');
    }

    public function testLeavesQuantityOneUntouched(): void
    {
        $this->setCurrentRequest(['mmLength' => '2000']);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(
            minLength: 1000,
            maxLength: 6000,
        ));

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $this->subscriber->onBeforeLineItemAdded($this->createEvent($lineItem, 'sc-id', $cart));

        self::assertSame(1, $lineItem->getQuantity());
    }

    /**
     * Ist der Meterpreis aus, ist das Produkt kein Meterartikel, und die Menge bleibt, wie sie ist.
     */
    public function testLeavesQuantityUntouchedWhenConfigInactive(): void
    {
        $this->setCurrentRequest(['mmLength' => '2000']);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn(
            ResolvedMeterConfig::disabled(ConfigScope::Product),
        );

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id', 5);
        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $this->subscriber->onBeforeLineItemAdded($this->createEvent($lineItem, 'sc-id', $cart));

        self::assertSame(5, $lineItem->getQuantity(), 'Nicht-Meterartikel dürfen ihre Menge behalten.');
    }

    public function testReducesSplitModeToHintWhenForeignIdControllerMarkerIsPresent(): void
    {
        $this->setCurrentRequest(['mmLength' => '8000', 'rcTmmsActive' => '1']);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(
            minLength: 1,
            maxLength: 10000,
            splitMode: SplitMode::Equal,
            maxPieceLength: 5000,
        ));

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

    public function testReducesSplitModeToHintWhenTmmsMarkerIsNestedInLineItemsPayload(): void
    {
        // RcCartSplitter setzt sein Kennzeichen als lineItems[{productId}][payload][rcTmmsActive]=1.
        // Prüfte der Subscriber diese Ebene nicht, teilte er Positionen auf, deren Kennungen ein
        // anderes Plugin vergibt.
        $this->setCurrentRequest([
            'mmLength' => '8000',
            'lineItems' => [
                'product-id' => [
                    'id' => 'hash-uuid',
                    'payload' => ['rcTmmsActive' => '1'],
                ],
            ],
        ]);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(
            minLength: 1,
            maxLength: 10000,
            splitMode: SplitMode::Equal,
            maxPieceLength: 5000,
        ));

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

    public function testReducesSplitModeToHintWhenCustomFieldsMarkerIsNestedInLineItemsPayload(): void
    {
        // RcCustomFields setzt sein Kennzeichen ebenso als lineItems[{productId}][payload][rcCustomFieldsActive]=1.
        $this->setCurrentRequest([
            'mmLength' => '8000',
            'lineItems' => [
                'product-id' => [
                    'id' => 'hash-uuid',
                    'payload' => ['rcCustomFieldsActive' => '1'],
                ],
            ],
        ]);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(
            minLength: 1,
            maxLength: 10000,
            splitMode: SplitMode::MaxRest,
            maxPieceLength: 5000,
        ));

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

    public function testKeepsConfiguredSplitModeWhenLineItemsPayloadHasNoForeignMarker(): void
    {
        // Nur Angaben ohne fremdes Kennzeichen; die Aufteilung bleibt wie eingestellt.
        $this->setCurrentRequest([
            'mmLength' => '8000',
            'lineItems' => [
                'product-id' => [
                    'id' => 'plain-id',
                    'payload' => ['someOtherKey' => 'value'],
                ],
            ],
        ]);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(
            minLength: 1,
            maxLength: 10000,
            splitMode: SplitMode::Equal,
            maxPieceLength: 5000,
        ));

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');

        $this->assembler
            ->expects($this->once())
            ->method('assemble')
            ->with(
                $this->anything(),
                $this->anything(),
                8000,
                $this->callback(static fn (MeterSplittingConfig $c): bool => $c->splitMode === SplitMode::Equal),
            );

        $this->subscriber->onBeforeLineItemAdded($this->createEvent($lineItem, 'sc-id'));
    }

    // Länge je Position, wenn eine Anfrage mehrere Positionen bringt. readRequestedLength liest
    // aus drei Quellen, die erste gültige gewinnt: die Angabe je Position in der Anfrage, die schon
    // gesetzte Angabe an der Position (wiederhergestellter oder geteilter Warenkorb) und der flache
    // Schlüssel mmLength.

    /**
     * Quelle 1: `lineItems[<lineItemId>][payload][meterLengthMm]`. So bringt eine Anfrage mehrere
     * Positionen mit verschiedenen Längen (Schnellbestellung der B2bSuite).
     */
    public function testReadsPerPositionPayloadLength(): void
    {
        $this->setCurrentRequest([
            'lineItems' => [
                'line-id' => ['payload' => [DynamicPriceConstants::PAYLOAD_LENGTH_MM => '3000']],
            ],
        ]);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(1000, 6000));

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $this->assembler->expects($this->once())->method('assemble')->with($cart, $lineItem, 3000, $this->anything());
        $this->subscriber->onBeforeLineItemAdded($this->createEvent($lineItem, 'sc-id', $cart));
    }

    /**
     * Widersprechen sich die Angabe je Position und der flache Schlüssel, gewinnt die Angabe je
     * Position.
     */
    public function testPerPositionPayloadWinsOverFlatKey(): void
    {
        $this->setCurrentRequest([
            'mmLength' => '2000',
            'lineItems' => [
                'line-id' => ['payload' => [DynamicPriceConstants::PAYLOAD_LENGTH_MM => '3000']],
            ],
        ]);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(1000, 6000));

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $this->assembler->expects($this->once())->method('assemble')->with($cart, $lineItem, 3000, $this->anything());
        $this->subscriber->onBeforeLineItemAdded($this->createEvent($lineItem, 'sc-id', $cart));
    }

    /**
     * Ein solcher Widerspruch deutet auf eine falsch gebaute Anfrage und wird als Warnung
     * protokolliert; die Angabe je Position gewinnt trotzdem.
     */
    public function testLogsWarningOnAmbiguousLength(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('mehrdeutige'),
            $this->anything(),
        );
        $subscriber = new LineItemSubscriber(
            $this->requestStack,
            $this->meterProductHelper,
            $this->configResolver,
            $this->assembler,
            $logger,
        );

        $this->setCurrentRequest([
            'mmLength' => '2000',
            'lineItems' => ['line-id' => ['payload' => [DynamicPriceConstants::PAYLOAD_LENGTH_MM => '3000']]],
        ]);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(1000, 6000));

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $subscriber->onBeforeLineItemAdded($this->createEvent($lineItem, 'sc-id', $cart));
    }

    /**
     * Quelle 2: Trägt die Anfrage keine Länge, gilt die schon gesetzte Angabe an der Position. So
     * stellt FroshPlatformShareBasket einen Warenkorb wieder her: mit den Positionsdaten, aber ohne
     * mmLength in der Anfrage.
     */
    public function testReadsLengthFromRestoredLineItemPayload(): void
    {
        $this->setCurrentRequest([]);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(1000, 6000));

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, 4000);
        $cart = new Cart('test-token');
        $cart->add($lineItem);

        $this->assembler->expects($this->once())->method('assemble')->with($cart, $lineItem, 4000, $this->anything());
        $this->subscriber->onBeforeLineItemAdded($this->createEvent($lineItem, 'sc-id', $cart));
    }

    /**
     * Zwei Positionen mit verschiedenen Längen in einer Anfrage bekommen jede ihre eigene.
     */
    public function testMultiPositionRequestResolvesEachLength(): void
    {
        $this->setCurrentRequest([
            'lineItems' => [
                'line-a' => ['payload' => [DynamicPriceConstants::PAYLOAD_LENGTH_MM => '3000']],
                'line-b' => ['payload' => [DynamicPriceConstants::PAYLOAD_LENGTH_MM => '4500']],
            ],
        ]);
        $this->meterProductHelper->method('loadProduct')->willReturn(new ProductEntity());
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved(1000, 6000));

        $captured = [];
        $this->assembler->method('assemble')->willReturnCallback(
            static function (Cart $cart, LineItem $li, int $mm) use (&$captured): void {
                $captured[$li->getId()] = $mm;
            },
        );

        foreach (['line-a', 'line-b'] as $id) {
            $li = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id');
            $cart = new Cart('test-token');
            $cart->add($li);
            $this->subscriber->onBeforeLineItemAdded($this->createEvent($li, 'sc-id', $cart));
        }

        self::assertSame(['line-a' => 3000, 'line-b' => 4500], $captured);
    }

    /** @param array<string, mixed> $postData */
    private function setCurrentRequest(array $postData): void
    {
        $request = new Request();
        $request->request = new InputBag($postData);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
    }

    private function activeResolved(
        int $minLength = 1,
        int $maxLength = 10000,
        ?SplitMode $splitMode = null,
        int $maxPieceLength = 0,
        string $roundingMode = 'none',
    ): ResolvedMeterConfig {
        return new ResolvedMeterConfig(
            active: true,
            activeScope: ConfigScope::Product,
            minLength: $minLength,
            minLengthScope: ConfigScope::Product,
            maxLength: $maxLength,
            maxLengthScope: ConfigScope::Product,
            roundingMode: $roundingMode,
            roundingModeScope: ConfigScope::Product,
            splitMode: $splitMode,
            splitModeScope: ConfigScope::Product,
            maxPieceLength: $maxPieceLength,
            maxPieceLengthScope: ConfigScope::Product,
            splitHintTemplate: '',
            splitHintTemplateScope: ConfigScope::Default,
        );
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

    /**
     * Was: Ein Gutschein-Platzhalter im Warenkorb.
     * Warum: Der Warenkorb trägt mehr als Produkte. Ein Gutschein trägt an der Stelle der
     *        Produktkennung seinen Code; sucht der Subscriber damit ein Produkt, bricht die Suche
     *        mit einer Ausnahme ab, reißt den Warenkorb-Zugang mit, und kein Code ist mehr
     *        einlösbar, ohne dass das Protokoll etwas zeigt.
     * Erwartet: Der Subscriber steigt sofort aus, ohne das Produkt zu suchen.
     */
    public function testSkipsPromotionLineItemsWithoutTouchingTheProductLookup(): void
    {
        $this->setCurrentRequest(['mmLength' => '5000']);

        $this->meterProductHelper->expects($this->never())->method('loadProduct');
        $this->configResolver->expects($this->never())->method('resolveForProduct');
        $this->assembler->expects($this->never())->method('assemble');

        $lineItem = new LineItem('promo-id', LineItem::PROMOTION_LINE_ITEM_TYPE, 'Sommer2026');

        $this->subscriber->onBeforeLineItemAdded($this->createEvent($lineItem));
    }

    /**
     * Was: Eine Versandkosten-Position.
     * Warum: Stellvertretend für alle weiteren Nicht-Produkt-Typen. Ein Meterpreis ergibt dort
     *        keinen Sinn, und jede Auflösung wäre eine irreführende Protokollzeile.
     * Erwartet: unberührt.
     */
    public function testSkipsShippingLineItems(): void
    {
        $this->setCurrentRequest(['mmLength' => '5000']);
        $this->meterProductHelper->expects($this->never())->method('loadProduct');

        $lineItem = new LineItem('shipping-id', 'shipping', 'irgendwas');

        $this->subscriber->onBeforeLineItemAdded($this->createEvent($lineItem));
    }

    /**
     * Was: Der Split-Assembler wirft.
     * Warum: Der Ausfallschutz hängt im Weg in den Warenkorb. Eine Fehlkonfiguration darf keinen
     *        Serverfehler auslösen, sondern fällt auf „keine Aufteilung" zurück. Ein kaputter
     *        Schutz zeigte sich erst, wenn ein Kunde nichts mehr in den Warenkorb legen kann.
     * Erwartet: kein Wurf nach außen, dafür ein Eintrag im Fehlerprotokoll.
     */
    public function testAssemblerFailureDegradesToNoSplitAndIsLogged(): void
    {
        $this->setCurrentRequest(['mmLength' => '5000']);

        $product = $this->createMock(ProductEntity::class);
        $this->meterProductHelper->method('loadProduct')->willReturn($product);
        $this->configResolver->method('resolveForProduct')->willReturn($this->activeResolved());

        $this->assembler->method('assemble')
            ->willThrowException(new \RuntimeException('maxLength jenseits der Obergrenze'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Split-Assembler fehlgeschlagen'),
                $this->callback(static fn (array $logContext): bool => isset($logContext['exception'], $logContext['message']))
            );

        $subscriber = new LineItemSubscriber(
            $this->requestStack,
            $this->meterProductHelper,
            $this->configResolver,
            $this->assembler,
            $logger,
        );

        $lineItem = new LineItem('line-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        $subscriber->onBeforeLineItemAdded($this->createEvent($lineItem));
    }
}
