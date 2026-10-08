<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Legt den Haken der geführten Variantenauswahl an, am Produkt und an der Kategorie.
 *
 * Die Felder kommen in die bestehenden Sets der Erweiterung, wie der Längenschalter: dieselbe
 * Domäne, dieselbe Karte im Verwaltungsbereich. Die Kennungen der Sets werden gelesen; fehlt ein
 * Set, bleiben seine Felder weg, statt verwaist angelegt zu werden. Die Längengruppen, die die
 * Auswahl braucht, legt `Migration1788400000ReplaceLengthGroupWithLengthGroups` an.
 */
final class Migration1788300000AddGuidedSelectionFields extends MigrationStep
{
    // Feste Kennungen: Ein zweiter Lauf trifft dieselben Datensätze, und `INSERT IGNORE` lässt sie aus.
    private const PRODUCT_SWITCH_ID = 'f942690d21e84c4dad171a1a599154d1';
    private const CATEGORY_SWITCH_ID = '9ec15e2e368a47f5a0d03c15c619d728';

    // Hinter dem Längenschalter (20); die Kategoriefelder hinter den vorhandenen (bis 7).
    private const PRODUCT_POSITION = 21;
    private const CATEGORY_POSITION = 8;

    public function getCreationTimestamp(): int
    {
        return 1788300000;
    }

    public function update(Connection $connection): void
    {
        $productSetId = $this->setId($connection, DynamicPriceConstants::SET_PRODUCT);
        if ($productSetId !== null) {
            $this->insert($connection, self::PRODUCT_SWITCH_ID, DynamicPriceConstants::FIELD_GUIDED_SELECTION, $this->switchConfig(self::PRODUCT_POSITION), $productSetId);
        }

        $categorySetId = $this->setId($connection, DynamicPriceConstants::SET_CATEGORY);
        if ($categorySetId !== null) {
            $this->insert($connection, self::CATEGORY_SWITCH_ID, DynamicPriceConstants::CAT_FIELD_GUIDED_SELECTION, $this->switchConfig(self::CATEGORY_POSITION), $categorySetId);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function setId(Connection $connection, string $name): ?string
    {
        $id = $connection->fetchOne('SELECT `id` FROM `custom_field_set` WHERE `name` = :name', ['name' => $name]);

        return \is_string($id) ? $id : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function insert(Connection $connection, string $id, string $name, array $config, string $setId): void
    {
        $connection->executeStatement(
            'INSERT IGNORE INTO `custom_field` (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`)
             VALUES (:id, :name, :type, :config, 1, :setId, NOW())',
            [
                'id' => Uuid::fromHexToBytes($id),
                'name' => $name,
                'type' => 'bool',
                'config' => json_encode($config, \JSON_THROW_ON_ERROR),
                'setId' => $setId,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function switchConfig(int $position): array
    {
        return [
            'label' => [
                'de-DE' => 'Geführte Auswahl: erst die Länge',
                'en-GB' => 'Guided selection: length first',
            ],
            'helpText' => [
                'de-DE' => 'Die Produktseite fragt zuerst die Länge ab und zeigt danach nur die Optionen, '
                    . 'die es zu dieser Länge zu kaufen gibt. Preis und Warenkorb erscheinen, wenn die '
                    . 'Variante feststeht. Wirkt nur zusammen mit den Längengruppen. An einer Kategorie '
                    . 'gesetzt, gilt der Haken für alle Produkte darunter.',
                'en-GB' => 'The product page asks for the length first and then shows only the options '
                    . 'available for that length. Price and cart appear once the variant is determined. '
                    . 'Works only together with the length groups. Set on a category, it applies to all '
                    . 'products below.',
            ],
            'componentName' => 'sw-field',
            'customFieldType' => 'checkbox',
            'customFieldPosition' => $position,
            'type' => 'checkbox',
        ];
    }
}
