<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;

/**
 * Produktladung und Rundung für den Meterpreis, an einer Stelle, damit Warenkorb und Produktseite
 * gleich rechnen.
 */
interface MeterProductHelperInterface
{
    /**
     * Lädt das Produkt samt Kategorien und Hauptkategorien, damit der Resolver die Kategoriekette
     * erreicht. `null`, wenn das Produkt fehlt oder die Kennung keine ist.
     */
    public function loadProduct(string $productId, Context $context): ?ProductEntity;

    /**
     * Rundet Millimeter auf die nächste volle Stufe des Modus auf. Exakte Vielfache bleiben, ein
     * unbekannter Modus rundet nicht.
     */
    public function roundUp(int $mm, string $mode): int;
}
