<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Cart;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcDynamicPrice\Cart\Error\MeterPriceError;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Ruhrcoder\RcDynamicPrice\Service\Metrics\MetricsRecorderInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\System\Locale\LanguageLocaleCodeProvider;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Rechnet den Preis jeder Meterposition aus ihrer Länge und schreibt Länge und Aufteilung in den
 * Positionsnamen.
 *
 * Geschnitten wird, was der Kunde bestellt hat; abgerechnet wird je Teilstück mit Mindestlänge und
 * Rundung. Lässt sich kein Preis ermitteln, bleibt die Position sichtbar und die Bestellung gesperrt
 * (MeterPriceError), statt still zum Grundpreis durchzugehen.
 */
final class DynamicPriceProcessor implements CartProcessorInterface
{
    public function __construct(
        private readonly QuantityPriceCalculator $calculator,
        private readonly MeterProductHelperInterface $meterProductHelper,
        private readonly LoggerInterface $logger,
        private readonly MetricsRecorderInterface $metrics,
        private readonly TranslatorInterface $translator,
        private readonly LanguageLocaleCodeProvider $localeProvider,
    ) {
    }

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior,
    ): void {
        foreach ($toCalculate->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            if ($lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE) !== true) {
                continue;
            }

            $mmLength = $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM);

            if (!is_int($mmLength) || $mmLength <= 0) {
                $this->logger->warning('RcDynamicPrice: Ungültige Länge im LineItem', [
                    'lineItemId' => $lineItem->getId(),
                    'meterLengthMm' => $mmLength,
                ]);
                $this->rejectLineItem($toCalculate, $lineItem, 'ungültige Länge');
                continue;
            }

            // Die Grenzen stehen seit dem Hinzufügen im Payload; geprüft wird bei jeder Berechnung neu,
            // weil auch ein wiederhergestellter oder von außen gebauter Warenkorb hier durchläuft.
            $minLength = $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH);
            $maxLength = $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH);

            if (is_int($minLength) && $mmLength < $minLength) {
                $this->logger->warning('RcDynamicPrice: Länge unter Minimum', [
                    'lineItemId' => $lineItem->getId(),
                    'meterLengthMm' => $mmLength,
                    'minLength' => $minLength,
                ]);
                $this->rejectLineItem($toCalculate, $lineItem, 'Länge unter der Mindestlänge');
                continue;
            }

            if (is_int($maxLength) && $mmLength > $maxLength) {
                $this->logger->warning('RcDynamicPrice: Länge über Maximum', [
                    'lineItemId' => $lineItem->getId(),
                    'meterLengthMm' => $mmLength,
                    'maxLength' => $maxLength,
                ]);
                $this->rejectLineItem($toCalculate, $lineItem, 'Länge über der Maximallänge');
                continue;
            }

            $price = $lineItem->getPrice();
            if ($price === null || $price->getUnitPrice() <= 0.0) {
                $this->logger->warning('RcDynamicPrice: Kein oder ungültiger Preis am LineItem', [
                    'lineItemId' => $lineItem->getId(),
                    'unitPrice' => $price?->getUnitPrice(),
                ]);
                $this->rejectLineItem($toCalculate, $lineItem, 'kein gültiger Grundpreis am Artikel');
                continue;
            }

            $roundingMode = $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING);

            $cutPieces = $this->cutPieces($lineItem, $mmLength);

            // Abgerechnet wird je Teilstück, auf die Mindestlänge angehoben und einzeln aufgerundet, dann
            // summiert, und nicht die gerundete Eingabelänge. Ist die Rundungsstufe kein Teiler der
            // Teilstücklängen, ergeben beide Wege verschiedene Beträge.
            $billedPieces = $this->billedPieces($lineItem, $cutPieces, $roundingMode);
            $billedLength = \array_sum($billedPieces);
            $longestPiece = \max($billedPieces);

            // Der Grundpreis des Artikels ist ein Meterpreis, die Längen stehen in Millimetern.
            $adjustedUnitPrice = ($price->getUnitPrice() / 1000.0) * $billedLength;

            $definition = new QuantityPriceDefinition(
                $adjustedUnitPrice,
                $price->getTaxRules(),
                $lineItem->getQuantity()
            );

            $lineItem->setPrice($this->calculator->calculate($definition, $context));

            // Gruppiert werden die Schnittlängen: Der Positionsname ist die Anweisung an die Fertigung
            // und sagt, was zu schneiden ist. Die Abrechnungslänge steht als eigene Angabe dahinter.
            $splitSummary = $this->groupPieces($cutPieces);

            $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_PIECES, $billedPieces);
            $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_BILLED_LENGTH_MM, $billedLength);
            $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_PIECE_LENGTH_MM, $longestPiece);
            $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_SUMMARY, $splitSummary);

            $this->applyLabel($lineItem, $mmLength, $billedLength, $cutPieces, $splitSummary, $this->locale($context));

            // Die DeliveryInformation trägt die längste Einzellänge, nicht die Gesamtlänge. Shopwares
            // Längenregeln (LineItemDimensionLengthRule) wählen die Versandart, und versendet werden
            // die einzelnen Zuschnitte; das längste Stück entscheidet zwischen Paket und Spedition.
            $delivery = $lineItem->getDeliveryInformation();
            if ($delivery !== null) {
                $delivery->setLength((float)$longestPiece);
            }

            // Kein try/catch: Der Recorder wirft per Vertrag nie.
            $this->metrics->increment(DynamicPriceConstants::METRIC_CART_ITEM_PROCESSED, [
                'rounding' => \is_string($roundingMode) ? $roundingMode : DynamicPriceConstants::ROUNDING_NONE,
            ]);
        }
    }

    /**
     * Schreibt Länge und Aufteilung in den Positionsnamen.
     *
     * Der Name ist die einzige Angabe, die Shopware bis in die Bestellung durchreicht und die jede
     * Warenwirtschaft übernimmt; der Payload ist es nicht. Stünde die Länge nur dort, wüsste die
     * Fertigung aus der Warenwirtschaft nicht, was zu schneiden ist, und die Verwaltung zeigte einen
     * vierstelligen Betrag für „1 Stück" ohne Erklärung.
     *
     * Der Name wird immer aus dem gemerkten Grundnamen neu gebildet und nie an den Bestand
     * angehängt, sonst stünde der Zusatz nach jedem Neuberechnen einmal mehr da.
     *
     * @param non-empty-list<int>                            $cutPieces    nur ihre Zahl zählt
     * @param non-empty-list<array{length: int, count: int}> $splitSummary
     */
    private function applyLabel(
        LineItem $lineItem,
        int $mmLength,
        int $billedLength,
        array $cutPieces,
        array $splitSummary,
        string $locale,
    ): void {
        $baseLabel = $this->baseLabel($lineItem);
        if ($baseLabel === '') {
            return;
        }

        // Gezählt werden Teilstücke, nicht Gruppen: Drei gleich lange Stücke sind eine Gruppe und
        // trotzdem eine Teilung.
        $details = \count($cutPieces) > 1
            ? $this->trans('rc-dynamic-price.labelSplit', [
                '%length%' => $this->formatMm($mmLength),
                '%pieces%' => $this->formatPieces($splitSummary, $locale),
            ], $locale)
            : $this->trans('rc-dynamic-price.labelLength', [
                '%length%' => $this->formatMm($mmLength),
            ], $locale);

        if ($billedLength !== $mmLength) {
            $details .= $this->trans('rc-dynamic-price.labelBilled', [
                '%billed%' => $this->formatMm($billedLength),
            ], $locale);
        }

        $lineItem->setLabel($this->trans('rc-dynamic-price.labelWithDetails', [
            '%name%' => $baseLabel,
            '%details%' => $details,
        ], $locale));
    }

    /**
     * Die Sprache des Warenkorbs, nicht die des Requests.
     *
     * Im Warenkorb-Processor ist der Translator nicht auf den Verkaufskanal eingestellt und übersetzt
     * ohne ausdrückliche Sprache nach Shopwares Vorgabe `en-GB`. Ein deutscher Kunde läse sonst
     * „Cut to length 5.100 mm" im Positionsnamen und damit in Bestellung, Beleg und Warenwirtschaft.
     * Der Name wird einmal geschrieben und bleibt; er muss beim ersten Mal stimmen.
     */
    private function locale(SalesChannelContext $context): string
    {
        return $this->localeProvider->getLocaleForLanguageId($context->getLanguageId());
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $key, array $parameters, string $locale): string
    {
        return $this->translator->trans($key, $parameters, null, $locale);
    }

    /**
     * Liefert den Produktnamen ohne Längen-Zusatz und merkt ihn beim ersten Mal im Payload.
     */
    private function baseLabel(LineItem $lineItem): string
    {
        $stored = $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_BASE_LABEL);
        if (\is_string($stored) && $stored !== '') {
            return $stored;
        }

        $label = $lineItem->getLabel() ?? '';
        if ($label === '') {
            return '';
        }

        $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_BASE_LABEL, $label);

        return $label;
    }

    /**
     * @param non-empty-list<array{length: int, count: int}> $splitSummary
     */
    private function formatPieces(array $splitSummary, string $locale): string
    {
        $parts = \array_map(
            fn (array $group): string => $this->trans('rc-dynamic-price.labelPiece', [
                '%count%' => (string) $group['count'],
                '%length%' => $this->formatMm($group['length']),
            ], $locale),
            $splitSummary,
        );

        return \implode(' + ', $parts);
    }

    /**
     * Mit Tausenderpunkt und ohne Nachkommastellen in jeder Sprache; der Name wird gelesen, nicht
     * weiterverrechnet.
     */
    private function formatMm(int $mm): string
    {
        return \number_format($mm, 0, ',', '.');
    }

    /**
     * Liefert die abgerechneten Längen der Teilstücke: erst auf die Mindestlänge anheben, dann
     * einzeln aufrunden.
     *
     * Nur hier wirkt die Mindestlänge. Sie ist eine Abrechnungsregel („ein Zuschnitt kostet
     * mindestens X"), keine Fertigungsregel: Geschnitten wird das Reststück in seiner tatsächlichen
     * Länge (5.000 + 100 mm für eine Bestellung von 5.100 mm), abgerechnet mit der Mindestlänge
     * (5.000 + 1.000 mm = 6.000 mm). Der Kunde bekommt, was er bestellt hat, und zahlt den
     * Mindestzuschnitt.
     *
     * Positionen aus der Zeit vor `rc_min_billing` tragen die Anhebung schon in ihren Schnittlängen;
     * ihr Preis bleibt unverändert.
     *
     * @param non-empty-list<int> $cutPieces
     *
     * @return non-empty-list<int>
     */
    private function billedPieces(LineItem $lineItem, array $cutPieces, mixed $roundingMode): array
    {
        $billed = $this->raiseToMinimum($lineItem, $cutPieces);

        if (!\is_string($roundingMode)) {
            return $billed;
        }

        return \array_map(
            fn (int $piece): int => $this->meterProductHelper->roundUp($piece, $roundingMode),
            $billed,
        );
    }

    /**
     * Die Schnittlängen der Position, also das, was die Fertigung schneidet.
     *
     * Positionen aus Warenkörben, die vor der Umstellung auf Auftrags-Positionen angelegt wurden,
     * tragen keine Teilstück-Liste. Für sie gilt die Eingabelänge als einziges Teilstück.
     *
     * @return non-empty-list<int>
     */
    private function cutPieces(LineItem $lineItem, int $mmLength): array
    {
        $pieces = $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES);

        $cutLengths = [];
        if (\is_array($pieces)) {
            foreach ($pieces as $piece) {
                if (\is_int($piece) && $piece > 0) {
                    $cutLengths[] = $piece;
                }
            }
        }

        if ($cutLengths === []) {
            return [$mmLength];
        }

        return $cutLengths;
    }

    /**
     * Hebt Teilstücke unter der Mindestlänge auf diese an, nur für die Abrechnung.
     *
     * @param non-empty-list<int> $cutLengths
     *
     * @return non-empty-list<int>
     */
    private function raiseToMinimum(LineItem $lineItem, array $cutLengths): array
    {
        if ($lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_BILLING) !== true) {
            return $cutLengths;
        }

        $minLength = $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH);
        if (!\is_int($minLength) || $minLength <= 0) {
            return $cutLengths;
        }

        return \array_map(
            static fn (int $piece): int => \max($piece, $minLength),
            $cutLengths,
        );
    }

    /**
     * Fasst gleich lange Teilstücke zu Anzeigegruppen zusammen: `[5000, 1700, 1700]` wird zu
     * `[['length' => 5000, 'count' => 1], ['length' => 1700, 'count' => 2]]`.
     *
     * Die Schnittfolge bleibt erhalten: Beim max_rest-Split steht das Reststück hinten, und so steht
     * es auch auf dem Lieferschein.
     *
     * @param non-empty-list<int> $cutPieces
     *
     * @return non-empty-list<array{length: int, count: int}>
     */
    private function groupPieces(array $cutPieces): array
    {
        $groups = [];

        foreach ($cutPieces as $piece) {
            $last = \array_key_last($groups);

            if ($last !== null && $groups[$last]['length'] === $piece) {
                ++$groups[$last]['count'];
                continue;
            }

            $groups[] = ['length' => $piece, 'count' => 1];
        }

        /** @var non-empty-list<array{length: int, count: int}> $groups */
        return $groups;
    }

    /**
     * Meldet eine Meter-Position, deren Preis nicht ermittelt werden kann, als blockierenden Fehler.
     *
     * Die Position bleibt im Warenkorb sichtbar; verschwände sie, stünde der Kunde ratlos da. Sie
     * behält den unberechneten Grundpreis, und `blockOrder()` verhindert eine Bestellung zu diesem
     * falschen Preis.
     */
    private function rejectLineItem(Cart $toCalculate, LineItem $lineItem, string $reason): void
    {
        $toCalculate->addErrors(new MeterPriceError(
            $lineItem->getId(),
            $reason,
            $lineItem->getLabel() ?? '',
        ));

        $this->metrics->increment(DynamicPriceConstants::METRIC_CART_ITEM_REJECTED, [
            'reason' => $reason,
        ]);
    }
}
