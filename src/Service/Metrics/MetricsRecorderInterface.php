<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service\Metrics;

/**
 * Optionale Observability-Schnittstelle für Plugin-Kennzahlen.
 *
 * Standard-Implementierung ist der NullMetricsRecorder (No-Op). Über die Plugin-Config
 * `enableMetrics` lässt sich der LoggingMetricsRecorder-Decorator aktivieren. Eigene
 * Adapter (z.B. StatsD) können das Interface implementieren.
 *
 * Fail-Safe-Vertrag: Implementierungen MÜSSEN robust gegen Fehler sein und dürfen
 * NIEMALS eine Exception werfen. Die Aufrufer liegen auf Hot-Paths (Cart-Processing,
 * Seitenrendering) — Observability darf Cart oder Seite nie beeinflussen.
 *
 * Keine PII: Tags sind für niedrigkardinale, technische Dimensionen gedacht
 * (Modus, Rundungsstufe). Kunden- oder Bestelldaten gehören nicht hinein.
 */
interface MetricsRecorderInterface
{
    /**
     * Zählt ein Ereignis hoch (z.B. verarbeitete Meterpositionen, angezeigte Widgets).
     *
     * @param array<string, scalar> $tags Optionale Dimensionen (z.B. ['mode' => 'full_m'])
     */
    public function increment(string $key, array $tags = []): void;

    /**
     * Erfasst eine Dauer in Millisekunden (z.B. Rundungs-Arithmetik).
     *
     * @param array<string, scalar> $tags Optionale Dimensionen
     */
    public function timing(string $key, float $ms, array $tags = []): void;
}
