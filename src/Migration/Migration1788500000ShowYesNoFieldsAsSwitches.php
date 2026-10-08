<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Zeigt die Ja/Nein-Felder der Erweiterung als Schalter statt als Ankreuzfeld.
 *
 * Für jedes Ja/Nein-Zusatzfeld setzt die Verwaltung `bordered`; das gerahmte Ankreuzfeld von Meteor hat
 * keinen Abstand nach unten, und die Kästen kleben aneinander und an der Beschriftung des nächsten Felds.
 * Der Schalter (`mt-switch`) bringt seine Abstände mit. Der gespeicherte Wert bleibt ein Wahrheitswert,
 * Storefront und gesetzte Werte merken nichts davon.
 *
 * Die Hilfetexte sprechen danach vom Schalter statt vom Haken. Nur Felder, die noch als Ankreuzfeld
 * eingetragen sind, werden angefasst; ein zweiter Lauf ändert nichts.
 */
final class Migration1788500000ShowYesNoFieldsAsSwitches extends MigrationStep
{
    private const FIELDS = [
        DynamicPriceConstants::FIELD_LENGTH_VARIANT_SWITCH,
        DynamicPriceConstants::FIELD_GUIDED_SELECTION,
        DynamicPriceConstants::FIELD_LENGTH_ONLY,
        DynamicPriceConstants::CAT_FIELD_LENGTH_VARIANT_SWITCH,
        DynamicPriceConstants::CAT_FIELD_GUIDED_SELECTION,
        DynamicPriceConstants::CAT_FIELD_LENGTH_ONLY,
    ];

    public function getCreationTimestamp(): int
    {
        return 1788500000;
    }

    public function update(Connection $connection): void
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT `id`, `config` FROM `custom_field` WHERE `name` IN (:names)',
            ['names' => self::FIELDS],
            ['names' => ArrayParameterType::STRING],
        );

        foreach ($rows as $row) {
            $config = json_decode((string) $row['config'], true, flags: \JSON_THROW_ON_ERROR);
            if (!\is_array($config) || ($config['type'] ?? null) !== 'checkbox') {
                continue;
            }

            $config['type'] = 'switch';
            $config['customFieldType'] = 'switch';
            $config['componentName'] = 'sw-field';
            if (isset($config['helpText']['de-DE']) && \is_string($config['helpText']['de-DE'])) {
                $config['helpText']['de-DE'] = str_replace('der Haken', 'der Schalter', $config['helpText']['de-DE']);
            }

            $connection->executeStatement(
                'UPDATE `custom_field` SET `config` = :config, `updated_at` = NOW(3) WHERE `id` = :id',
                ['config' => json_encode($config, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), 'id' => $row['id']],
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
