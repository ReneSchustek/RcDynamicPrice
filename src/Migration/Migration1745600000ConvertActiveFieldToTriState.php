<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcDynamicPrice\Exception\DynamicPriceException;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Konvertiert `rc_meter_price_active` am Produkt von `bool` (Checkbox)
 * auf `select` mit den Werten `inherit` / `on` / `off`. Das Feld behält Namen
 * und ID — vorhandene Beziehungen bleiben intakt.
 *
 * Backfill:
 *   true    -> "on"
 *   false   -> "inherit"
 *   fehlend -> bleibt fehlend (wird im Resolver als "inherit" behandelt)
 *
 * Idempotent: bricht ab, sobald der `custom_field`-Row-Type bereits `select` ist
 * und kein `bool`-Rest mehr in `product_translation.custom_fields` vorliegt.
 * Verifikations-Query am Ende wirft, falls nach dem Backfill noch boolesche
 * Werte existieren.
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
            // Plugin-Neuinstallation hat noch keinen Active-Feldeintrag — nichts zu konvertieren.
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
     * Backfill via Single-Statement-UPDATE gegen `product_translation` (dort liegen die
     * Custom-Fields — Tabelle `product` selbst hat keine `custom_fields`-Spalte).
     * Zwei separate UPDATEs, weil MySQL/MariaDB keinen direkten CASE-über-JSON-Typ
     * kennt und beide Mappings ohnehin disjunkt sind.
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
                   JSON_EXTRACT(`custom_fields`, :jsonPath) = true
                   OR JSON_EXTRACT(`custom_fields`, :jsonPath) = 1
                   OR JSON_UNQUOTE(JSON_EXTRACT(`custom_fields`, :jsonPath)) = "1"
               )',
            ['jsonPath' => $jsonPath, 'newValue' => 'on']
        );

        // false / 0 / "0" -> "inherit" (1.4.x kannte kein "off"; nicht-aktive Bool-Werte
        // entsprechen "vererben" an die neue Entscheidungskette)
        $connection->executeStatement(
            'UPDATE `product_translation`
             SET `custom_fields` = JSON_SET(`custom_fields`, :jsonPath, :newValue)
             WHERE JSON_EXTRACT(`custom_fields`, :jsonPath) IS NOT NULL
               AND (
                   JSON_EXTRACT(`custom_fields`, :jsonPath) = false
                   OR JSON_EXTRACT(`custom_fields`, :jsonPath) = 0
                   OR JSON_UNQUOTE(JSON_EXTRACT(`custom_fields`, :jsonPath)) = "0"
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
