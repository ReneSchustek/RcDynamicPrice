<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Korrigiert die Admin-Konfiguration des Hinweistext-Felds:
 *  - alt: sw-text-editor / html → Admin speichert HTML, Frontend rendert aber als Literal
 *  - neu: sw-textarea-field / text → Plain-Text, konsistent mit Twig-Escape und textContent im JS
 *
 * Die vorausgegangene Migration 1745200000 bleibt unveraendert (forward-only-Regel).
 */
final class Migration1745300000FixSplitHintComponent extends MigrationStep
{
    private const FIELD_NAME = 'rc_meter_price_split_hint';

    public function getCreationTimestamp(): int
    {
        return 1745300000;
    }

    public function update(Connection $connection): void
    {
        $row = $connection->fetchAssociative(
            'SELECT `id`, `config` FROM `custom_field` WHERE `name` = :name',
            ['name' => self::FIELD_NAME]
        );

        if ($row === false) {
            return;
        }

        /** @var array<string, mixed>|null $config */
        $config = json_decode((string) $row['config'], true);

        if (!\is_array($config)) {
            return;
        }

        $config['componentName'] = 'sw-textarea-field';
        $config['customFieldType'] = 'text';
        $config['type'] = 'text';

        $connection->executeStatement(
            'UPDATE `custom_field` SET `config` = :config, `updated_at` = NOW() WHERE `id` = :id',
            [
                'config' => json_encode($config, \JSON_THROW_ON_ERROR),
                'id'     => $row['id'],
            ]
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
