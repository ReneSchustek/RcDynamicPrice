<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

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

    public function split(int $totalMm, int $maxPieceMm, int $minPieceMm, ?SplitMode $mode): array
    {
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
            SplitMode::Equal => $this->splitEqual($totalMm, $maxPieceMm),
            SplitMode::MaxRest => $this->splitMaxRest($totalMm, $maxPieceMm, $minPieceMm),
        };
    }

    /**
     * Gleichmäßige Teilung: minimale Anzahl Teile, bei der jedes Teil <= maxPiece bleibt.
     * Jedes Teil erhält dieselbe (aufgerundete) Länge — kleine Überzahlung bei nicht teilbaren Werten.
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
     * Max-Rest-Teilung: volle maxPiece-Stücke plus Rest.
     * Wenn der Rest unter die Mindestlänge fällt, wird er auf die Mindestlänge angehoben,
     * weil ein kleinerer Zuschnitt immer möglich ist.
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
