<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;

interface MeterProductHelperInterface
{
    /**
     * Laedt das Produkt inklusive der Kategorie-Assoziation, damit der Resolver
     * die Primaerkategorie-Kette erreicht. `null`, wenn das Produkt fehlt.
     */
    public function loadProduct(string $productId, Context $context): ?ProductEntity;

    /**
     * Rundet Millimeter auf die naechste volle Einheit gemaess Modus auf.
     * Exakte Vielfache bleiben unveraendert. Unbekannter Modus = keine Rundung.
     */
    public function roundUp(int $mm, string $mode): int;
}
