<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Legt die Felder an, mit denen die Länge an Kategorien eingestellt wird: die Längengruppen als
 * Mehrfachauswahl, den Längenschalter an der Kategorie und „Größen nur über die Länge" an Produkt und
 * Kategorie.
 *
 * Eine Längengruppe je Kategorie reichte nicht: In derselben Kategorie heißen die Längen mal „Maße",
 * mal „Länge". Mit mehreren Gruppen nimmt jeder Artikel die, die er hat.
 *
 * Auf Entwicklungsinstanzen gibt es bisher das Einzelfeld `rc_guided_length_group` aus 1.23.0. Seine
 * Werte werden als einelementige Liste übernommen, das Feld selbst entfernt. Staging und Live hatten
 * es nie; dort läuft nur das Anlegen.
 */
final class Migration1788400000ReplaceLengthGroupWithLengthGroups extends MigrationStep
{
    // Feste Kennungen: Ein zweiter Lauf trifft dieselben Datensätze, und `INSERT IGNORE` lässt sie aus.
    private const PRODUCT_GROUPS_ID = 'f05a25927a14435da1030a091825c85c';
    private const PRODUCT_LENGTH_ONLY_ID = 'f8bd4435c0284fe3a30486a8ccae0c30';
    private const CATEGORY_GROUPS_ID = '8ae7bd272fa74cd3ab3c283c6e828d82';
    private const CATEGORY_SWITCH_ID = '959faa51cba247a98f021460a4f9b847';
    private const CATEGORY_LENGTH_ONLY_ID = '37ecd9ab0d014e3a88a94eceb4a007ad';

    // Am Produkt hinter Längenschalter (20) und geführter Auswahl (21); an der Kategorie hinter der
    // geführten Auswahl (8).
    private const PRODUCT_POSITION = 22;
    private const CATEGORY_POSITION = 9;

    public function getCreationTimestamp(): int
    {
        return 1788400000;
    }

