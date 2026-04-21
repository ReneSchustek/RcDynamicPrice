<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;

/**
 * Berechnet die Aufteilung einer Gesamtlänge in Teilstücke.
 * Zustandslos und rein funktional — gesamte Geschäftslogik des Splittings liegt hier.
 */
final class LengthSplitter implements LengthSplitterInterface
{
    public function split(int $totalMm, int $maxPieceMm, int $minPieceMm, ?SplitMode $mode): array
    {
        if ($totalMm <= 0) {
            throw new \InvalidArgumentException(
                \sprintf('Gesamtlaenge muss positiv sein, erhalten: %d', $totalMm)
            );
        }

        // Kein Split, wenn Modus unbekannt, deaktiviert oder die Stueckelungsgrenze nicht greift
        if ($mode === null || $mode === SplitMode::Hint || $maxPieceMm <= 0 || $totalMm <= $maxPieceMm) {
            return [$totalMm];
        }

        return match ($mode) {
            SplitMode::Equal => $this->splitEqual($totalMm, $maxPieceMm),
            SplitMode::MaxRest => $this->splitMaxRest($totalMm, $maxPieceMm, $minPieceMm),
        };
    }

    /**
     * Gleichmaessige Teilung: minimale Anzahl Teile, bei der jedes Teil <= maxPiece bleibt.
     * Jedes Teil erhaelt dieselbe (aufgerundete) Laenge — kleine Ueberzahlung bei nicht teilbaren Werten.
     *
     * @return non-empty-list<int>
     */
    private function splitEqual(int $total, int $max): array
    {
        // Bei $total > $max ist $pieceCount >= 2; der Guard im Aufrufer garantiert diese Vorbedingung.
        $pieceCount = \max(1, (int) \ceil($total / $max));
        $pieceLength = (int) \ceil($total / $pieceCount);

        /** @var non-empty-list<int> $pieces */
        $pieces = \array_fill(0, $pieceCount, $pieceLength);

        return $pieces;
    }

    /**
     * Max-Rest-Teilung: volle maxPiece-Stuecke plus Rest.
     * Wenn der Rest unter die Mindestlaenge faellt, wird er auf die Mindestlaenge angehoben,
     * weil ein kleinerer Zuschnitt immer moeglich ist.
     *
     * @return non-empty-list<int>
     */
    private function splitMaxRest(int $total, int $max, int $min): array
    {
        // Vorbedingung $total > $max durch den Aufrufer: intdiv ergibt garantiert >= 1
        $fullPieces = \intdiv($total, $max);
        $remainder = $total - $fullPieces * $max;

        /** @var non-empty-list<int> $pieces */
        $pieces = \array_fill(0, $fullPieces, $max);

        if ($remainder > 0) {
            $pieces[] = \max($remainder, \max($min, 1));
        }

        return $pieces;
    }
}
