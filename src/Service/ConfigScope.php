<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

/**
 * Woher ein einzelner Wert der aufgelösten Meterpreis-Einstellungen stammt. Er steht im Protokoll,
 * damit sich bei „warum kostet das so viel?" sehen lässt, welche Ebene gewonnen hat; entschieden wird
 * damit nichts.
 */
enum ConfigScope: string
{
    case Product = 'product';
    case Category = 'category';
    case Global = 'global';
    case Default = 'default';
}
