<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;

interface LengthSplitterInterface
{
    /**
     * Teilt die Gesamtlänge gemäß Modus in ein oder mehrere Teilstücke auf.
     * Rückgabe ist garantiert nicht leer; jedes Element > 0.
     *
     * @return non-empty-list<int>
     */
    public function split(int $totalMm, int $maxPieceMm, int $minPieceMm, ?SplitMode $mode): array;
}
