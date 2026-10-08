<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Content\Product\ProductEntity;

/**
 * Die Kategorie, von der ein Produkt seine Einstellungen erbt: Meterpreis wie geführte Auswahl.
 *
 * Bei gleichem Produkt kommt immer dieselbe Kategorie heraus, in dieser Reihenfolge:
 *
 * 1. Die vom Händler gepflegte Hauptkategorie (`mainCategories`) des Verkaufskanals. Das ist die
 *    ausdrückliche Absicht und je Kanal eindeutig.
 * 2. Sonst die kleinste Kategorie-Kennung (sortiert) statt der ersten beliebigen. Die DAL garantiert
 *    keine stabile Reihenfolge; ohne Sortierung erbten Produkte in mehreren Kategorien je nach
 *    Ladereihenfolge verschiedene Einstellungen und damit schwankende Preise.
 *
 * Eine Stelle für beide Funktionen: Liefen sie auseinander, hätte derselbe Artikel für den Preis
 * eine andere Kategorie als für die Auswahl.
 */
final class PrimaryCategory
{
    public static function idFor(ProductEntity $product, string $salesChannelId): ?string
    {
        $mainCategoryId = self::mainCategoryIdForChannel($product, $salesChannelId);
        if ($mainCategoryId !== null) {
            return $mainCategoryId;
        }

        $ids = $product->getCategoryIds();
        if ($ids === null || $ids === []) {
            $ids = $product->getCategories()?->getIds() ?? [];
        }

        if ($ids === []) {
            return null;
        }

        $sorted = array_values($ids);
        sort($sorted);

        return $sorted[0];
    }

    /**
     * Die Hauptkategorie für den Kanal, sofern die Zuordnung `mainCategories` geladen und für ihn
     * gesetzt ist.
     */
    private static function mainCategoryIdForChannel(ProductEntity $product, string $salesChannelId): ?string
    {
        foreach ($product->getMainCategories() ?? [] as $mainCategory) {
            if ($mainCategory->getSalesChannelId() === $salesChannelId) {
                return $mainCategory->getCategoryId();
            }
        }

        return null;
    }
}
