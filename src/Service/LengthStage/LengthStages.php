<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service\LengthStage;

/**
 * Liest Längenstufen aus den Beschriftungen der Optionen und findet zu einer Länge die passende Stufe.
 *
 * Dieselben Regeln wie der Längenschalter im Browser (`length-variant-switch.plugin.js`: `parseRange()`,
 * `completeFixedSizes()`, `findRange()`); der Server prüft damit, ob eine eingegebene Länge zur gekauften Stufe passt
 * (BRIEF65). Weichen beide voneinander ab, sagte die Seite „passt" und der Warenkorb „passt nicht"; die Tests beider
 * Seiten nutzen deshalb dieselben Fälle.
 */
final class LengthStages
{
    private const CENTIMETRES_PER_UNIT = ['mm' => 0.1, 'cm' => 1.0, 'm' => 100.0];

    /**
     * Stufen aus den Beschriftungen; unlesbare fallen weg. Feste Maße bekommen das nächstkleinere Maß als Untergrenze,
     * das kleinste null.
     *
     * @param list<string> $labels
     *
     * @return list<LengthStage>
     */
    public static function fromLabels(array $labels): array
    {
        $parsed = [];
        foreach ($labels as $label) {
            $range = self::parseLabel($label);
            if ($range !== null) {
                $parsed[] = $range;
            }
        }

        $fixedMaxima = array_column($parsed, 'max');
        sort($fixedMaxima);

        $stages = [];
        foreach ($parsed as $range) {
            $min = $range['min'];
            if ($min === null) {
                $smaller = array_values(array_filter($fixedMaxima, static fn (float $max): bool => $max < $range['max']));
                $min = $smaller === [] ? 0.0 : $smaller[\count($smaller) - 1];
            }
            $stages[] = new LengthStage($range['label'], $min, $range['max']);
        }

        return $stages;
    }

    /**
     * „96 - 116 cm" wird zu min 96, max 116; ein festes Maß wie „1,5 m" zu min `null`, max 150 (Zentimeter).
     *
     * @return array{label: string, min: float|null, max: float}|null
     */
    public static function parseLabel(string $text): ?array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (preg_match('/^(\d+(?:[.,]\d+)?) ?(mm|cm|m)$/iu', $normalized, $fixed) === 1) {
            $max = round((float) str_replace(',', '.', $fixed[1]) * self::CENTIMETRES_PER_UNIT[mb_strtolower($fixed[2])] * 10) / 10;

            return $max > 0 ? ['label' => $normalized, 'min' => null, 'max' => $max] : null;
        }

        if (preg_match('/^(\d+)\s*[-–]\s*(\d+)\s*cm$/iu', $normalized, $range) !== 1) {
            return null;
        }

        $min = (float) $range[1];
        $max = (float) $range[2];

        return $min < $max ? ['label' => $normalized, 'min' => $min, 'max' => $max] : null;
    }

    /**
     * Die Stufe zu einer Länge: die kleinste Obergrenze, die noch reicht. Unter der kleinsten Untergrenze und über der
     * größten Obergrenze gibt es keine.
     *
     * @param list<LengthStage> $stages
     */
    public static function find(array $stages, int $millimetres): ?LengthStage
    {
        $centimetres = $millimetres / 10;
        $found = null;
        $smallest = INF;
        foreach ($stages as $stage) {
            $smallest = min($smallest, $stage->minCentimetres);
            if ($stage->maxCentimetres >= $centimetres && ($found === null || $stage->maxCentimetres < $found->maxCentimetres)) {
                $found = $stage;
            }
        }

        return $centimetres < $smallest ? null : $found;
    }
}
