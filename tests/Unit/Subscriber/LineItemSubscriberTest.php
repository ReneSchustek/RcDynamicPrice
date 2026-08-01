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

    // --- Ein Meter-Artikel ohne verwertbare Länge wird nicht mehr still zum Stückpreis verkauft ---
    //
    // Früher kehrte der Subscriber vor dem Laden des Produkts zurück, sobald die Länge fehlte oder
    // nicht als String ankam. Ohne Payload übersprang der Processor die Position, und der Kunde
    // bekam den Zuschnitt-Artikel zum Stückpreis. Jetzt trägt die Position das Aktiv-Flag; der
    // Processor lehnt sie ab und blockiert die Bestellung.

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
     * Der Kernfehler aus dem Store-API-Smoke: Ein JSON-Client sendet `{"mmLength": 5100}` als Zahl.
     * Die alte is_string-Prüfung verwarf das still, die Position lief zum Stückpreis durch.
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
     * Regression: Ein Artikel ohne Meterpreis bleibt unberührt — auch ohne Längenangabe. Der
     * Subscriber lädt jetzt für jede Position das Produkt; er darf normale Artikel dabei weder
     * markieren noch blockieren.
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

    // --- Längen außerhalb der Grenzen: ebenfalls kein stiller Rückfall auf den Stückpreis ---
    //
    // Die unzulässige Länge wird mitgeschrieben, damit der Processor sie gegen die hinterlegten
    // Grenzen prüfen und dem Kunden sagen kann, ob sie zu kurz oder zu lang war.

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

    // --- Menge einer Meter-Position wird serverseitig auf 1 erzwungen ---
    //
    // Das Buy-Widget sendet immer quantity=1. Über die Store-API oder einen manipulierten Request
    // konnte eine höhere Menge durchrutschen: das Original behielt sie, die Split-Geschwister
    // wurden im Assembler fest mit Menge 1 erzeugt — der Warenkorb-Preis passte dann nicht mehr
    // zur bestellten Ware.

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
     * Ist die Meterpreis-Konfiguration nicht aktiv, ist das Produkt kein Meterartikel — die Menge
     * geht den Subscriber dann nichts an und muss unangetastet bleiben.
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
        // RcCartSplitter injiziert das Marker-Hidden-Input als lineItems[{productId}][payload][rcTmmsActive]=1.
        // Der Subscriber muss diese Payload-Ebene prüfen, sonst wird fremde ID-Hoheit übergangen.
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
        // RcCustomFields injiziert analog lineItems[{productId}][payload][rcCustomFieldsActive]=1.
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
        // Nur payload-Keys ohne Marker-Relevanz — Split-Modus bleibt wie konfiguriert.
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

    // --- Per-Positions-Länge für Mehrpositions-Adds ---
    //
    // readRequestedLength liest die Länge aus drei Quellen (erste gültige gewinnt):
    // 1. Per-Positions-Payload im Request, 2. bereits gesetzter LineItem-Payload
    // (Warenkorb-Wiederherstellung, ShareBasket), 3. flacher mmLength-Key.

    /**
     * Quelle 1: `lineItems[<lineItemId>][payload][meterLengthMm]`. Ermöglicht mehrere
     * Positionen mit unterschiedlichen Längen in einem Request (B2bSuite QuickOrder).
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
     * Vorrang: Ist sowohl der Per-Positions-Key als auch der flache Key gesetzt und
     * widersprüchlich, gewinnt der Per-Positions-Key.
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
     * Bei widersprüchlichem flachem und Per-Positions-Key wird eine Warnung geloggt
     * (fehlkonstruierter Request), der Per-Positions-Key gewinnt trotzdem.
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
     * Quelle 2: Trägt der Request keine Länge, wird sie aus dem bereits gesetzten
     * LineItem-Payload gelesen. Deckt die Warenkorb-Wiederherstellung ab
     * (FroshPlatformShareBasket restauriert den Payload, aber ohne mmLength im Request).
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
     * Mehrpositions-Request: zwei Positionen mit unterschiedlichen Per-Positions-Längen
     * in einem Request werden je korrekt aufgelöst.
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
}
