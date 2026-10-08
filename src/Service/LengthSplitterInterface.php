<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;

/**
 * Die Aufteilung zu langer Zuschnitte in Teilstücke, getrennt von Warenkorb und Request, damit die
 * Rechnung für sich prüfbar ist.
 */
interface LengthSplitterInterface
{
    /**
     * Teilt die Gesamtlänge gemäß Modus in ein oder mehrere Teilstücke auf. Die Rückgabe ist nie
     * leer, jedes Element größer null.
     *
     * Geliefert werden Schnittlängen, also das, was die Fertigung zu schneiden hat. Die Mindestlänge
     * kommt hier nicht vor: Sie ist eine Abrechnungsregel („ein Zuschnitt kostet mindestens X") und
     * wirkt im DynamicPriceProcessor. Wer 5.100 mm bestellt, bekommt 5.000 + 100 mm und nicht
     * 5.000 + 1.000 mm.
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
