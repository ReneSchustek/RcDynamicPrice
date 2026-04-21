<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Repariert den Sonderfall, dass Migration1745200000 silent returned hat, weil das CustomFieldSet
 * zum Zeitpunkt ihrer Ausführung fehlte (z. B. Datenbank-Wiederherstellung aus Backup, in der
 * 1743123456CreateMeterPriceCustomField nicht lief).
 *
 * Fehlt das Set jetzt immer noch, wird eine RuntimeException geworfen — der Admin sieht beim
 * plugin:update sofort das Problem, statt stumme Nicht-Funktionalität im Shop.
 */
final class Migration1745400000EnsureSplittingFieldsExist extends MigrationStep
{
    private const SET_NAME = 'rc_dynamic_price';

    /** @var array<string, array{type: string, config: array<string, mixed>}> */
    private const FIELDS = [
        'rc_meter_price_split_mode' => [
            'type' => 'select',
            'config' => [
                'label' => [
                    'de-DE' => 'Split-Modus fuer Langstuecke',
                    'en-GB' => 'Split mode for long pieces',
                ],
                'helpText' => [
                    'de-DE' => 'Wie wird eine Eingabe oberhalb der maximalen Teilstuecklaenge behandelt?',
                    'en-GB' => 'How is input above the maximum piece length handled?',
                ],
                'componentName' => 'sw-single-select',
                'customFieldType' => 'select',
                'type' => 'select',
                'customFieldPosition' => 5,
                'options' => [
                    [
                        'value' => 'equal',
                        'label' => ['de-DE' => 'Gleichmaessig aufteilen', 'en-GB' => 'Split equally'],
                    ],
                    [
                        'value' => 'max_rest',
                        'label' => ['de-DE' => 'Volle Stuecke plus Rest', 'en-GB' => 'Full pieces plus remainder'],
                    ],
                    [
                        'value' => 'hint',
                        'label' => [
                            'de-DE' => 'Nur Hinweis (Kunde teilt selbst auf)',
                            'en-GB' => 'Hint only (customer splits manually)',
                        ],
                    ],
                ],
            ],
        ],
        'rc_meter_price_max_piece_length' => [
            'type' => 'int',
            'config' => [
                'label' => [
                    'de-DE' => 'Max. Teilstuecklaenge (mm)',
                    'en-GB' => 'Max. piece length (mm)',
                ],
                'helpText' => [
                    'de-DE' => 'Ab welcher Laenge werden Eingaben aufgeteilt. Leer = kein Splitting.',
                    'en-GB' => 'Length above which input is split. Empty = no splitting.',
                ],
                'componentName' => 'sw-field',
                'customFieldType' => 'number',
                'type' => 'number',
                'numberType' => 'int',
                'customFieldPosition' => 6,
            ],
        ],
        'rc_meter_price_split_hint' => [
            'type' => 'text',
            'config' => [
                'label' => [
                    'de-DE' => 'Hinweistext fuer Splitting',
                    'en-GB' => 'Hint text for splitting',
                ],
                'helpText' => [
                    'de-DE' => 'Platzhalter: {length}, {maxPiece}, {pieces}, {pieceLength}, {remainder}. '
                        . 'Leer = globaler Plugin-Default wird verwendet.',
                    'en-GB' => 'Placeholders: {length}, {maxPiece}, {pieces}, {pieceLength}, {remainder}. '
                        . 'Empty = plugin default is used.',
                ],
                'componentName' => 'sw-textarea-field',
                'customFieldType' => 'text',
                'type' => 'text',
                'customFieldPosition' => 7,
            ],
        ],
    ];

    public function getCreationTimestamp(): int
    {
        return 1745400000;
    }

    public function update(Connection $connection): void
    {
        $setId = $connection->fetchOne(
            'SELECT `id` FROM `custom_field_set` WHERE `name` = :name',
            ['name' => self::SET_NAME]
        );

        if ($setId === false) {
            throw new \RuntimeException(\sprintf(
                'CustomFieldSet "%s" fehlt. Plugin-Installation scheint defekt — '
                . 'Migration1743123456CreateMeterPriceCustomField muss vorher erfolgreich gelaufen sein.',
                self::SET_NAME
            ));
        }

        foreach (self::FIELDS as $fieldName => $spec) {
            if ($this->fieldExists($connection, $fieldName)) {
                continue;
            }

            $connection->executeStatement(
                'INSERT INTO `custom_field` (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`)
                 VALUES (:id, :name, :type, :config, 1, :setId, NOW())',
                [
                    'id'     => Uuid::randomBytes(),
                    'name'   => $fieldName,
                    'type'   => $spec['type'],
                    'config' => json_encode($spec['config'], \JSON_THROW_ON_ERROR),
                    'setId'  => $setId,
                ]
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function fieldExists(Connection $connection, string $name): bool
    {
        return $connection->fetchOne(
            'SELECT 1 FROM `custom_field` WHERE `name` = :name',
            ['name' => $name]
        ) !== false;
    }
}
