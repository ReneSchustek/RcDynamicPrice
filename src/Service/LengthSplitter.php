<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Exception\DynamicPriceException;

/**
 * Berechnet die Aufteilung einer Gesamtlänge in Teilstücke.
 * Zustandslos und rein funktional — gesamte Geschäftslogik des Splittings liegt hier.
 *
 * Vorbedingung: $totalMm muss vom Aufrufer auf eine realistische Obergrenze begrenzt sein.
 * Der Service akzeptiert bis 1.000.000 mm (1 km); darüber wird eine Exception geworfen,
 * um Memory-Exhaustion durch absurd viele Teilstücke auszuschließen.
 */
final class LengthSplitter implements LengthSplitterInterface
{
    /** Obergrenze für die Gesamtlänge (1 km). Schützt vor absurden Array-Allokationen. */
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

        // Kein Split, wenn Modus unbekannt, deaktiviert oder die Stückelungsgrenze nicht greift
        if ($mode === null || $mode === SplitMode::Hint || $maxPieceMm <= 0 || $totalMm <= $maxPieceMm) {
            return [$totalMm];
        }

        return match ($mode) {
            SplitMode::Equal => $this->splitEqual($totalMm, $maxPieceMm, $equalBilling),
            SplitMode::MaxRest => $this->splitMaxRest($totalMm, $maxPieceMm),
        };
    }

    /**
     * Gleichmäßige Teilung: minimale Anzahl Teile, bei der jedes Teil <= maxPiece bleibt.
     * Die kleinste Stückzahl liefert zugleich die größten und damit min-freundlichsten Teile.
     *
     * Zwei Varianten (Händler-Konfiguration, Geld-relevant):
     *  - `cut_length` (Standard): jedes Teil erhält dieselbe **aufgerundete** Länge
     *    (`ceil(total / pieceCount)`). Die Stück-Summe kann die Eingabe übersteigen — der
     *    Shop schneidet und berechnet die gleichlangen Stücke. So war das bisherige Verhalten.
     *  - `exact`: die Länge wird exakt verteilt (Summe der Teile == $total); die ersten
     *    $remainder Teile sind 1 mm länger. Der Kunde zahlt genau die bestellte Länge.
     *
     * Die Mindestlänge kommt hier nicht vor — ein Teilstück unter ihr wird geschnitten wie
     * berechnet und lediglich mit der Mindestlänge **abgerechnet** (im Processor).
     *
     * @return non-empty-list<int>
     */
    private function splitEqual(int $total, int $max, string $equalBilling): array
    {
        // Bei $total > $max ist $pieceCount >= 2; der Guard im Aufrufer garantiert diese Vorbedingung.
        $pieceCount = \max(1, (int) \ceil($total / $max));

        if ($equalBilling === DynamicPriceConstants::EQUAL_BILLING_EXACT) {
            // Exakte Verteilung: Summe der Teile == $total.
            $base = \intdiv($total, $pieceCount);
            $remainder = $total - $base * $pieceCount;
            $pieces = [];
            for ($i = 0; $i < $pieceCount; ++$i) {
                $pieces[] = $i < $remainder ? $base + 1 : $base;
            }
        } else {
            // Schnittlänge: jedes Teil auf dieselbe aufgerundete Länge.
            $pieceLength = (int) \ceil($total / $pieceCount);
            $pieces = \array_fill(0, $pieceCount, $pieceLength);
        }

        /** @var non-empty-list<int> $pieces */
        return $pieces;
    }

    /**
     * Max-Rest-Teilung: volle maxPiece-Stücke plus Rest.
     *
     * Das Reststück behält seine tatsächliche Länge, auch wenn es unter der Mindestlänge liegt.
     * Die Mindestlänge ist eine **Abrechnungsregel** („ein Zuschnitt kostet mindestens $min"), keine
     * Fertigungsregel: Wer 5.100 mm bestellt, soll 5.000 + 100 mm bekommen und nicht 5.000 + 1.000 mm.
     * Die Anhebung auf die Mindestlänge passiert im Processor, wo der Preis entsteht.
     *
     * @return non-empty-list<int>
     */
    private function splitMaxRest(int $total, int $max): array
    {
        // Vorbedingung $total > $max durch den Aufrufer: intdiv ergibt garantiert >= 1
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