    public function update(Connection $connection): void
    {
        $productSetId = $this->setId($connection, DynamicPriceConstants::SET_PRODUCT);
        if ($productSetId !== null) {
            $this->insert($connection, self::PRODUCT_GROUPS_ID, DynamicPriceConstants::FIELD_LENGTH_GROUPS, 'select', self::groupsConfig(self::PRODUCT_POSITION), $productSetId);
            $this->insert($connection, self::PRODUCT_LENGTH_ONLY_ID, DynamicPriceConstants::FIELD_LENGTH_ONLY, 'bool', self::lengthOnlyConfig(self::PRODUCT_POSITION + 1), $productSetId);
        }

        $categorySetId = $this->setId($connection, DynamicPriceConstants::SET_CATEGORY);
        if ($categorySetId !== null) {
            $this->insert($connection, self::CATEGORY_GROUPS_ID, DynamicPriceConstants::CAT_FIELD_LENGTH_GROUPS, 'select', self::groupsConfig(self::CATEGORY_POSITION), $categorySetId);
            $this->insert($connection, self::CATEGORY_SWITCH_ID, DynamicPriceConstants::CAT_FIELD_LENGTH_VARIANT_SWITCH, 'bool', self::switchConfig(self::CATEGORY_POSITION + 1), $categorySetId);
            $this->insert($connection, self::CATEGORY_LENGTH_ONLY_ID, DynamicPriceConstants::CAT_FIELD_LENGTH_ONLY, 'bool', self::lengthOnlyConfig(self::CATEGORY_POSITION + 2), $categorySetId);
        }

        // Erst die Werte umziehen, dann die Altfelder löschen: Ohne Feld zeigte der Verwaltungsbereich
        // den Wert nicht mehr, und niemand sähe, was verloren ginge.
        $this->moveValues($connection, 'product_translation', DynamicPriceConstants::LEGACY_FIELD_GUIDED_LENGTH_GROUP, DynamicPriceConstants::FIELD_LENGTH_GROUPS);
        $this->moveValues($connection, 'category_translation', DynamicPriceConstants::LEGACY_CAT_FIELD_GUIDED_LENGTH_GROUP, DynamicPriceConstants::CAT_FIELD_LENGTH_GROUPS);

        $connection->executeStatement(
            'DELETE FROM `custom_field` WHERE `name` IN (:names)',
            ['names' => [DynamicPriceConstants::LEGACY_FIELD_GUIDED_LENGTH_GROUP, DynamicPriceConstants::LEGACY_CAT_FIELD_GUIDED_LENGTH_GROUP]],
            ['names' => ArrayParameterType::STRING],
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    /**
     * Macht aus dem Einzelwert eine einelementige Liste unter dem neuen Schlüssel und nimmt den alten
     * heraus. Ein schon gesetzter neuer Wert bleibt stehen.
     */
    private function moveValues(Connection $connection, string $table, string $from, string $to): void
    {
        $connection->executeStatement(
            \sprintf(
                'UPDATE `%s`
                 SET `custom_fields` = JSON_REMOVE(
                     IF(JSON_CONTAINS_PATH(`custom_fields`, :one, :to),
                        `custom_fields`,
                        JSON_SET(`custom_fields`, :to, JSON_ARRAY(JSON_UNQUOTE(JSON_EXTRACT(`custom_fields`, :from))))),
                     :from)
                 WHERE JSON_EXTRACT(`custom_fields`, :from) IS NOT NULL',
                $table,
            ),
            ['from' => '$.' . $from, 'to' => '$.' . $to, 'one' => 'one'],
        );
    }

    private function setId(Connection $connection, string $name): ?string
    {
        $id = $connection->fetchOne('SELECT `id` FROM `custom_field_set` WHERE `name` = :name', ['name' => $name]);

        return \is_string($id) ? $id : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function insert(Connection $connection, string $id, string $name, string $type, array $config, string $setId): void
    {
        $connection->executeStatement(
            'INSERT IGNORE INTO `custom_field` (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`)
             VALUES (:id, :name, :type, :config, 1, :setId, NOW())',
            [
                'id' => Uuid::fromHexToBytes($id),
                'name' => $name,
                'type' => $type,
                'config' => json_encode($config, \JSON_THROW_ON_ERROR),
                'setId' => $setId,
            ],
        );
    }

    /**
     * Mehrfachauswahl der Eigenschaftsgruppen, wie der Verwaltungsbereich ein Entitätsfeld mit
     * „Mehrfachauswahl" anlegt. Ein freies Textfeld mit Gruppennamen bräche beim Umbenennen der Gruppe.
     *
     * @return array<string, mixed>
     */
    private static function groupsConfig(int $position): array
    {
        return [
            'label' => [
                'de-DE' => 'Längengruppen',
                'en-GB' => 'Length groups',
            ],
            'helpText' => [
                'de-DE' => 'Die Eigenschaftsgruppen, deren Werte Längen sind, etwa „Maße" und „Länge". Jeder '
                    . 'Artikel nimmt die Gruppe, die er hat. Geführte Auswahl und Längenschalter lesen sie.',
                'en-GB' => 'The property groups whose values are lengths, such as "Dimensions" and "Length". '
                    . 'Each product uses the group it has. Guided selection and length switch read them.',
            ],
            'componentName' => 'sw-entity-multi-id-select',
            'customFieldType' => 'entity',
            'entity' => 'property_group',
            'customFieldPosition' => $position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function switchConfig(int $position): array
    {
        return [
            'label' => [
                'de-DE' => 'Eingegebene Länge wählt die Größe',
                'en-GB' => 'Entered length selects the size',
            ],
            'helpText' => [
                'de-DE' => 'Für alle Produkte dieser Kategorie: Trägt der Kunde eine Länge ein, stellt der Shop '
                    . 'auf die nächstgrößere Größe um. Wirkt nur bei Artikeln mit Längenfeld.',
                'en-GB' => 'For all products in this category: when the customer enters a length, the shop '
                    . 'switches to the next larger size. Only affects products with a length field.',
            ],
            'componentName' => 'sw-field',
            'customFieldType' => 'checkbox',
            'customFieldPosition' => $position,
            'type' => 'checkbox',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function lengthOnlyConfig(int $position): array
    {
        return [
            'label' => [
                'de-DE' => 'Größen nur über die Länge',
                'en-GB' => 'Sizes by length only',
            ],
            'helpText' => [
                'de-DE' => 'Die Knöpfe der Längengruppe verschwinden; der Kunde gibt nur seine Länge ein und '
                    . 'sieht, welche Länge berechnet wird. Gedacht für Stangenmaterial. Braucht den '
                    . 'Längenschalter und die Längengruppen.',
                'en-GB' => 'The buttons of the length group disappear; the customer only enters a length and '
                    . 'sees which length is charged. Meant for bar stock. Needs the length switch and the '
                    . 'length groups.',
            ],
            'componentName' => 'sw-field',
            'customFieldType' => 'checkbox',
            'customFieldPosition' => $position,
            'type' => 'checkbox',
        ];
    }
}
