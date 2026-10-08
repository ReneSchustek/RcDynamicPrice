<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Ersetzt in Beschriftung und Hilfetext der Felder zum Aufteilen und der Kategoriefelder die
 * Ersatzschreibung (`ae`, `ue`, `ss`) durch Umlaute und ß, in Shops, in denen die Felder bisher so
 * angelegt sind.
 *
 * Geändert werden nur Texte unter `de-DE`. Geschrieben wird nur, wenn die bereinigte Konfiguration
 * anders aussieht als die gespeicherte; ein zweiter Lauf schreibt deshalb nichts mehr.
 */
final class Migration1745700000FixCustomFieldLabelsUmlauts extends MigrationStep
{
    /**
     * Die Felder, die mit Ersatzschreibung angelegt sein können: aus den Migrationen 1745200000,
     * 1745400000 und 1745500000. Die älteren Produktfelder (Meterpreis, Mindest- und Höchstlänge) sind mit
     * Umlauten angelegt und fehlen hier deshalb.
     *
     * @var list<string>
     */
    private const AFFECTED_FIELDS = [
        // Produktfelder aus Migration1745200000
        'rc_meter_price_split_mode',
        'rc_meter_price_max_piece_length',
        'rc_meter_price_split_hint',
        // Kategoriefelder aus Migration1745500000
        'rc_meter_price_cat_min_length',
        'rc_meter_price_cat_max_length',
        'rc_meter_price_cat_rounding',
        'rc_meter_price_cat_split_mode',
        'rc_meter_price_cat_max_piece_length',
        'rc_meter_price_cat_split_hint',
    ];

    /**
     * Ersatzschreibung und richtige Schreibung, ganze Texte und einzelne Begriffe. Die Reihenfolge
     * entscheidet nichts: `strtr()` mit einem Feld nimmt an jeder Stelle den längsten passenden Schlüssel,
     * ein Begriff innerhalb eines ganzen Textes wird also nicht vorher einzeln ersetzt.
     *
     * @var array<string, string>
     */
    private const REPLACEMENTS = [
        // Ganze Texte
        'Split-Modus fuer Langstuecke' => 'Split-Modus für Langstücke',
        'Wie wird eine Eingabe oberhalb der maximalen Teilstuecklaenge behandelt?'
            => 'Wie wird eine Eingabe oberhalb der maximalen Teilstücklänge behandelt?',
        'Mindestlaenge fuer alle Produkte dieser Kategorie, sofern am Produkt nichts gesetzt ist.'
            => 'Mindestlänge für alle Produkte dieser Kategorie, sofern am Produkt nichts gesetzt ist.',
        'Maximallaenge fuer alle Produkte dieser Kategorie, sofern am Produkt nichts gesetzt ist.'
            => 'Maximallänge für alle Produkte dieser Kategorie, sofern am Produkt nichts gesetzt ist.',
        'Aufrundung der Kundenlaenge auf volle Einheiten.' => 'Aufrundung der Kundenlänge auf volle Einheiten.',
        'Behandlung langer Eingaben oberhalb der Teilstuecklaenge.'
            => 'Behandlung langer Eingaben oberhalb der Teilstücklänge.',
        'Ab welcher Laenge werden Eingaben aufgeteilt. Leer = kein Splitting.'
            => 'Ab welcher Länge werden Eingaben aufgeteilt. Leer = kein Splitting.',
        'Ab welcher Laenge greift das Splitting. Leer = kein Splitting.'
            => 'Ab welcher Länge greift das Splitting. Leer = kein Splitting.',
        // Einzelbegriffe
        'Gleichmaessig aufteilen' => 'Gleichmäßig aufteilen',
        'Volle Stuecke plus Rest' => 'Volle Stücke plus Rest',
        'Max. Teilstuecklaenge (mm)' => 'Max. Teilstücklänge (mm)',
        'Mindestlaenge (mm)' => 'Mindestlänge (mm)',
        'Maximallaenge (mm)' => 'Maximallänge (mm)',
        'Hinweistext fuer Splitting' => 'Hinweistext für Splitting',
    ];

    public function getCreationTimestamp(): int
    {
        return 1745700000;
    }

    public function update(Connection $connection): void
    {
        foreach (self::AFFECTED_FIELDS as $fieldName) {
            $this->fixFieldLabels($connection, $fieldName);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function fixFieldLabels(Connection $connection, string $fieldName): void
    {
        $row = $connection->fetchAssociative(
            'SELECT `id`, `config` FROM `custom_field` WHERE `name` = :name',
            ['name' => $fieldName]
        );

        if ($row === false) {
            return;
        }

        $rawConfig = (string) $row['config'];

        $decoded = json_decode($rawConfig, true);
        if (!\is_array($decoded)) {
            return;
        }

        $repaired = $this->replaceInDeDeStrings($decoded);

        $newConfig = json_encode($repaired, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        if ($newConfig === $rawConfig) {
            // Nichts ersetzt und gleich kodiert: kein Schreiben, ein zweiter Lauf bleibt folgenlos.
            return;
        }

        $connection->executeStatement(
            'UPDATE `custom_field` SET `config` = :config, `updated_at` = NOW() WHERE `id` = :id',
            [
                'config' => $newConfig,
                'id'     => $row['id'],
            ]
        );
    }

    /**
     * Geht die Konfiguration durch und ersetzt in jedem Text unter einem Schlüssel `de-DE`. Englische Texte
     * und sprachunabhängige Werte wie `componentName` oder `value` bleiben, wie sie sind.
     *
     * @param array<int|string, mixed> $node
     *
     * @return array<int|string, mixed>
     */
    private function replaceInDeDeStrings(array $node): array
    {
        foreach ($node as $key => $value) {
            if (\is_array($value)) {
                $node[$key] = $this->replaceInDeDeStrings($value);
                continue;
            }

            if ($key === 'de-DE' && \is_string($value)) {
                $node[$key] = strtr($value, self::REPLACEMENTS);
            }
        }

        return $node;
    }
}
