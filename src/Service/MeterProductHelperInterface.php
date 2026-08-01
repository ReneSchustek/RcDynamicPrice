<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;

interface MeterProductHelperInterface
{
    /**
     * Lädt das Produkt inklusive der Kategorie-Assoziation, damit der Resolver
     * die Primärkategorie-Kette erreicht. `null`, wenn das Produkt fehlt.
     */
    public function loadProduct(string $productId, Context $context): ?ProductEntity;

    /**
     * Rundet Millimeter auf die nächste volle Einheit gemäß Modus auf.
     * Exakte Vielfache bleiben unverändert. Unbekannter Modus = keine Rundung.
     */
    public function roundUp(int $mm, string $mode): int;
}
