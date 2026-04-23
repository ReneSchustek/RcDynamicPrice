<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
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
 * und kein `bool`-Rest mehr in `product.custom_fields` vorliegt. Verifikations-Query
 * am Ende wirft, falls nach dem Backfill noch Boolesche Werte existieren.
 */
final class Migration1745600000ConvertActiveFieldToTriState extends MigrationStep
{
    private const FIELD_NAME = 'rc_meter_price_active';
    private const BATCH_SIZE = 500;

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

    private function backfillProductCustomFields(Connection $connection): void
    {
        $lastId = null;
        do {
            $query = 'SELECT LOWER(HEX(`id`)) AS id_hex, `custom_fields` FROM `product`
                      WHERE `custom_fields` IS NOT NULL
                        AND JSON_EXTRACT(`custom_fields`, :jsonPath) IS NOT NULL';

            $parameters = ['jsonPath' => '$.' . self::FIELD_NAME];

            if ($lastId !== null) {
                $query .= ' AND LOWER(HEX(`id`)) > :lastId';
                $parameters['lastId'] = $lastId;
            }

            $query .= ' ORDER BY LOWER(HEX(`id`)) ASC LIMIT ' . self::BATCH_SIZE;

            /** @var list<array{id_hex: string, custom_fields: string}> $rows */
            $rows = $connection->fetchAllAssociative($query, $parameters);

            if ($rows === []) {
                break;
            }

            // Batch atomar schreiben: Cursor rückt erst nach erfolgreichem Commit vor.
            // Bricht ein Batch ab, bleibt `$lastId` auf dem letzten erfolgreichen Batch —
            // ein Re-Run der Migration nimmt denselben Batch nochmal und überspringt ihn nicht.
            $batchLastId = $connection->transactional(function (Connection $tx) use ($rows): string {
                $batchLastId = '';
                foreach ($rows as $row) {
                    $this->rewriteRow($tx, 'product', $row['id_hex'], $row['custom_fields']);
                    $batchLastId = $row['id_hex'];
                }

                return $batchLastId;
            });

            $lastId = $batchLastId;
        } while (\count($rows) === self::BATCH_SIZE);
    }

    private function rewriteRow(Connection $connection, string $table, string $idHex, string $rawJson): void
    {
        try {
            /** @var array<string, mixed>|null $customFields */
            $customFields = json_decode($rawJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Defekte JSON überspringen — würde im Admin sonst ohnehin nicht ladbar sein.
            return;
        }

        if (!\is_array($customFields) || !\array_key_exists(self::FIELD_NAME, $customFields)) {
            return;
        }

        $raw = $customFields[self::FIELD_NAME];

        $mapped = match (true) {
            $raw === true, $raw === 1, $raw === '1' => 'on',
            $raw === false, $raw === 0, $raw === '0' => 'inherit',
            \is_string($raw) && \in_array(strtolower($raw), ['inherit', 'on', 'off'], true) => strtolower($raw),
            default => 'inherit',
        };

        if ($customFields[self::FIELD_NAME] === $mapped) {
            return;
        }

        $customFields[self::FIELD_NAME] = $mapped;

        $connection->executeStatement(
            \sprintf('UPDATE `%s` SET `custom_fields` = :json, `updated_at` = NOW() WHERE `id` = UNHEX(:id)', $table),
            [
                'json' => json_encode($customFields, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                'id'   => $idHex,
            ]
        );
    }

    private function verifyNoBooleanValuesRemain(Connection $connection): void
    {
        $booleanLeftovers = $connection->fetchOne(
            'SELECT COUNT(*) FROM `product`
             WHERE JSON_EXTRACT(`custom_fields`, :jsonPath) IS NOT NULL
               AND JSON_TYPE(JSON_EXTRACT(`custom_fields`, :jsonPath)) IN ("BOOLEAN", "INTEGER")',
            ['jsonPath' => '$.' . self::FIELD_NAME]
        );

        if ((int) $booleanLeftovers > 0) {
            throw new \RuntimeException(\sprintf(
                'Backfill für Custom-Field "%s" unvollständig: %d Produkte halten weiterhin bool-/int-Werte. '
                . 'Plugin-Migration abgebrochen, Datenkorrektur notwendig.',
                self::FIELD_NAME,
                (int) $booleanLeftovers,
            ));
        }
    }
}
