<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Ergaenzt drei Custom Fields fuer das Laengen-Splitting:
 *  - rc_meter_price_split_mode        (select: equal | max_rest | hint)
 *  - rc_meter_price_max_piece_length  (int, mm)
 *  - rc_meter_price_split_hint        (text mit Platzhaltern)
 */
final class Migration1745200000AddSplittingCustomFields extends MigrationStep
{
    private const SET_NAME = 'rc_dynamic_price';

    private const FIELD_SPLIT_MODE = 'rc_meter_price_split_mode';
    private const FIELD_MAX_PIECE_LENGTH = 'rc_meter_price_max_piece_length';
    private const FIELD_SPLIT_HINT = 'rc_meter_price_split_hint';

    public function getCreationTimestamp(): int
    {
        return 1745200000;
    }

    public function update(Connection $connection): void
    {
        $setId = $this->getCustomFieldSetId($connection);

        if ($setId === null) {
            return;
        }

        $this->createSplitModeField($connection, $setId);
        $this->createMaxPieceLengthField($connection, $setId);
        $this->createSplitHintField($connection, $setId);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function getCustomFieldSetId(Connection $connection): ?string
    {
        $id = $connection->fetchOne(
            'SELECT `id` FROM `custom_field_set` WHERE `name` = :name',
            ['name' => self::SET_NAME]
        );

        return $id !== false ? (string) $id : null;
    }

    private function createSplitModeField(Connection $connection, string $setId): void
    {
        if ($this->fieldExists($connection, self::FIELD_SPLIT_MODE)) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO `custom_field` (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`)
             VALUES (:id, :name, :type, :config, 1, :setId, NOW())',
            [
                'id'     => Uuid::randomBytes(),
                'name'   => self::FIELD_SPLIT_MODE,
                'type'   => 'select',
                'config' => json_encode([
                    'label' => [
                        'de-DE' => 'Split-Modus fuer Langstuecke',
                        'en-GB' => 'Split mode for long pieces',
                    ],
                    'helpText' => [
                        'de-DE' => 'Wie wird eine Eingabe oberhalb der maximalen Teilstuecklaenge behandelt?',
                        'en-GB' => 'How is input above the maximum piece length handled?',
                    ],
                    'componentName'       => 'sw-single-select',
                    'customFieldType'     => 'select',
                    'type'                => 'select',
                    'customFieldPosition' => 5,
                    'options'             => [
                        [
                            'value' => 'equal',
                            'label' => [
                                'de-DE' => 'Gleichmaessig aufteilen',
                                'en-GB' => 'Split equally',
                            ],
                        ],
                        [
                            'value' => 'max_rest',
                            'label' => [
                                'de-DE' => 'Volle Stuecke plus Rest',
                                'en-GB' => 'Full pieces plus remainder',
                            ],
                        ],
                        [
                            'value' => 'hint',
                            'label' => [
                                'de-DE' => 'Nur Hinweis (Kunde teilt selbst auf)',
                                'en-GB' => 'Hint only (customer splits manually)',
                            ],
                        ],
                    ],
                ], \JSON_THROW_ON_ERROR),
                'setId' => $setId,
            ]
        );
    }

    private function createMaxPieceLengthField(Connection $connection, string $setId): void
    {
        if ($this->fieldExists($connection, self::FIELD_MAX_PIECE_LENGTH)) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO `custom_field` (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`)
             VALUES (:id, :name, :type, :config, 1, :setId, NOW())',
            [
                'id'     => Uuid::randomBytes(),
                'name'   => self::FIELD_MAX_PIECE_LENGTH,
                'type'   => 'int',
                'config' => json_encode([
                    'label' => [
                        'de-DE' => 'Max. Teilstuecklaenge (mm)',
                        'en-GB' => 'Max. piece length (mm)',
                    ],
                    'helpText' => [
                        'de-DE' => 'Ab welcher Laenge werden Eingaben aufgeteilt. Leer = kein Splitting.',
                        'en-GB' => 'Length above which input is split. Empty = no splitting.',
                    ],
                    'componentName'       => 'sw-field',
                    'customFieldType'     => 'number',
                    'type'                => 'number',
                    'numberType'          => 'int',
                    'customFieldPosition' => 6,
                ], \JSON_THROW_ON_ERROR),
                'setId' => $setId,
            ]
        );
    }

    private function createSplitHintField(Connection $connection, string $setId): void
    {
        if ($this->fieldExists($connection, self::FIELD_SPLIT_HINT)) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO `custom_field` (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`)
             VALUES (:id, :name, :type, :config, 1, :setId, NOW())',
            [
                'id'     => Uuid::randomBytes(),
                'name'   => self::FIELD_SPLIT_HINT,
                'type'   => 'text',
                'config' => json_encode([
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
                    'componentName'       => 'sw-text-editor',
                    'customFieldType'     => 'text',
                    'type'                => 'html',
                    'customFieldPosition' => 7,
                ], \JSON_THROW_ON_ERROR),
                'setId' => $setId,
            ]
        );
    }

    private function fieldExists(Connection $connection, string $name): bool
    {
        return $connection->fetchOne(
            'SELECT 1 FROM `custom_field` WHERE `name` = :name',
            ['name' => $name]
        ) !== false;
    }
}
