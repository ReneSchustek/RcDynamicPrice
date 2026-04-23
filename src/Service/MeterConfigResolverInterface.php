<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;

interface MeterConfigResolverInterface
{
    /**
     * Löst die Meterpreis-Konfiguration für ein Produkt auf.
     * Priorisierung: Produkt > Kategorie-Tree (Primärkategorie -> Wurzel) > Plugin-Global > Default.
     *
     * Active-Logik:
     * - Produkt `off`  -> immer deaktiv.
     * - Produkt `on`   -> aktiv, Produktwerte dominieren numerische Felder.
     * - Produkt `inherit` + Kategorie `on`/`off` (nächstgelegene gewinnt) -> diese entscheidet.
     * - Produkt und Kategorie-Kette `inherit` + `applyToAllProducts = true` -> aktiv aus Plugin-Global.
     * - Sonst deaktiv.
     */
    public function resolveForProduct(ProductEntity $product, string $salesChannelId, Context $context): ResolvedMeterConfig;

    /**
     * Testbare Reinform ohne DAL-Zugriff.
     * Die Kategorie-Kette muss bereits sortiert sein (nächstgelegene zuerst).
     *
     * @param array<string, mixed>                                          $productFields
     * @param list<array{id: string, customFields: array<string, mixed>}>   $categoryChain
     */
    public function resolve(array $productFields, array $categoryChain, string $salesChannelId): ResolvedMeterConfig;
}
