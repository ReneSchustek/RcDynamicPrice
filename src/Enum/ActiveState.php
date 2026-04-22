<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Enum;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;

enum ActiveState: string
{
    case Inherit = DynamicPriceConstants::ACTIVE_INHERIT;
    case On = DynamicPriceConstants::ACTIVE_ON;
    case Off = DynamicPriceConstants::ACTIVE_OFF;

    /**
     * Wandelt Custom-Field-Werte tolerant in einen Zustand:
     * - Strings `inherit` / `on` / `off` werden direkt gemappt
     * - `bool true` -> `On` (BC: vor der Migration auf Tri-State lagen Werte als bool vor)
     * - `bool false` / `null` / leerer String / unbekannte Werte -> `Inherit` (Default: nicht entschieden)
     */
    public static function fromMixed(mixed $value): self
    {
        if ($value === true) {
            return self::On;
        }

        if ($value === false || $value === null) {
            return self::Inherit;
        }

        if (\is_string($value)) {
            $normalized = strtolower(trim($value));

            return self::tryFrom($normalized) ?? self::Inherit;
        }

        return self::Inherit;
    }
}
