<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service\Metrics;

/**
 * Die Vorgabe: zählt und misst nichts.
 *
 * Ohne eingeschaltete Metriken (`enableMetrics`, Vorgabe aus) sind die Aufrufe im Warenkorb und auf
 * der Produktseite leere Rümpfe. Der LoggingMetricsRecorder darum herum fügt nur eine
 * zwischengespeicherte Prüfung des Schalters hinzu.
 */
final class NullMetricsRecorder implements MetricsRecorderInterface
{
    /** @param array<string, scalar> $tags */
    public function increment(string $key, array $tags = []): void
    {
    }

    /** @param array<string, scalar> $tags */
    public function timing(string $key, float $ms, array $tags = []): void
    {
    }
}
