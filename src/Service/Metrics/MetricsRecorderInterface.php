<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service\Metrics;

/**
 * Optionale Kennzahlen der Erweiterung.
 *
 * Die Vorgabe ist der NullMetricsRecorder, der nichts tut. Mit `enableMetrics` schreibt der
 * LoggingMetricsRecorder zusätzlich ins Protokoll; eigene Anbindungen, etwa an StatsD, können die
 * Schnittstelle ebenso umsetzen.
 *
 * Eine Umsetzung wirft nie eine Exception. Die Aufrufer liegen im Warenkorb und auf der Produktseite,
 * und eine Kennzahl darf dort nichts kaputt machen.
 *
 * Tags sind für wenige technische Ausprägungen gedacht (Modus, Rundungsstufe). Kunden- und
 * Bestelldaten gehören nicht hinein.
 */
interface MetricsRecorderInterface
{
    /**
     * Zählt ein Ereignis, etwa eine verarbeitete Meterposition oder ein angezeigtes Widget.
     *
     * @param array<string, scalar> $tags etwa `['mode' => 'full_m']`
     */
    public function increment(string $key, array $tags = []): void;

    /**
     * Erfasst eine Dauer in Millisekunden, etwa die der Rundung.
     *
     * @param array<string, scalar> $tags
     */
    public function timing(string $key, float $ms, array $tags = []): void;
}
