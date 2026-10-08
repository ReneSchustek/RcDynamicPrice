<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcDynamicPrice\Exception\DynamicPriceException;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Macht aus dem Haken `rc_meter_price_active` am Produkt eine Auswahl mit „vererben", „aktiv" und
 * „inaktiv", damit ein Produkt der Vorgabe seiner Kategorie folgen oder ihr ausdrücklich widersprechen
 * kann. Das Feld behält Namen und Kennung; was daran hängt, bleibt gültig.
 *
 * Gesetzte Haken werden zu „aktiv", nicht gesetzte zu „vererben"; ein fehlender Wert bleibt fehlend und
 * gilt im Resolver als „vererben". Die Migration darf mehrfach laufen: Die Felddefinition wird nur
 * umgestellt, solange sie noch ein Haken ist, und die Übertragung trifft nur boolesche Werte. Bleibt
 * danach ein boolescher Wert übrig, bricht sie mit einer Ausnahme ab, statt einen halb umgestellten
 * Bestand zu hinterlassen.
 */
final class Migration1745600000ConvertActiveFieldToTriState extends MigrationStep
{
    private const FIELD_NAME = 'rc_meter_price_active';

    public function getCreationTimestamp(): int
    {
        return 1745600000;
    }

    public function update(Connection $connection): void
    {
        $this->convertFieldDefinition($connection);
        $this->backfillProductCustomFields($connection);
        $this->verifyNoBooleanValuesRemain($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function convertFieldDefinition(Connection $connection): void
    {
        $row = $connection->fetchAssociative(
            'SELECT `id`, `type` FROM `custom_field` WHERE `name` = :name',
            ['name' => self::FIELD_NAME]
        );

        if ($row === false) {
            // Bei einer Neuinstallation gibt es das Feld an dieser Stelle noch nicht; nichts umzustellen.
            return;
        }

        if ($row['type'] === 'select') {
            return;
        }

        $connection->executeStatement(
            'UPDATE `custom_field` SET `type` = :type, `config` = :config, `updated_at` = NOW() WHERE `id` = :id',
            [
                'id'     => $row['id'],
                'type'   => 'select',
                'config' => json_encode([
                    'label' => ['de-DE' => 'Meterpreis (Aktivierung)', 'en-GB' => 'Meter price (activation)'],
                    'helpText' => [
                        'de-DE' => '"Vererben" übernimmt die Entscheidung aus der Kategorie oder der Plugin-Global-Einstellung. "Aktiv" erzwingt den Meterpreis, "Inaktiv" deaktiviert ihn produktbezogen.',
                        'en-GB' => '"Inherit" defers to the category or the global plugin setting. "Active" forces the meter price, "Inactive" disables it product-wise.',
                    ],
                    'componentName' => 'sw-single-select',
                    'customFieldType' => 'select',
                    'type' => 'select',
                    'customFieldPosition' => 1,
                    'options' => [
                        ['value' => 'inherit', 'label' => ['de-DE' => 'Vererben', 'en-GB' => 'Inherit']],
                        ['value' => 'on', 'label' => ['de-DE' => 'Aktiv', 'en-GB' => 'Active']],
                        ['value' => 'off', 'label' => ['de-DE' => 'Inaktiv', 'en-GB' => 'Inactive']],
                    ],
                ], \JSON_THROW_ON_ERROR),
            ]
        );
    }

    /**
     * Überträgt die Werte mit je einem `UPDATE` auf `product_translation`; dort liegen die Zusatzfelder,
     * `product` hat keine solche Spalte. Die beiden Abbildungen treffen sich nicht, die Reihenfolge ist
     * also gleichgültig.
     *
     * Verglichen wird über `JSON_TYPE` und den entpackten Text, nie als Zahl. Ein Zahlenvergleich läse
     * einen schon umgestellten Wert wie `"on"` als Dezimalzahl, und ein zweiter Lauf bräche mit
     * `Truncated incorrect DECIMAL value: 'on'` ab.
     */
    private function backfillProductCustomFields(Connection $connection): void
    {
        $jsonPath = '$.' . self::FIELD_NAME;

        // true / 1 / "1" -> "on"
        $connection->executeStatement(
            'UPDATE `product_translation`
             SET `custom_fields` = JSON_SET(`custom_fields`, :jsonPath, :newValue)
             WHERE JSON_EXTRACT(`custom_fields`, :jsonPath) IS NOT NULL
               AND (
                   (JSON_TYPE(JSON_EXTRACT(`custom_fields`, :jsonPath)) = "BOOLEAN"
                    AND JSON_UNQUOTE(JSON_EXTRACT(`custom_fields`, :jsonPath)) = "true")
                   OR (JSON_TYPE(JSON_EXTRACT(`custom_fields`, :jsonPath)) = "INTEGER"
                       AND JSON_UNQUOTE(JSON_EXTRACT(`custom_fields`, :jsonPath)) = "1")
                   OR (JSON_TYPE(JSON_EXTRACT(`custom_fields`, :jsonPath)) = "STRING"
                       AND JSON_UNQUOTE(JSON_EXTRACT(`custom_fields`, :jsonPath)) = "1")
               )',
            ['jsonPath' => $jsonPath, 'newValue' => 'on']
        );

        // false / 0 / "0" -> "inherit". Der Haken kennt kein „aus", ein nicht gesetzter Haken heißt bisher
        // nur „kein Meterpreis von hier"; in der neuen Kette entspricht das dem Vererben.
        $connection->executeStatement(
            'UPDATE `product_translation`
             SET `custom_fields` = JSON_SET(`custom_fields`, :jsonPath, :newValue)
             WHERE JSON_EXTRACT(`custom_fields`, :jsonPath) IS NOT NULL
               AND (
                   (JSON_TYPE(JSON_EXTRACT(`custom_fields`, :jsonPath)) = "BOOLEAN"
                    AND JSON_UNQUOTE(JSON_EXTRACT(`custom_fields`, :jsonPath)) = "false")
                   OR (JSON_TYPE(JSON_EXTRACT(`custom_fields`, :jsonPath)) = "INTEGER"
                       AND JSON_UNQUOTE(JSON_EXTRACT(`custom_fields`, :jsonPath)) = "0")
                   OR (JSON_TYPE(JSON_EXTRACT(`custom_fields`, :jsonPath)) = "STRING"
                       AND JSON_UNQUOTE(JSON_EXTRACT(`custom_fields`, :jsonPath)) = "0")
               )',
            ['jsonPath' => $jsonPath, 'newValue' => 'inherit']
        );
    }

    private function verifyNoBooleanValuesRemain(Connection $connection): void
    {
        $booleanLeftovers = $connection->fetchOne(
            'SELECT COUNT(*) FROM `product_translation`
             WHERE JSON_EXTRACT(`custom_fields`, :jsonPath) IS NOT NULL
               AND JSON_TYPE(JSON_EXTRACT(`custom_fields`, :jsonPath)) IN ("BOOLEAN", "INTEGER")',
            ['jsonPath' => '$.' . self::FIELD_NAME]
        );

        if ((int) $booleanLeftovers > 0) {
            throw DynamicPriceException::backfillIncomplete(self::FIELD_NAME, (int) $booleanLeftovers);
        }
    }
}
