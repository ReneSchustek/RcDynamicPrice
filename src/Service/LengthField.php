<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

/**
 * Findet unter den Kundeneingaben von TmmsProductCustomerInputs das Feld, in das der Kunde seine Länge
 * schreibt.
 *
 * Ein Artikel kann mehrere Eingaben tragen, und die Länge steht nicht immer an erster Stelle; andere
 * Artikel haben an erster Stelle „Wandabstand" oder „Höhe des Gartentores". Erkannt wird das
 * Längenfeld daran, dass Beschriftung oder Platzhalter „Länge" nennen; im Bestand trifft das genau die
 * Felder „… die nächst größere Länge aus" mit dem Platzhalter „Gewünschte Länge(n) in mm". Ohne
 * Längenfeld bekommt ein Artikel keinen Längenschalter, auch wenn seine Kategorie ihn einschaltet.
 */
final class LengthField
{
    // TMMS nummeriert seine Felder ab 1; mehr als zehn trägt kein Artikel im Bestand (höchstens vier).
    private const MAX_FIELDS = 10;

    /**
     * @param array<string, mixed> $productFields
     *
     * @return int|null die Nummer des Feldes, oder `null`, wenn der Artikel keines hat
     */
    public static function numberIn(array $productFields): ?int
    {
        for ($number = 1; $number <= self::MAX_FIELDS; ++$number) {
            $active = $productFields["tmms_customer_input_{$number}_active"] ?? null;
            if ($active !== true && $active !== 1 && $active !== '1') {
                continue;
            }

            foreach (['title', 'placeholder'] as $part) {
                $text = $productFields["tmms_customer_input_{$number}_{$part}"] ?? null;
                if (\is_string($text) && mb_stripos($text, 'länge') !== false) {
                    return $number;
                }
            }
        }

        return null;
    }
}
