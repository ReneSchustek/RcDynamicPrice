<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Ersetzt das Bool-Feld rc_meter_price_round_up_meter durch das Select-Feld
 * rc_meter_price_rounding mit konfigurierbaren Rundungsstufen.
 *
 * Bestehende Produkte mit round_up_meter = true werden auf full_m migriert.
 */
final class Migration1743400000ReplaceRoundUpWithRoundingMode extends MigrationStep
{
    private const SET_NAME = 'rc_dynamic_price';
    private const OLD_FIELD_NAME = 'rc_meter_price_round_up_meter';
    private const NEW_FIELD_NAME = 'rc_meter_price_rounding';

    public function getCreationTimestamp(): int
    {
        return 1743400000;
    }

    public function update(Connection $connection): void
    {
        $setId = $connection->fetchOne(
            'SELECT `id` FROM `custom_field_set` WHERE `name` = :name',
            ['name' => self::SET_NAME]
        );

        if ($setId === false) {
            return;
        }

        $this->migrateExistingValues($connection);
        $this->removeOldField($connection);
        $this->createNewField($connection, (string) $setId);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    /**
     * Bestehende Produkte mit round_up_meter = true auf full_m setzen.
     * Nutzt JSON_SET direkt auf der Datenbank, um alle betroffenen Produkte in einem Schritt zu aktualisieren.
     */
    private function migrateExistingValues(Connection $connection): void
    {
        $connection->executeStatement(
            'UPDATE `product`
             SET `custom_fields` = JSON_SET(
                 COALESCE(`custom_fields`, \'{}\'),
                 \'$."rc_meter_price_rounding"\',
                 \'full_m\'
             )
             WHERE JSON_EXTRACT(`custom_fields`, \'$."rc_meter_price_round_up_meter"\') = true'
        );

        // Altes Feld aus den JSON-Daten entfernen
        $connection->executeStatement(
            'UPDATE `product`
             SET `custom_fields` = JSON_REMOVE(`custom_fields`, \'$."rc_meter_price_round_up_meter"\')
             WHERE JSON_EXTRACT(`custom_fields`, \'$."rc_meter_price_round_up_meter"\') IS NOT NULL'
        );
    }

    private function removeOldField(Connection $connection): void
    {
        $connection->executeStatement(
            'DELETE FROM `custom_field` WHERE `name` = :name',
            ['name' => self::OLD_FIELD_NAME]
        );
    }

    private function createNewField(Connection $connection, string $setId): void
    {
        $exists = $connection->fetchOne(
            'SELECT 1 FROM `custom_field` WHERE `name` = :name',
            ['name' => self::NEW_FIELD_NAME]
        );

        if ($exists !== false) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO `custom_field` (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`)
             VALUES (:id, :name, :type, :config, 1, :setId, NOW())',
            [
                'id'     => Uuid::randomBytes(),
                'name'   => self::NEW_FIELD_NAME,
                'type'   => 'select',
                'config' => json_encode([
                    'label' => [
                        'de-DE' => 'Rundungsmodus',
                        'en-GB' => 'Rounding mode',
                    ],
                    'helpText' => [
                        'de-DE' => 'Legt fest, auf welche Einheit die Kundeneingabe aufgerundet wird. '
                            . '"Keine" = exakte mm-Berechnung.',
                        'en-GB' => 'Defines the unit to which the customer input is rounded up. '
                            . '"None" = exact mm calculation.',
                    ],
                    'componentName'       => 'sw-single-select',
                    'customFieldType'     => 'select',
                    'type'                => 'select',
                    'customFieldPosition' => 4,
                    'options'             => [
                        [
                            'value' => 'none',
                            'label' => ['de-DE' => 'Keine Rundung', 'en-GB' => 'No rounding'],
                        ],
                        [
                            'value' => 'cm',
                            'label' => ['de-DE' => 'Volle Zentimeter (10 mm)', 'en-GB' => 'Full centimetres (10 mm)'],
                        ],
                        [
                            'value' => 'quarter_m',
                            'label' => ['de-DE' => 'Viertel Meter (250 mm)', 'en-GB' => 'Quarter metre (250 mm)'],
                        ],
                        [
                            'value' => 'half_m',
                            'label' => ['de-DE' => 'Halber Meter (500 mm)', 'en-GB' => 'Half metre (500 mm)'],
                        ],
                        [
                            'value' => 'full_m',
                            'label' => ['de-DE' => 'Voller Meter (1000 mm)', 'en-GB' => 'Full metre (1000 mm)'],
                        ],
                    ],
                ], \JSON_THROW_ON_ERROR),
                'setId' => $setId,
            ]
        );
    }
}
