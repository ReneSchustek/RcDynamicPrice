<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Enum;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;

/**
 * Der Schalter des Meterpreises an Produkt und Kategorie: erben, an oder aus. Drei Werte statt
 * eines Hakens, weil ein Produkt eine eingeschaltete Kategorie auch abwählen können muss.
 */
enum ActiveState: string
{
    case Inherit = DynamicPriceConstants::ACTIVE_INHERIT;
    case On = DynamicPriceConstants::ACTIVE_ON;
    case Off = DynamicPriceConstants::ACTIVE_OFF;

    /**
     * Wandelt Zusatzfeld-Werte duldsam in einen Zustand:
     * - `inherit`, `on` und `off` werden direkt übernommen.
     * - `true` gilt als `On`: Felder aus der Zeit des Hakens tragen noch einen Wahrheitswert.
     * - `false`, `null`, ein leerer Text und Unbekanntes gelten als `Inherit`, also nicht entschieden.
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
