<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Enum;

/**
 * Was mit einem Zuschnitt geschieht, der länger ist als die eingestellte Höchstlänge je Teilstück.
 */
enum SplitMode: string
{
    // Die Gesamtlänge wird gleichmäßig auf ceil(total / maxPiece) Teilstücke verteilt.
    case Equal = 'equal';

    // Volle maxPiece-Stücke plus Rest. Ein Rest unter der Mindestlänge wird in seiner Länge
    // geschnitten und nur in der Abrechnung angehoben.
    case MaxRest = 'max_rest';

    // Keine Teilung; der Kunde bekommt einen Hinweis und teilt selbst auf.
    case Hint = 'hint';

    /** Duldsame Wandlung aus Zusatzfeld-Werten; unbekannte und leere Werte ergeben `null`. */
    public static function tryFromString(mixed $value): ?self
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
