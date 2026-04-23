<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Legt das Custom-Field-Set `rc_dynamic_price_category` an und
 * relationiert es an `category`. Enthält dieselben Felder wie am Produkt,
 * inklusive Active-Select (inherit/on/off, Default inherit).
 *
 * Idempotent: Set/Relation/Felder werden nur angelegt, wenn sie fehlen.
 */
final class Migration1745500000AddCategoryCustomFieldSet extends MigrationStep
{
    private const SET_NAME = DynamicPriceConstants::SET_CATEGORY;

    /** @return array<string, array{type: string, config: array<string, mixed>}> */
    private static function fields(): array
    {
        return [
            DynamicPriceConstants::CAT_FIELD_METER_ACTIVE => [
                'type' => 'select',
                'config' => [
                    'label' => [
                        'de-DE' => 'Meterpreis (Vererbung)',
                        'en-GB' => 'Meter price (inheritance)',
                    ],
                    'helpText' => [
                        'de-DE' => 'Steuert, ob Produkte in dieser Kategorie den Meterpreis anzeigen. "Vererben" gibt die Entscheidung an die Elternkategorie oder die Plugin-Global-Einstellung weiter.',
                        'en-GB' => 'Controls whether products in this category use the meter price. "Inherit" defers to the parent category or the global plugin setting.',
                    ],
                    'componentName' => 'sw-single-select',
                    'customFieldType' => 'select',
                    'type' => 'select',
                    'customFieldPosition' => 1,
                    'options' => [
                        [
                            'value' => 'inherit',
                            'label' => ['de-DE' => 'Vererben', 'en-GB' => 'Inherit'],
                        ],
                        [
                            'value' => 'on',
                            'label' => ['de-DE' => 'Aktiv', 'en-GB' => 'Active'],
                        ],
                        [
                            'value' => 'off',
                            'label' => ['de-DE' => 'Inaktiv', 'en-GB' => 'Inactive'],
                        ],
                    ],
                ],
            ],
            DynamicPriceConstants::CAT_FIELD_MIN_LENGTH => [
                'type' => 'int',
                'config' => [
                    'label' => ['de-DE' => 'Mindestlaenge (mm)', 'en-GB' => 'Minimum length (mm)'],
                    'helpText' => [
                        'de-DE' => 'Mindestlaenge fuer alle Produkte dieser Kategorie, sofern am Produkt nichts gesetzt ist.',
                        'en-GB' => 'Minimum length for products in this category, unless the product overrides it.',
                    ],
                    'componentName' => 'sw-field',
                    'customFieldType' => 'number',
                    'type' => 'number',
                    'numberType' => 'int',
                    'customFieldPosition' => 2,
                ],
            ],
            DynamicPriceConstants::CAT_FIELD_MAX_LENGTH => [
                'type' => 'int',
                'config' => [
                    'label' => ['de-DE' => 'Maximallaenge (mm)', 'en-GB' => 'Maximum length (mm)'],
                    'helpText' => [
                        'de-DE' => 'Maximallaenge fuer alle Produkte dieser Kategorie, sofern am Produkt nichts gesetzt ist.',
                        'en-GB' => 'Maximum length for products in this category, unless the product overrides it.',
                    ],
                    'componentName' => 'sw-field',
                    'customFieldType' => 'number',
                    'type' => 'number',
                    'numberType' => 'int',
                    'customFieldPosition' => 3,
                ],
            ],
            DynamicPriceConstants::CAT_FIELD_ROUNDING => [
                'type' => 'select',
                'config' => [
                    'label' => ['de-DE' => 'Rundungsmodus', 'en-GB' => 'Rounding mode'],
                    'helpText' => [
                        'de-DE' => 'Aufrundung der Kundenlaenge auf volle Einheiten.',
                        'en-GB' => 'Rounds the customer length up to full units.',
                    ],
                    'componentName' => 'sw-single-select',
                    'customFieldType' => 'select',
                    'type' => 'select',
                    'customFieldPosition' => 4,
                    'options' => [
                        ['value' => 'none', 'label' => ['de-DE' => 'Keine Rundung', 'en-GB' => 'No rounding']],
                        ['value' => 'cm', 'label' => ['de-DE' => 'Aufrunden auf cm', 'en-GB' => 'Round up to cm']],
                        ['value' => 'quarter_m', 'label' => ['de-DE' => 'Aufrunden auf 25 cm', 'en-GB' => 'Round up to 25 cm']],
                        ['value' => 'half_m', 'label' => ['de-DE' => 'Aufrunden auf 50 cm', 'en-GB' => 'Round up to 50 cm']],
                        ['value' => 'full_m', 'label' => ['de-DE' => 'Aufrunden auf Meter', 'en-GB' => 'Round up to full meter']],
                    ],
                ],
            ],
            DynamicPriceConstants::CAT_FIELD_SPLIT_MODE => [
                'type' => 'select',
                'config' => [
                    'label' => ['de-DE' => 'Split-Modus', 'en-GB' => 'Split mode'],
                    'helpText' => [
                        'de-DE' => 'Behandlung langer Eingaben oberhalb der Teilstuecklaenge.',
                        'en-GB' => 'Handling of long input above the piece length.',
                    ],
                    'componentName' => 'sw-single-select',
                    'customFieldType' => 'select',
                    'type' => 'select',
                    'customFieldPosition' => 5,
                    'options' => [
                        ['value' => 'equal', 'label' => ['de-DE' => 'Gleichmaessig aufteilen', 'en-GB' => 'Split equally']],
                        ['value' => 'max_rest', 'label' => ['de-DE' => 'Volle Stuecke plus Rest', 'en-GB' => 'Full pieces plus remainder']],
                        ['value' => 'hint', 'label' => ['de-DE' => 'Nur Hinweis', 'en-GB' => 'Hint only']],
                    ],
                ],
            ],
            DynamicPriceConstants::CAT_FIELD_MAX_PIECE_LENGTH => [
                'type' => 'int',
                'config' => [
                    'label' => ['de-DE' => 'Max. Teilstuecklaenge (mm)', 'en-GB' => 'Max. piece length (mm)'],
                    'helpText' => [
                        'de-DE' => 'Ab welcher Laenge greift das Splitting. Leer = kein Splitting.',
                        'en-GB' => 'Length above which splitting applies. Empty = no splitting.',
                    ],
                    'componentName' => 'sw-field',
                    'customFieldType' => 'number',
                    'type' => 'number',
                    'numberType' => 'int',
                    'customFieldPosition' => 6,
                ],
            ],
            DynamicPriceConstants::CAT_FIELD_SPLIT_HINT => [
                'type' => 'text',
                'config' => [
                    'label' => ['de-DE' => 'Hinweistext fuer Splitting', 'en-GB' => 'Hint text for splitting'],
                    'helpText' => [
                        'de-DE' => 'Platzhalter: {length}, {maxPiece}, {pieces}, {pieceLength}, {remainder}. Leer = Plugin-Default.',
                        'en-GB' => 'Placeholders: {length}, {maxPiece}, {pieces}, {pieceLength}, {remainder}. Empty = plugin default.',
                    ],
                    'componentName' => 'sw-textarea-field',
                    'customFieldType' => 'text',
                    'type' => 'text',
                    'customFieldPosition' => 7,
                ],
            ],
        ];
    }

