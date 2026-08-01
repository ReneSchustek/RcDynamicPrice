<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Cart;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcDynamicPrice\Cart\DynamicPriceProcessor;
use Ruhrcoder\RcDynamicPrice\Cart\Error\MeterPriceError;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Ruhrcoder\RcDynamicPrice\Service\Metrics\MetricsRecorderInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\Locale\LanguageLocaleCodeProvider;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DynamicPriceProcessorTest extends TestCase
{
    private QuantityPriceCalculator&MockObject $calculator;
    private MeterProductHelperInterface&MockObject $helper;
    private LoggerInterface&MockObject $logger;
    private MetricsRecorderInterface&MockObject $metrics;
    private DynamicPriceProcessor $processor;
    private SalesChannelContext&MockObject $context;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(QuantityPriceCalculator::class);
        $this->helper = $this->createMock(MeterProductHelperInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->metrics = $this->createMock(MetricsRecorderInterface::class);
        $localeProvider = $this->createMock(LanguageLocaleCodeProvider::class);
        $localeProvider->method('getLocaleForLanguageId')->willReturn('de-DE');

        $this->processor = new DynamicPriceProcessor(
            $this->calculator,
            $this->helper,
            $this->logger,
            $this->metrics,
            $this->snippetTranslator(),
            $localeProvider,
        );
        $this->context = $this->createMock(SalesChannelContext::class);
        $this->context->method('getLanguageId')->willReturn('language-id');
    }

    /**
     * Übersetzt gegen die echten Snippet-Dateien statt gegen einen Mock. Ein Mock würde jede
     * Umbenennung und jeden fehlenden Platzhalter durchgehen lassen — der Positionsname ist aber
     * genau der Text, den Kunde, Sachbearbeiter und Warenwirtschaft lesen.
     *
     * Das Locale wird ausgewertet, nicht ignoriert: Ohne explizites Locale übersetzt Shopwares
     * Translator im Cart-Processor gegen den Default `en-GB` — ein deutscher Kunde bekam dadurch
     * „Cut to length 5.100 mm" in den Positionsnamen, und zwar dauerhaft, weil der Name mit der
     * Bestellung gespeichert wird. Ein Translator-Fake ohne Locale-Prüfung hätte das nicht gezeigt.
     */
    private function snippetTranslator(): TranslatorInterface
    {
        $catalogues = [
            'de-DE' => $this->loadSnippets('de_DE/rc-dynamic-price.de-DE.json'),
            'en-GB' => $this->loadSnippets('en_GB/rc-dynamic-price.en-GB.json'),
        ];

        return new class ($catalogues) implements TranslatorInterface {
            /** @param array<string, array<string, string>> $catalogues */
            public function __construct(private readonly array $catalogues)
            {
            }

            /** @param array<string, mixed> $parameters */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                $catalogue = $this->catalogues[$locale ?? 'en-GB'] ?? [];
                $text = $catalogue[str_replace('rc-dynamic-price.', '', $id)] ?? $id;

                $replacements = [];
                foreach ($parameters as $name => $value) {
                    $replacements[$name] = (string) $value;
                }

                return strtr($text, $replacements);
            }

            public function getLocale(): string
            {
                return 'en-GB';
            }
        };
    }

    /**
     * @return array<string, string>
     */
    private function loadSnippets(string $relativePath): array
    {
        $raw = file_get_contents(__DIR__ . '/../../../src/Resources/snippet/' . $relativePath);
        self::assertIsString($raw);

        /** @var array{'rc-dynamic-price': array<string, string>} $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded['rc-dynamic-price'];
    }

    public function testSkipsLineItemWithoutMeterPriceActiveFlag(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('meterLengthMm', 1500);

        $this->calculator->expects($this->never())->method('calculate');

        $this->process([$lineItem]);
    }

    public function testSkipsLineItemWithoutMeterLengthPayload(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('rc_meter_price_active', true);

        $this->calculator->expects($this->never())->method('calculate');

        $this->process([$lineItem]);
    }

    public function testSkipsLineItemWithZeroLength(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('rc_meter_price_active', true);
        $lineItem->setPayloadValue('meterLengthMm', 0);

        $this->calculator->expects($this->never())->method('calculate');

        $this->process([$lineItem]);
    }

    public function testSkipsLineItemWithNegativeLength(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('rc_meter_price_active', true);
        $lineItem->setPayloadValue('meterLengthMm', -100);

        $this->calculator->expects($this->never())->method('calculate');

        $this->process([$lineItem]);
    }

    public function testSkipsLineItemWithNullPrice(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('rc_meter_price_active', true);
        $lineItem->setPayloadValue('meterLengthMm', 1500);

        $this->calculator->expects($this->never())->method('calculate');

        $this->process([$lineItem]);
    }

    public function testSkipsLineItemBelowMinLength(): void
    {
        $lineItem = $this->createMeterLineItem(500, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, 1000);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH, 6000);

        $this->calculator->expects($this->never())->method('calculate');

        $this->process([$lineItem]);
    }

    public function testSkipsLineItemAboveMaxLength(): void
    {
        $lineItem = $this->createMeterLineItem(7000, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, 1000);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH, 6000);

        $this->calculator->expects($this->never())->method('calculate');

        $this->process([$lineItem]);
    }

    public function testCalculatesPriceForValidMeterLengthItem(): void
    {
        $lineItem = $this->createMeterLineItem(1500, 100.0);
        $adjustedPrice = $this->createPrice(150.0);

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->with(
                $this->callback(fn (QuantityPriceDefinition $def) => $def->getPrice() === 150.0),
                $this->context,
            )
            ->willReturn($adjustedPrice);

        $this->process([$lineItem]);

        $this->assertSame($adjustedPrice, $lineItem->getPrice());
    }

    public function testSetsBilledLengthPayloadWithoutRounding(): void
    {
        $lineItem = $this->createMeterLineItem(1500, 100.0);

        $this->calculator->method('calculate')->willReturn($this->createPrice(150.0));

        $this->process([$lineItem]);

        $this->assertSame(1500, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
    }

    public function testCalculatesPriceWithFullMeterRounding(): void
    {
        $lineItem = $this->createMeterLineItem(4050, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'full_m');

        $this->helper->method('roundUp')->with(4050, 'full_m')->willReturn(5000);

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->with(
                $this->callback(fn (QuantityPriceDefinition $def) => $def->getPrice() === 500.0),
                $this->context,
            )
            ->willReturn($this->createPrice(500.0));

        $this->process([$lineItem]);
    }

    public function testBilledLengthReflectsRounding(): void
    {
        $lineItem = $this->createMeterLineItem(4050, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'full_m');

        $this->helper->method('roundUp')->willReturn(5000);
        $this->calculator->method('calculate')->willReturn($this->createPrice(500.0));

        $this->process([$lineItem]);

        $this->assertSame(5000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
    }

    public function testCalculatesPriceWithQuarterMeterRounding(): void
    {
        $lineItem = $this->createMeterLineItem(1300, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'quarter_m');

        $this->helper->method('roundUp')->with(1300, 'quarter_m')->willReturn(1500);

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->with(
                $this->callback(fn (QuantityPriceDefinition $def) => $def->getPrice() === 150.0),
                $this->context,
            )
            ->willReturn($this->createPrice(150.0));

        $this->process([$lineItem]);
    }

    public function testCalculatesPriceWithHalfMeterRounding(): void
    {
        $lineItem = $this->createMeterLineItem(2100, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'half_m');

        $this->helper->method('roundUp')->with(2100, 'half_m')->willReturn(2500);

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->with(
                $this->callback(fn (QuantityPriceDefinition $def) => $def->getPrice() === 250.0),
                $this->context,
            )
            ->willReturn($this->createPrice(250.0));

        $this->process([$lineItem]);
    }

    public function testCalculatesPriceWithCmRounding(): void
    {
        $lineItem = $this->createMeterLineItem(1505, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'cm');

        $this->helper->method('roundUp')->with(1505, 'cm')->willReturn(1510);

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->with(
                $this->callback(fn (QuantityPriceDefinition $def) => $def->getPrice() === 151.0),
                $this->context,
            )
            ->willReturn($this->createPrice(151.0));

        $this->process([$lineItem]);
    }

    public function testNoRoundingWhenModeIsNone(): void
    {
        $lineItem = $this->createMeterLineItem(4050, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'none');

        $this->helper->method('roundUp')->with(4050, 'none')->willReturn(4050);

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->with(
                $this->callback(fn (QuantityPriceDefinition $def) => $def->getPrice() === 405.0),
                $this->context,
            )
            ->willReturn($this->createPrice(405.0));

        $this->process([$lineItem]);
    }

    public function testNoRoundingWhenModeIsMissing(): void
    {
        $lineItem = $this->createMeterLineItem(4050, 100.0);

        $this->calculator->method('calculate')->willReturn($this->createPrice(405.0));

        $this->process([$lineItem]);

        $this->assertSame(4050, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
    }

    public function testAcceptsLengthWithinBounds(): void
    {
        $lineItem = $this->createMeterLineItem(3000, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, 1000);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH, 6000);

        $this->calculator->expects($this->once())->method('calculate')->willReturn($this->createPrice(300.0));

        $this->process([$lineItem]);
    }

    public function testRoundingAppliesPerSiblingWhenCartHasSplitItems(): void
    {
        // Splitting-Szenario: 3x 4750 mm landen im Cart (vom Subscriber erzeugt), full_m rundet pro Teilstück auf 5000 mm.
        $primary = $this->createMeterLineItemWithId('primary-id', 4750, 100.0);
        $primary->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'full_m');

        $sibling1 = $this->createMeterLineItemWithId('primary-id-piece1', 4750, 100.0);
        $sibling1->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'full_m');

        $sibling2 = $this->createMeterLineItemWithId('primary-id-piece2', 4750, 100.0);
        $sibling2->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'full_m');

        $this->helper->method('roundUp')->with(4750, 'full_m')->willReturn(5000);
        $this->calculator->method('calculate')->willReturn($this->createPrice(500.0));

        $this->process([$primary, $sibling1, $sibling2]);

        // Jeder LineItem wird unabhängig gerundet und behält seine eigene billed_length
        $this->assertSame(5000, $primary->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
        $this->assertSame(5000, $sibling1->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
        $this->assertSame(5000, $sibling2->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
    }

    public function testWritesBilledLengthIntoDeliveryInformationForStandardShopwareRules(): void
    {
        $lineItem = $this->createMeterLineItem(800, 100.0);
        $lineItem->setDeliveryInformation(new \Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation(
            10,
            1.5,
            false,
            null,
            null,
            null,
            null,
            6000.0,
        ));

        $this->calculator->method('calculate')->willReturn($this->createPrice(80.0));

        $this->process([$lineItem]);

        $delivery = $lineItem->getDeliveryInformation();
        self::assertNotNull($delivery);
        self::assertSame(800.0, $delivery->getLength(), 'DeliveryInformation.length muss auf die billed-Length gesetzt werden, damit LineItemDimensionLengthRule greift.');
    }

    public function testHandlesMissingDeliveryInformationGracefully(): void
    {
        $lineItem = $this->createMeterLineItem(800, 100.0);
        // Kein setDeliveryInformation — z. B. bei direkt erzeugten LineItems ohne Produkt-Stammdaten.

        $this->calculator->method('calculate')->willReturn($this->createPrice(80.0));

        $this->process([$lineItem]);

        self::assertNull($lineItem->getDeliveryInformation());
        self::assertSame(800, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
    }

    public function testWritesRoundedBilledLengthIntoDeliveryInformation(): void
    {
        $lineItem = $this->createMeterLineItem(4050, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'full_m');
        $lineItem->setDeliveryInformation(new \Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation(
            10,
            1.5,
            false,
            null,
            null,
            null,
            null,
            6000.0,
        ));

        $this->helper->method('roundUp')->willReturn(5000);
        $this->calculator->method('calculate')->willReturn($this->createPrice(500.0));

        $this->process([$lineItem]);

        $delivery = $lineItem->getDeliveryInformation();
        self::assertNotNull($delivery);
        self::assertSame(5000.0, $delivery->getLength(), 'Bei aktiver Rundung muss die gerundete Länge in der DeliveryInformation stehen — sonst kommen Versandregel und Berechnungsbasis auseinander.');
    }

    public function testRecordsCounterMetricForProcessedItem(): void
    {
        $lineItem = $this->createMeterLineItem(1500, 100.0);

        $this->calculator->method('calculate')->willReturn($this->createPrice(150.0));

        $this->metrics
            ->expects($this->once())
            ->method('increment')
            ->with(DynamicPriceConstants::METRIC_CART_ITEM_PROCESSED, ['rounding' => 'none']);

        $this->process([$lineItem]);
    }

    public function testDoesNotRecordCounterMetricForSkippedItem(): void
    {
        // Position ohne aktives Meter-Flag wird übersprungen -> keine Metrik.
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue('meterLengthMm', 1500);

        $this->metrics->expects($this->never())->method('increment');

        $this->process([$lineItem]);
    }

    /**
     * Der Preis eines Zuschnitt-Auftrags ist die Summe der **einzeln aufgerundeten** Teilstücke,
     * nicht die aufgerundete Eingabelänge. Beispiel aus der Live-Konfiguration: 5.100 mm, max_rest,
     * maxPiece 5.000, min 1.000, Rundung full_m. Der Rest von 100 mm wird auf die Mindestlänge
     * von 1.000 mm angehoben — berechnet werden 5.000 + 1.000 = 6.000 mm zu je 301,54 EUR/m.
     */
    public function testPriceIsSumOfIndividuallyRoundedPieces(): void
    {
        $lineItem = $this->createMeterLineItem(5100, 301.54);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES, [5000, 1000]);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'full_m');

        $this->helper->method('roundUp')->willReturnCallback(
            static fn (int $mm, string $mode): int => (int) (\ceil($mm / 1000) * 1000)
        );

        $this->calculator->expects($this->once())
            ->method('calculate')
            ->with($this->callback(static function (QuantityPriceDefinition $definition): bool {
                // (301.54 / 1000) * 6000 = 1809.24
                return \abs($definition->getPrice() - 1809.24) < 0.001;
            }))
            ->willReturn($this->createPrice(1809.24));

        $this->process([$lineItem]);

        self::assertSame(6000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
        self::assertSame([5000, 1000], $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_PIECES));
    }

    /**
     * Die längenbasierten Versandregeln (`cartLineItemDimensionLength`) entscheiden über die
     * Versandart. Sie müssen die **längste Einzellänge** sehen, nicht die Gesamtlänge des Auftrags —
     * versendet werden die einzelnen Zuschnitte.
     */
    public function testDeliveryLengthIsLongestPieceNotTotal(): void
    {
        $lineItem = $this->createMeterLineItem(8000, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES, [5000, 3000]);
        $lineItem->setDeliveryInformation(new DeliveryInformation(10, 0.0, false, null, null, 0.0, 0.0, 0.0));

        $this->calculator->method('calculate')->willReturn($this->createPrice(800.0));

        $this->process([$lineItem]);

        self::assertSame(5000.0, $lineItem->getDeliveryInformation()?->getLength());
        self::assertSame(5000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_PIECE_LENGTH_MM));
    }

    public function testSplitSummaryGroupsEqualPieces(): void
    {
        $lineItem = $this->createMeterLineItem(15000, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES, [5000, 5000, 3000]);

        $this->calculator->method('calculate')->willReturn($this->createPrice(1300.0));

        $this->process([$lineItem]);

        self::assertSame(
            [
                ['length' => 5000, 'count' => 2],
                ['length' => 3000, 'count' => 1],
            ],
            $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_SUMMARY),
        );
    }

    /**
     * Bestandswarenkörbe aus der Zeit vor den Auftrags-Positionen tragen keine Teilstück-Liste.
     * Für sie gilt die Eingabelänge als einziges Teilstück — ihr Preis darf sich nicht ändern.
     */
    public function testLegacyLineItemWithoutSplitPiecesKeepsItsPrice(): void
    {
        $lineItem = $this->createMeterLineItem(3000, 100.0);

        $this->calculator->expects($this->once())
            ->method('calculate')
            ->with($this->callback(static function (QuantityPriceDefinition $definition): bool {
                // (100.0 / 1000) * 3000 = 300.0 — exakt wie vor dem Umbau
                return \abs($definition->getPrice() - 300.0) < 0.001;
            }))
            ->willReturn($this->createPrice(300.0));

        $this->process([$lineItem]);

        self::assertSame(3000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
    }

    // --- Schnittlänge und Abrechnungslänge sind zweierlei ---
    //
    // Die Mindestlänge ist eine Abrechnungsregel, keine Fertigungsregel. Wer 5.100 mm bestellt,
    // bekommt 5.000 + 100 mm geschnitten und zahlt 6.000 mm (das Reststück zur Mindestlänge).
    // Vorher hob der Splitter das Reststück physisch an — der Kunde bekam 900 mm zu viel.

    public function testShortRemainderIsBilledAtTheMinimumButCutAsOrdered(): void
    {
        $lineItem = $this->createMeterLineItem(5100, 100.0);
        $lineItem->setLabel('Bodenprofil');
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES, [5000, 100]);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, 1000);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_BILLING, true);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING, 'full_m');

        $this->helper->method('roundUp')->willReturnCallback(
            static fn (int $mm, string $mode): int => (int) \ceil($mm / 1000) * 1000,
        );

        $this->calculator->expects($this->once())
            ->method('calculate')
            ->with($this->callback(static function (QuantityPriceDefinition $definition): bool {
                // (100,00 EUR/m / 1000) * 6000 mm = 600,00 EUR — wie vor der Trennung
                return \abs($definition->getPrice() - 600.0) < 0.001;
            }))
            ->willReturn($this->createPrice(600.0));

        $this->process([$lineItem]);

        self::assertSame(
            [5000, 1000],
            $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_PIECES),
            'Abgerechnet wird das Reststück mit der Mindestlänge.',
        );
        self::assertSame(6000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
        self::assertSame(
            'Bodenprofil (Zuschnitt 5.100 mm: 1× 5.000 mm + 1× 100 mm, berechnet 6.000 mm)',
            $lineItem->getLabel(),
            'Der Name ist die Fertigungsanweisung — er muss die Schnittlängen nennen.',
        );
    }

    /**
     * Ohne die Abrechnungs-Option (equal-Modus mit `equalSplitEnforceMin` = aus) wird das kurze
     * Teilstück auch nicht angehoben — geschnitten und berechnet wird die tatsächliche Länge.
     */
    public function testShortPieceIsNotRaisedWhenMinimumBillingIsOff(): void
    {
        $lineItem = $this->createMeterLineItem(1100, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES, [550, 550]);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, 600);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_BILLING, false);

        $this->calculator->method('calculate')->willReturn($this->createPrice(110.0));

        $this->process([$lineItem]);

        self::assertSame([550, 550], $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_PIECES));
        self::assertSame(1100, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
    }

    /**
     * Bestandswarenkörbe tragen kein `rc_min_billing`, ihre Schnittlängen sind aber bereits
     * angehoben. Sie dürfen nicht ein zweites Mal angehoben werden — ihr Preis bleibt unverändert.
     */
    public function testLegacyCartWithAlreadyRaisedPiecesKeepsItsPrice(): void
    {
        $lineItem = $this->createMeterLineItem(5100, 100.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES, [5000, 1000]);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, 1000);

        $this->calculator->method('calculate')->willReturn($this->createPrice(600.0));

        $this->process([$lineItem]);

        self::assertSame([5000, 1000], $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_PIECES));
        self::assertSame(6000, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM));
    }

    // --- Der Positionsname trägt Länge und Aufteilung ---
    //
    // Er ist die einzige Angabe, die Shopware bis in die Bestellung durchreicht und die jede
    // Warenwirtschaft übernimmt (orgaMAX liest ihn als "abweichenderArtikeltext"). Stünde die Länge
    // nur im Payload, wüsste die Fertigung aus dem ERP nicht, was zu schneiden ist, und die
    // Admin-Bestellansicht zeigte einen vierstelligen Betrag für "1 Stück" ohne Erklärung.

    public function testLabelCarriesLengthWithoutSplit(): void
    {
        $lineItem = $this->createMeterLineItem(2000, 100.0);
        $lineItem->setLabel('Bodenprofil');

        $this->calculator->method('calculate')->willReturn($this->createPrice(200.0));

        $this->process([$lineItem]);

        self::assertSame('Bodenprofil (Länge 2.000 mm)', $lineItem->getLabel());
    }

    public function testLabelNamesTheBilledLengthWhenItDiffersFromTheInput(): void
    {
        $lineItem = $this->createMeterLineItem(5100, 100.0);
        $lineItem->setLabel('Bodenprofil');
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES, [5000, 1000]);

        $this->calculator->method('calculate')->willReturn($this->createPrice(600.0));

        $this->process([$lineItem]);

        self::assertSame(
            'Bodenprofil (Zuschnitt 5.100 mm: 1× 5.000 mm + 1× 1.000 mm, berechnet 6.000 mm)',
            $lineItem->getLabel(),
        );
    }

    /**
     * Der Fehler, den in v1.16.0 erst der Storefront-Smoke fand: Drei gleich lange Stücke ergeben
     * nur **eine** Anzeigegruppe. Wer auf die Gruppen zählt, hält einen equal-Split für "kein Split"
     * und unterschlägt die Aufteilung. Gezählt werden muss die Teilstück-Liste.
     */
    public function testEqualSplitIntoIdenticalPiecesIsStillShownAsSplit(): void
    {
        $lineItem = $this->createMeterLineItem(15000, 100.0);
        $lineItem->setLabel('Bodenprofil');
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES, [5000, 5000, 5000]);

        $this->calculator->method('calculate')->willReturn($this->createPrice(1500.0));

        $this->process([$lineItem]);

        self::assertSame('Bodenprofil (Zuschnitt 15.000 mm: 3× 5.000 mm)', $lineItem->getLabel());
    }

    /**
     * Der Warenkorb wird bei jeder Änderung neu berechnet. Würde der Zusatz an das bestehende Label
     * angehängt statt aus dem gemerkten Basisnamen neu gebildet, stünde er nach dem dritten Lauf
     * dreimal im Namen.
     */
    public function testLabelIsRebuiltNotAppendedOnRepeatedProcessing(): void
    {
        $lineItem = $this->createMeterLineItem(2000, 100.0);
        $lineItem->setLabel('Bodenprofil');

        $this->calculator->method('calculate')->willReturn($this->createPrice(200.0));

        $this->process([$lineItem]);
        $this->process([$lineItem]);
        $this->process([$lineItem]);

        self::assertSame('Bodenprofil (Länge 2.000 mm)', $lineItem->getLabel());
        self::assertSame('Bodenprofil', $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BASE_LABEL));
    }

    /**
     * Nach dem Umbau auf Auftrags-Positionen setzt der Core das Label bei jedem Durchlauf neu aus
     * dem Produkt. Auch dann darf nur der Basisname als Grundlage dienen — und der bleibt gemerkt.
     */
    public function testLabelSurvivesCoreResettingItToTheProductName(): void
    {
        $lineItem = $this->createMeterLineItem(2000, 100.0);
        $lineItem->setLabel('Bodenprofil');

        $this->calculator->method('calculate')->willReturn($this->createPrice(200.0));

        $this->process([$lineItem]);
        $lineItem->setLabel('Bodenprofil');
        $this->process([$lineItem]);

        self::assertSame('Bodenprofil (Länge 2.000 mm)', $lineItem->getLabel());
    }

    /**
     * Der Positionsname wird mit der Bestellung gespeichert und wandert von dort in Beleg und
     * Warenwirtschaft — er muss beim ersten Mal in der Sprache des Warenkorbs stehen. Im
     * Store-API-Smoke bekam ein deutscher Warenkorb „Cut to length 5.100 mm", weil der Translator
     * im Cart-Processor ohne explizites Locale gegen Shopwares Default en-GB übersetzt.
     */
    public function testLabelUsesTheLanguageOfTheSalesChannelNotTheRequestDefault(): void
    {
        $localeProvider = $this->createMock(LanguageLocaleCodeProvider::class);
        $localeProvider->method('getLocaleForLanguageId')->willReturn('en-GB');

        $processor = new DynamicPriceProcessor(
            $this->calculator,
            $this->helper,
            $this->logger,
            $this->metrics,
            $this->snippetTranslator(),
            $localeProvider,
        );

        $lineItem = $this->createMeterLineItem(2000, 100.0);
        $lineItem->setLabel('Floor profile');
        $this->calculator->method('calculate')->willReturn($this->createPrice(200.0));

        $cart = new Cart('to-calculate');
        $cart->getLineItems()->add($lineItem);
        $processor->process(new CartDataCollection(), new Cart('original'), $cart, $this->context, new CartBehavior());

        self::assertSame('Floor profile (Length 2.000 mm)', $lineItem->getLabel());
    }

    public function testLineItemWithoutLabelIsLeftAlone(): void
    {
        $lineItem = $this->createMeterLineItem(2000, 100.0);

        $this->calculator->method('calculate')->willReturn($this->createPrice(200.0));

        $this->process([$lineItem]);

        self::assertNull($lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BASE_LABEL));
    }

    private function createMeterLineItemWithId(string $id, int $mm, float $unitPrice): LineItem
    {
        $lineItem = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, $mm);
        $lineItem->setPrice($this->createPrice($unitPrice));

        return $lineItem;
    }

    private function createMeterLineItem(int $mm, float $unitPrice): LineItem
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, $mm);
        $lineItem->setPrice($this->createPrice($unitPrice));

        return $lineItem;
    }

    private function createPrice(float $unitPrice): CalculatedPrice
    {
        return new CalculatedPrice(
            $unitPrice,
            $unitPrice,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
        );
    }

    /** @param LineItem[] $items */
    private function process(array $items): void
    {
        $cart = new Cart('to-calculate');
        foreach ($items as $item) {
            $cart->getLineItems()->add($item);
        }

        $this->processor->process(
            new CartDataCollection(),
            new Cart('original'),
            $cart,
            $this->context,
            new CartBehavior(),
        );
    }

    // --- Verworfene Meter-Positionen erzeugen einen blockierenden Warenkorb-Fehler ---
    //
    // Vorher fielen sie still auf den Basispreis zurück: der Kunde bestellte zu einem Preis,
    // der die Länge ignoriert — bei Meterware praktisch immer zu billig.

    public function testAddsBlockingErrorForInvalidLength(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, 0);
        $lineItem->setPrice($this->createPrice(10.0));

        $cart = $this->processReturningCart([$lineItem]);

        $this->assertMeterPriceErrorPresent($cart, 'item-1');
    }

    /**
     * So kommt die Position aus dem Subscriber, wenn ein Client den Meter-Artikel ganz ohne Länge
     * in den Warenkorb legt: Aktiv-Flag gesetzt, Längen-Payload leer. Ohne Blockade würde der
     * Zuschnitt-Artikel zum Stückpreis verkauft.
     */
    public function testAddsBlockingErrorWhenLengthPayloadIsMissingEntirely(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
        $lineItem->setPrice($this->createPrice(10.0));

        $this->calculator->expects($this->never())->method('calculate');

        $cart = $this->processReturningCart([$lineItem]);

        $this->assertMeterPriceErrorPresent($cart, 'item-1');
    }

    public function testAddsBlockingErrorWhenBelowMinimum(): void
    {
        $lineItem = $this->createMeterLineItem(500, 10.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, 1000);

        $cart = $this->processReturningCart([$lineItem]);

        $this->assertMeterPriceErrorPresent($cart, 'item-1');
    }

    public function testAddsBlockingErrorWhenAboveMaximum(): void
    {
        $lineItem = $this->createMeterLineItem(9000, 10.0);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH, 6000);

        $cart = $this->processReturningCart([$lineItem]);

        $this->assertMeterPriceErrorPresent($cart, 'item-1');
    }

    public function testAddsBlockingErrorWhenBasePriceMissing(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, 1500);
        $lineItem->setPrice($this->createPrice(0.0));

        $cart = $this->processReturningCart([$lineItem]);

        $this->assertMeterPriceErrorPresent($cart, 'item-1');
    }

    public function testSuccessfulCalculationAddsNoError(): void
    {
        $lineItem = $this->createMeterLineItem(1500, 10.0);

        $this->calculator->method('calculate')->willReturn($this->createPrice(15.0));

        $cart = $this->processReturningCart([$lineItem]);

        self::assertCount(0, $cart->getErrors(), 'Eine gültige Meterposition darf keinen Fehler erzeugen.');
    }

    /**
     * Eine Position ohne Meter-Flag ist kein Meterartikel — sie darf keinen Fehler auslösen,
     * auch wenn ihr die Meter-Payload fehlt.
     */
    public function testNonMeterLineItemAddsNoError(): void
    {
        $lineItem = new LineItem('item-1', LineItem::PRODUCT_LINE_ITEM_TYPE);

        $cart = $this->processReturningCart([$lineItem]);

        self::assertCount(0, $cart->getErrors());
    }

    private function assertMeterPriceErrorPresent(Cart $cart, string $lineItemId): void
    {
        $errors = $cart->getErrors()->getElements();

        self::assertCount(1, $errors, 'Genau ein Warenkorb-Fehler erwartet.');

        $error = array_values($errors)[0];

        self::assertInstanceOf(MeterPriceError::class, $error);
        self::assertTrue($error->blockOrder(), 'Der Fehler muss die Bestellung blockieren.');
        self::assertSame(Error::LEVEL_ERROR, $error->getLevel());
        self::assertStringContainsString($lineItemId, $error->getId());
    }

    /** @param LineItem[] $items */
    private function processReturningCart(array $items): Cart
    {
        $cart = new Cart('to-calculate');
        foreach ($items as $item) {
            $cart->getLineItems()->add($item);
        }

        $this->processor->process(
            new CartDataCollection(),
            new Cart('original'),
            $cart,
            $this->context,
            new CartBehavior(),
        );

        return $cart;
    }
}
