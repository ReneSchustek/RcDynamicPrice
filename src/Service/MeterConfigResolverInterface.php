<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;

interface MeterConfigResolverInterface
{
    /**
     * Loest die Meterpreis-Konfiguration fuer ein Produkt auf.
     * Priorisierung: Produkt > Kategorie-Tree (Primaerkategorie -> Wurzel) > Plugin-Global > Default.
     *
     * Active-Logik:
     * - Produkt `off`  -> immer deaktiv.
     * - Produkt `on`   -> aktiv, Produktwerte dominieren numerische Felder.
     * - Produkt `inherit` + Kategorie `on`/`off` (naechstgelegene gewinnt) -> diese entscheidet.
     * - Produkt und Kategorie-Kette `inherit` + `applyToAllProducts = true` -> aktiv aus Plugin-Global.
     * - Sonst deaktiv.
     */
    public function resolveForProduct(ProductEntity $product, string $salesChannelId, Context $context): ResolvedMeterConfig;

    /**
     * Testbare Reinform ohne DAL-Zugriff.
     * Die Kategorie-Kette muss bereits sortiert sein (naechstgelegene zuerst).
     *
     * @param array<string, mixed>                                          $productFields
     * @param list<array{id: string, customFields: array<string, mixed>}>   $categoryChain
     */
    public function resolve(array $productFields, array $categoryChain, string $salesChannelId): ResolvedMeterConfig;
}
