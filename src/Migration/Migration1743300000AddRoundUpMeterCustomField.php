<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

final class Migration1743300000AddRoundUpMeterCustomField extends MigrationStep
{
    private const SET_NAME = 'rc_dynamic_price';
    private const FIELD_NAME = 'rc_meter_price_round_up_meter';

    public function getCreationTimestamp(): int
    {
        return 1743300000;
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

        $exists = $connection->fetchOne(
            'SELECT 1 FROM `custom_field` WHERE `name` = :name',
            ['name' => self::FIELD_NAME]
        );

        if ($exists !== false) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO `custom_field` (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`)
             VALUES (:id, :name, :type, :config, 1, :setId, NOW())',
            [
                'id'     => Uuid::randomBytes(),
                'name'   => self::FIELD_NAME,
                'type'   => 'bool',
                'config' => json_encode([
                    'label'               => [
                        'de-DE' => 'Auf vollen Meter aufrunden',
                        'en-GB' => 'Round up to full meter',
                    ],
                    'componentName'       => 'sw-field',
                    'customFieldType'     => 'checkbox',
                    'type'                => 'checkbox',
                    'customFieldPosition' => 4,
                    'helpText'            => [
                        'de-DE' => 'Eingabe wird auf den nächsten vollen Meter (1000 mm) aufgerundet. Z. B. 4050 mm → 5000 mm',
                        'en-GB' => 'Input is rounded up to the next full meter (1000 mm). E.g. 4050 mm → 5000 mm',
                    ],
                ], \JSON_THROW_ON_ERROR),
                'setId'  => (string) $setId,
            ]
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
