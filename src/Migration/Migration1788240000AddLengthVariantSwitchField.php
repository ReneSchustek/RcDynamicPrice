<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Ergänzt am Produkt-Set den Haken, mit dem die eingegebene Länge die Variante wählt.
 *
 * Das Feld kommt aus der Erweiterung statt von Hand, weil sein technischer Name in Vorlage und Skript
 * steht. Ein abgetippter Name mit einem fehlenden Buchstaben meldet keinen Fehler, die Automatik tut
 * dann einfach nichts.
 *
 * Es gehört ins bestehende Set; ein zweites wäre eine zweite Karte im Verwaltungsbereich für dieselbe
 * Sache. Die Kennung des Sets wird gelesen statt ein zweites Mal hingeschrieben. Fehlt das Set, etwa weil
 * es jemand gelöscht hat, legt die Migration kein verwaistes Feld an.
 *
 * `componentName: sw-field` mit `customFieldType: checkbox` ist die Beschreibung, die Shopware 6.7 selbst
 * für Haken-Felder speichert und als Haken anzeigt.
 */
final class Migration1788240000AddLengthVariantSwitchField extends MigrationStep
{
    // Feste Kennung: Ein zweiter Lauf trifft denselben Datensatz, und `INSERT IGNORE` lässt ihn aus.
    private const FIELD_ID = '9f2c41d7a5b8470e9c1d63f0a7b2e846';

    public function getCreationTimestamp(): int
    {
        return 1788240000;
    }

    public function update(Connection $connection): void
    {
        $setId = $connection->fetchOne(
            'SELECT `id` FROM `custom_field_set` WHERE `name` = :name',
            ['name' => DynamicPriceConstants::SET_PRODUCT]
        );

        if (!\is_string($setId)) {
            return;
        }

        $connection->executeStatement(
            'INSERT IGNORE INTO `custom_field` (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`)
             VALUES (:id, :name, :type, :config, 1, :setId, NOW())',
            [
                'id' => Uuid::fromHexToBytes(self::FIELD_ID),
                'name' => DynamicPriceConstants::FIELD_LENGTH_VARIANT_SWITCH,
                'type' => 'bool',
                'config' => json_encode([
                    'label' => [
                        'de-DE' => 'Eingegebene Länge wählt die Größe',
                        'en-GB' => 'Entered length selects the size',
                    ],
                    'helpText' => [
                        'de-DE' => 'Trägt der Kunde eine Länge ein, die nicht zur gewählten Größe passt, '
                            . 'stellt der Shop auf die passende Größe um und sagt es ihm. Die Menge folgt der '
                            . 'Zahl der angegebenen Längen. Im Warenkorb steht dann die Länge statt der '
                            . 'Anleitung. Setzt voraus, dass die Größen als Bereiche beschriftet sind '
                            . '(»96 - 116 cm«).',
                        'en-GB' => 'If the customer enters a length that does not match the selected size, '
                            . 'the shop switches to the matching size and says so. The quantity follows the '
                            . 'number of lengths given. Requires the sizes to be labelled as ranges '
                            . '(»96 - 116 cm«).',
                    ],
                    'componentName' => 'sw-field',
                    'customFieldType' => 'checkbox',
                    'customFieldPosition' => 20,
                    'type' => 'checkbox',
                ], \JSON_THROW_ON_ERROR),
                'setId' => $setId,
            ]
        );
    }

    /**
     * Löscht nichts. Ein zerstörender Lauf nähme dem Betreiber die Einstellung an jedem Artikel, an dem er
     * sie gesetzt hat. Ein übrig gebliebenes Feld kostet einen Haken, den niemand anhakt; ein gelöschtes
     * kostet Artikel, die still ihre Einstellung verlieren.
     */
    public function updateDestructive(Connection $connection): void
    {
    }
}
