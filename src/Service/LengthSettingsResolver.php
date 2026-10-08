<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Entscheidet, welches Längen-Verhalten ein Artikel bekommt: geführte Auswahl, Längenschalter,
 * „Größen nur über die Länge", und mit welchen Längengruppen.
 *
 * Die drei Haken gelten, wenn sie am Produkt oder an irgendeiner Kategorie seiner Kette gesetzt sind.
 * Ein Haken kennt nur „gesetzt" und „nicht gesetzt"; ein nicht gesetzter Haken an einer näheren
 * Kategorie hebt einen gesetzten weiter oben nicht auf. So genügt eine Einstellung an einer obersten
 * Kategorie, auch wenn ein Artikel in mehreren Unterkategorien hängt.
 *
 * Die Längengruppen werden über die ganze Kette gesammelt, die nächste Stelle zuerst: erst das Produkt,
 * dann die Kategorien von der eigenen zur Wurzel. Welche davon gilt, entscheidet die Produktseite: die
 * erste, die der Artikel hat. Nähme man nur die nächste Stelle, verdeckte eine Unterkategorie mit
 * „Länge" die „Maße" ihrer Elternkategorie, und ein Relinggeländer unter „Balkongeländer" bekäme keine
 * Längengruppe.
 */
final class LengthSettingsResolver
{
    public function __construct(
        private readonly CategoryChainLoaderInterface $categoryChainLoader,
    ) {
    }

    public function forProduct(ProductEntity $product, string $salesChannelId, Context $context): LengthSettings
    {
        /** @var array<string, mixed> $productFields */
        $productFields = $product->getTranslation('customFields') ?? $product->getCustomFields() ?? [];

        $categoryId = PrimaryCategory::idFor($product, $salesChannelId);
        $categoryChain = $categoryId === null ? [] : $this->categoryChainLoader->loadChain($categoryId, $context);

        return self::resolve($productFields, $categoryChain);
    }

    /**
     * @param array<string, mixed>                                        $productFields
     * @param list<array{id: string, customFields: array<string, mixed>}> $categoryChain von der eigenen
     *                                                                                   Kategorie zur Wurzel
     */
    public static function resolve(array $productFields, array $categoryChain): LengthSettings
    {
        $guided = self::isSet($productFields, DynamicPriceConstants::FIELD_GUIDED_SELECTION);
        $switch = self::isSet($productFields, DynamicPriceConstants::FIELD_LENGTH_VARIANT_SWITCH);
        $lengthOnly = self::isSet($productFields, DynamicPriceConstants::FIELD_LENGTH_ONLY);
        $groups = self::groupIds($productFields[DynamicPriceConstants::FIELD_LENGTH_GROUPS] ?? null);

        foreach ($categoryChain as $entry) {
            $fields = $entry['customFields'];
            $guided = $guided || self::isSet($fields, DynamicPriceConstants::CAT_FIELD_GUIDED_SELECTION);
            $switch = $switch || self::isSet($fields, DynamicPriceConstants::CAT_FIELD_LENGTH_VARIANT_SWITCH);
            $lengthOnly = $lengthOnly || self::isSet($fields, DynamicPriceConstants::CAT_FIELD_LENGTH_ONLY);

            $groups = [...$groups, ...self::groupIds($fields[DynamicPriceConstants::CAT_FIELD_LENGTH_GROUPS] ?? null)];
        }

        $groups = array_values(array_unique($groups));

        // Ohne Längenfeld gibt es nichts, was der Schalter lesen könnte; eine Einstellung an der Kategorie
        // trifft so nur die Artikel, die eines haben, und nie ein Feld wie „Wandabstand".
        $field = LengthField::numberIn($productFields);

        return new LengthSettings($guided, $switch && $field !== null, $lengthOnly && $field !== null, $groups, $field);
    }

    /**
     * Ein Haken ist gesetzt, wenn er wahr ist; über die Schnittstelle oder die Konsole kann er auch als
     * `1` oder `"1"` ankommen.
     *
     * @param array<string, mixed> $fields
     */
    private static function isSet(array $fields, string $key): bool
    {
        $value = $fields[$key] ?? null;

        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * @return list<string>
     */
    private static function groupIds(mixed $value): array
    {
        // Ein Einzelwert, wie ihn ältere Pflege oder die Schnittstelle schreibt, zählt als Liste mit einem Eintrag.
        $values = \is_array($value) ? $value : [$value];

        $ids = [];
        foreach ($values as $id) {
            // Kleinschreibung vor der Prüfung: `Uuid::isValid` kennt nur Kleinbuchstaben, die Schnittstelle nimmt beide.
            $id = \is_string($id) ? strtolower($id) : null;
            if ($id !== null && Uuid::isValid($id)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
