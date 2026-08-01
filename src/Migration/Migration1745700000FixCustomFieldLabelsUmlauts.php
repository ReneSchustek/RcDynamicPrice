<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Heilt bestehende Installationen: ersetzt in den Admin-Labels und HelpTexts der Splitting- und
 * Kategorie-Custom-Felder die historischen Ersatzschreibweisen `ae`/`ue`/`ss` durch korrekte Umlaute.
 *
 * Betrifft ausschließlich `de-DE`-Strings. Englische Labels bleiben unverändert.
 *
 * Idempotent: Felder, deren Config bereits die korrekten Umlaute enthält, werden ohne UPDATE
 * übersprungen — Pre-Hash auf der ursprünglichen Config-Spalte vergleicht sich mit dem Post-Replace
 * und schreibt nur, wenn sich tatsächlich etwas geändert hat.
 */
final class Migration1745700000FixCustomFieldLabelsUmlauts extends MigrationStep
{
    /**
     * Custom-Field-Namen, die Ersatzschreibweisen-Lasten tragen können (Migrations 1745200000,
     * 1745400000, 1745500000). Andere Felder (Produkt-Active, Min/Max am Produkt aus 1743*) wurden
     * historisch direkt mit korrekten Umlauten erzeugt und brauchen keine Heilung.
     *
     * @var list<string>
     */
    private const AFFECTED_FIELDS = [
        // Produkt-Custom-Fields aus Migration1745200000
        'rc_meter_price_split_mode',
        'rc_meter_price_max_piece_length',
        'rc_meter_price_split_hint',
        // Kategorie-Custom-Fields aus Migration1745500000
        'rc_meter_price_cat_min_length',
        'rc_meter_price_cat_max_length',
        'rc_meter_price_cat_rounding',
        'rc_meter_price_cat_split_mode',
        'rc_meter_price_cat_max_piece_length',
        'rc_meter_price_cat_split_hint',
    ];

    /**
     * Ersetzungs-Tabelle (Ersatzschreibweise → Korrektur). Wird ausschließlich auf
     * `de-DE`-Strings angewandt. Reihenfolge so gewählt, dass längere Phrasen zuerst greifen,
     * damit kein Teilersatz innerhalb eines bereits korrigierten Worts entsteht.
     *
     * @var array<string, string>
     */
    private const REPLACEMENTS = [
        // Mehrwort-Phrasen zuerst
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
            // Idempotenz: Config hat keine Ersatzschreibweisen mehr — kein UPDATE nötig.
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
     * Walks the config tree and applies the replacement table to every string that lives under a
     * `de-DE` key. English strings (`en-GB`) and locale-unabhängige Schlüssel (`componentName`,
     * `customFieldType`, `customFieldPosition`, `value`, …) bleiben unangetastet.
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
