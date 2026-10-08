<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Exception\DynamicPriceException;

/**
 * Berechnet die Aufteilung einer Gesamtlänge in Teilstücke. Zustandslos; die ganze Regel der Teilung
 * steht hier und nirgends sonst.
 *
 * Vorbedingung: $totalMm muss vom Aufrufer auf eine realistische Obergrenze begrenzt sein; davor
 * steht die Höchstlänge der Einstellungen (Vorgabe 10 m). Der Service akzeptiert bis 1.000.000 mm
 * (1 km) und wirft darüber eine Exception.
 */
final class LengthSplitter implements LengthSplitterInterface
{
    /**
     * Obergrenze für die Gesamtlänge (1 km).
     *
     * Die Rechnung selbst ist auch an der Grenze harmlos: 1 km in 1-mm-Stücken, also eine Million
     * Teilstücke, braucht gemessen rund 13 ms und 4 MB (`benchmarks/LengthSplitterBench.php`). Teuer
     * wäre, was danach kommt: Die Teilstücke stehen in der Warenkorbposition, werden mit ihr
     * gespeichert und im Bestellvorgang angezeigt. Davor schützt die Grenze.
     */
    public const MAX_TOTAL_MM = 1_000_000;

    /**
     * @param string $equalBilling Schnittlänge im equal-Modus: `cut_length` (jedes Teil
     *                             aufgerundet, Stück-Summe darf die Eingabe übersteigen)
     *                             oder `exact` (Summe == Eingabe).
     */
    public function split(
        int $totalMm,
        int $maxPieceMm,
        ?SplitMode $mode,
        string $equalBilling = DynamicPriceConstants::EQUAL_BILLING_CUT_LENGTH,
    ): array {
        if ($totalMm <= 0) {
            throw DynamicPriceException::invalidTotalLength($totalMm);
        }

        if ($totalMm > self::MAX_TOTAL_MM) {
            throw DynamicPriceException::totalLengthExceedsMaximum($totalMm, self::MAX_TOTAL_MM);
        }

        // Keine Teilung ohne Modus, beim reinen Hinweis oder wenn die Länge unter der Grenze bleibt.
        if ($mode === null || $mode === SplitMode::Hint || $maxPieceMm <= 0 || $totalMm <= $maxPieceMm) {
            return [$totalMm];
        }

        return match ($mode) {
            SplitMode::Equal => $this->splitEqual($totalMm, $maxPieceMm, $equalBilling),
            SplitMode::MaxRest => $this->splitMaxRest($totalMm, $maxPieceMm),
        };
    }

    /**
     * Gleichmäßige Teilung: die kleinste Zahl an Teilen, bei der jedes höchstens maxPiece lang ist.
     * Die kleinste Stückzahl ergibt zugleich die längsten Teile, die am seltensten unter die
     * Mindestlänge fallen.
     *
     * Zwei Varianten, vom Händler eingestellt, weil sie den Preis bestimmen:
     *  - `cut_length` (Vorgabe): Jedes Teil bekommt dieselbe aufgerundete Länge
     *    (`ceil(total / pieceCount)`). Die Summe kann die Eingabe übersteigen; der Shop schneidet
     *    und berechnet die gleich langen Stücke.
     *  - `exact`: Die Länge wird genau verteilt (Summe gleich $total), die ersten $remainder Teile
     *    sind 1 mm länger. Der Kunde zahlt genau die bestellte Länge.
     *
     * Die Mindestlänge kommt hier nicht vor. Ein Teilstück darunter wird geschnitten wie berechnet
     * und erst im Processor mit der Mindestlänge abgerechnet.
     *
     * @return non-empty-list<int>
     */
    private function splitEqual(int $total, int $max, string $equalBilling): array
    {
        // Der Aufrufer teilt nur bei $total > $max, also ist $pieceCount mindestens 2.
        $pieceCount = \max(1, (int) \ceil($total / $max));

        if ($equalBilling === DynamicPriceConstants::EQUAL_BILLING_EXACT) {
            $base = \intdiv($total, $pieceCount);
            $remainder = $total - $base * $pieceCount;
            $pieces = [];
            for ($i = 0; $i < $pieceCount; ++$i) {
                $pieces[] = $i < $remainder ? $base + 1 : $base;
            }
        } else {
            $pieceLength = (int) \ceil($total / $pieceCount);
            $pieces = \array_fill(0, $pieceCount, $pieceLength);
        }

        /** @var non-empty-list<int> $pieces */
        return $pieces;
    }

    /**
     * Max-Rest-Teilung: volle maxPiece-Stücke plus Rest.
     *
     * Das Reststück behält seine tatsächliche Länge, auch unter der Mindestlänge. Die Mindestlänge ist
     * eine Abrechnungsregel („ein Zuschnitt kostet mindestens $min"), keine Fertigungsregel: Wer
     * 5.100 mm bestellt, bekommt 5.000 + 100 mm und nicht 5.000 + 1.000 mm. Angehoben wird erst im
     * Processor, wo der Preis entsteht.
     *
     * @return non-empty-list<int>
     */
    private function splitMaxRest(int $total, int $max): array
    {
        // Der Aufrufer teilt nur bei $total > $max; intdiv ergibt also mindestens 1.
        $fullPieces = \intdiv($total, $max);
        $remainder = $total - $fullPieces * $max;

        /** @var non-empty-list<int> $pieces */
        $pieces = \array_fill(0, $fullPieces, $max);

        if ($remainder > 0) {
            $pieces[] = $remainder;
        }

        return $pieces;
    }
}
