<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;

/**
 * Entscheidet, ob ein Produkt den Meterpreis bekommt und mit welchen Werten. Jeder Wert kann am
 * Produkt, an einer Kategorie seiner Kette oder in der Grundeinstellung stehen.
 */
interface MeterConfigResolverInterface
{
    /**
     * Löst die Meterpreis-Einstellungen für ein Produkt auf. Vorrang: Produkt, dann die
     * Kategoriekette von der Primärkategorie zur Wurzel, dann die Grundeinstellung, dann die Vorgabe.
     *
     * Ob der Meterpreis gilt:
     * - Produkt `off`: nie.
     * - Produkt `on`: immer.
     * - Produkt `inherit`: die nächste Kategorie mit `on` oder `off` entscheidet.
     * - Steht überall `inherit`: an, wenn `applyToAllProducts` gesetzt ist, sonst aus.
     */
    public function resolveForProduct(ProductEntity $product, string $salesChannelId, Context $context): ResolvedMeterConfig;

    /**
     * Dieselbe Entscheidung ohne Datenbankzugriff, damit sie ohne Kern prüfbar ist. Die
     * Kategoriekette muss schon sortiert sein, die nächste zuerst.
     *
     * @param array<string, mixed>                                          $productFields
     * @param list<array{id: string, customFields: array<string, mixed>}>   $categoryChain
     */
    public function resolve(array $productFields, array $categoryChain, string $salesChannelId): ResolvedMeterConfig;
}
