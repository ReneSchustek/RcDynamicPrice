<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Enum;

enum SplitMode: string
{
    // Gesamtlänge wird gleichmäßig auf ceil(total/maxPiece) Teilstücke verteilt
    case Equal = 'equal';

    // Ganze maxPiece-Stücke plus Rest (Rest unter minLength wird auf minLength angehoben)
    case MaxRest = 'max_rest';

    // Kein Auto-Split, Kunde bekommt Hinweistext und teilt selbst auf
    case Hint = 'hint';

    /** Tolerante Wandlung aus Custom-Field-Werten; unbekannte/leere Werte => null. */
    public static function tryFromString(mixed $value): ?self
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