    public function getCreationTimestamp(): int
    {
        return 1745500000;
    }

    public function update(Connection $connection): void
    {
        $setId = $this->ensureSet($connection);
        $this->ensureRelation($connection, $setId);
        $this->ensureFields($connection, $setId);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function ensureSet(Connection $connection): string
    {
        $existing = $connection->fetchOne(
            'SELECT `id` FROM `custom_field_set` WHERE `name` = :name',
            ['name' => self::SET_NAME]
        );

        if ($existing !== false) {
            return (string) $existing;
        }

        $setId = Uuid::randomBytes();

        $connection->executeStatement(
            'INSERT INTO `custom_field_set` (`id`, `name`, `config`, `active`, `global`, `position`, `created_at`)
             VALUES (:id, :name, :config, 1, 0, 2, NOW())',
            [
                'id'     => $setId,
                'name'   => self::SET_NAME,
                'config' => json_encode([
                    'label' => ['de-DE' => 'Dynamischer Meterpreis (Kategorie)', 'en-GB' => 'Dynamic Meter Price (Category)'],
                    'translated' => true,
                ], \JSON_THROW_ON_ERROR),
            ]
        );

        return $setId;
    }

    private function ensureRelation(Connection $connection, string $setId): void
    {
        $exists = $connection->fetchOne(
            'SELECT 1 FROM `custom_field_set_relation` WHERE `set_id` = :setId AND `entity_name` = :entity',
            ['setId' => $setId, 'entity' => 'category']
        );

        if ($exists !== false) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO `custom_field_set_relation` (`id`, `set_id`, `entity_name`, `created_at`)
             VALUES (:id, :setId, :entity, NOW())',
            [
                'id'     => Uuid::randomBytes(),
                'setId'  => $setId,
                'entity' => 'category',
            ]
        );
    }

    private function ensureFields(Connection $connection, string $setId): void
    {
        foreach (self::fields() as $fieldName => $spec) {
            $exists = $connection->fetchOne(
                'SELECT 1 FROM `custom_field` WHERE `name` = :name AND `set_id` = :setId',
                ['name' => $fieldName, 'setId' => $setId]
            );

            if ($exists !== false) {
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
}
