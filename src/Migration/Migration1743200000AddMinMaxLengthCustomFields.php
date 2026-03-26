<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

final class Migration1743200000AddMinMaxLengthCustomFields extends MigrationStep
{
    private const SET_NAME = 'rc_dynamic_price';
    private const FIELD_MIN_LENGTH = 'rc_meter_price_min_length';
    private const FIELD_MAX_LENGTH = 'rc_meter_price_max_length';

    public function getCreationTimestamp(): int
    {
        return 1743200000;
    }

    public function update(Connection $connection): void
    {
        $setId = $this->getCustomFieldSetId($connection);
        if ($setId === null) {
            return;
        }

        $this->ensureCustomField($connection, $setId, self::FIELD_MIN_LENGTH, [
            'label'               => ['de-DE' => 'Mindestlänge (mm)', 'en-GB' => 'Minimum length (mm)'],
            'componentName'       => 'sw-field',
            'customFieldType'     => 'number',
            'type'                => 'number',
            'numberType'          => 'int',
            'customFieldPosition' => 2,
            'helpText'            => [
                'de-DE' => 'Leer lassen = globaler Standardwert aus der Plugin-Konfiguration',
                'en-GB' => 'Leave empty = global default from plugin configuration',
            ],
        ]);

        $this->ensureCustomField($connection, $setId, self::FIELD_MAX_LENGTH, [
            'label'               => ['de-DE' => 'Maximallänge (mm)', 'en-GB' => 'Maximum length (mm)'],
            'componentName'       => 'sw-field',
            'customFieldType'     => 'number',
            'type'                => 'number',
            'numberType'          => 'int',
            'customFieldPosition' => 3,
            'helpText'            => [
                'de-DE' => 'Leer lassen = globaler Standardwert aus der Plugin-Konfiguration',
                'en-GB' => 'Leave empty = global default from plugin configuration',
            ],
        ]);
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

    /**
     * @param array<string, mixed> $config
     */
    private function ensureCustomField(Connection $connection, string $setId, string $name, array $config): void
    {
        $exists = $connection->fetchOne(
            'SELECT 1 FROM `custom_field` WHERE `name` = :name',
            ['name' => $name]
        );

        if ($exists !== false) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO `custom_field` (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`)
             VALUES (:id, :name, :type, :config, 1, :setId, NOW())',
            [
                'id'     => Uuid::randomBytes(),
                'name'   => $name,
                'type'   => 'int',
                'config' => json_encode($config, \JSON_THROW_ON_ERROR),
                'setId'  => $setId,
            ]
        );
    }
}
