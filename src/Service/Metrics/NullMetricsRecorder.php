<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service\Metrics;

/**
 * Standard-Implementierung: tut bewusst nichts.
 *
 * Ohne aktivierte Metriken (Plugin-Config `enableMetrics` = false, Default) sind die
 * Hot-Path-Aufrufe leere Methoden-Rümpfe. Der umgebende LoggingMetricsRecorder-Decorator
 * ergänzt lediglich eine zwischengespeicherte Bool-Prüfung — das Plugin bleibt ohne
 * Monitoring-Stack praktisch unverändert performant.
 */
final class NullMetricsRecorder implements MetricsRecorderInterface
{
    /** @param array<string, scalar> $tags */
    public function increment(string $key, array $tags = []): void
    {
        // No-Op: keine Metrik-Erfassung im Default.
    }

    /** @param array<string, scalar> $tags */
    public function timing(string $key, float $ms, array $tags = []): void
    {
        // No-Op: keine Metrik-Erfassung im Default.
    }
}
