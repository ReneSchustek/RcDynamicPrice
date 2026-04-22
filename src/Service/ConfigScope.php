<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

/**
 * Quelle, aus der ein einzelnes Feld im aufgeloesten Meterpreis-Config stammt.
 * Dient der Nachvollziehbarkeit (Logging, Debugging) — keine Geschaeftslogik.
 */
enum ConfigScope: string
{
    case Product = 'product';
    case Category = 'category';
    case Global = 'global';
    case Default = 'default';
}
