<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;

interface LengthSplitterInterface
{
    /**
     * Teilt die Gesamtlänge gemäß Modus in ein oder mehrere Teilstücke auf.
     * Rückgabe ist garantiert nicht leer; jedes Element > 0.
     *
     * Geliefert werden **Schnittlängen** — das, was die Fertigung zu schneiden hat. Die
     * Mindestlänge kommt hier nicht vor: Sie ist eine Abrechnungsregel („ein Zuschnitt kostet
     * mindestens X"), keine Fertigungsregel, und wird im DynamicPriceProcessor angewandt. Wer
     * 5.100 mm bestellt, bekommt 5.000 + 100 mm — nicht 5.000 + 1.000 mm.
     *
     * @param string $equalBilling Schnittlänge im equal-Modus (`cut_length`/`exact`)
     *
     * @return non-empty-list<int>
     */
    public function split(
        int $totalMm,
        int $maxPieceMm,
        ?SplitMode $mode,
        string $equalBilling = DynamicPriceConstants::EQUAL_BILLING_CUT_LENGTH,
    ): array;
}
