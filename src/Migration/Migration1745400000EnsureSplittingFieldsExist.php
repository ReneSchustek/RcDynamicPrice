<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcDynamicPrice\Exception\DynamicPriceException;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Legt die drei Felder zum Aufteilen an, falls `Migration1745200000AddSplittingCustomFields` ohne sie
 * zurückkam, weil das Feldset fehlte, etwa nach dem Einspielen einer Sicherung, in der die Migration
 * mit dem Set nicht gelaufen ist.
 *
 * Fehlt das Set auch hier, bricht die Migration mit einer Ausnahme ab. So erscheint das Problem beim
 * `plugin:update`, statt als Shop, in dem das Aufteilen still nicht funktioniert. Die Felddefinitionen
 * stehen hier noch einmal vollständig, mit dem Textfeld für den Hinweis wie nach der Umstellung.
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
                    'de-DE' => 'Split-Modus für Langstücke',
                    'en-GB' => 'Split mode for long pieces',
                ],
                'helpText' => [
                    'de-DE' => 'Wie wird eine Eingabe oberhalb der maximalen Teilstücklänge behandelt?',
                    'en-GB' => 'How is input above the maximum piece length handled?',
                ],
                'componentName' => 'sw-single-select',
                'customFieldType' => 'select',
                'type' => 'select',
                'customFieldPosition' => 5,
                'options' => [
                    [
                        'value' => 'equal',
                        'label' => ['de-DE' => 'Gleichmäßig aufteilen', 'en-GB' => 'Split equally'],
                    ],
                    [
                        'value' => 'max_rest',
                        'label' => ['de-DE' => 'Volle Stücke plus Rest', 'en-GB' => 'Full pieces plus remainder'],
                    ],
                    [
                        'value' => 'hint',
                        'label' => [
                            'de-DE' => 'Nur Hint (Kunde teilt selbst auf)',
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
                    'de-DE' => 'Max. Teilstücklänge (mm)',
                    'en-GB' => 'Max. piece length (mm)',
                ],
                'helpText' => [
                    'de-DE' => 'Ab welcher Länge werden Eingaben aufgeteilt. Leer = kein Splitting.',
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
                    'de-DE' => 'Hinweistext für Splitting',
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
            throw DynamicPriceException::missingCustomFieldSet(
                self::SET_NAME,
                'Migration1743123456CreateMeterPriceCustomField',
            );
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
