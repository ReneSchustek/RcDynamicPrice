<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Content\Product\ProductEntity;

/**
 * Liest die Zusatzfelder eines Produkts auf demselben Weg für alle Resolver.
 */
final class ProductCustomFields
{
    /**
     * Die übersetzten Werte samt Rückfall auf die Standardsprache, wie die Vorlage sie als `translated` liest; ohne
     * Übersetzung die Werte der Kontextsprache. Ein Shop mit zweiter Sprache, in der niemand die Felder pflegt, behält
     * so die Einstellungen der Standardsprache.
     *
     * @return array<string, mixed>
     */
    public static function of(ProductEntity $product): array
    {
        $fields = $product->getTranslation('customFields') ?? $product->getCustomFields();

        return \is_array($fields) ? $fields : [];
    }

    /**
     * Ein Haken ist gesetzt, wenn er wahr ist; über die Schnittstelle oder die Konsole kann er auch als `1` oder `"1"`
     * ankommen.
     */
    public static function isChecked(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
