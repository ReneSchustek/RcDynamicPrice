<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service\Metrics;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Schreibt Kennzahlen in den Protokollkanal `rc_dynamic_price`, wenn `enableMetrics` eingeschaltet
 * ist.
 *
 * Er dekoriert den inneren Recorder (Vorgabe: NullMetricsRecorder), ruft ihn immer auf und
 * protokolliert nur bei eingeschaltetem Schalter zusätzlich. Ausgeschaltet verhält er sich nach
 * außen wie der NullMetricsRecorder.
 *
 * Weil er die Kennung der Schnittstelle übernimmt, liegt er auch ausgeschaltet im Warenkorb und auf
 * der Produktseite. Der Schalter wird deshalb je Instanz einmal gelesen und gemerkt; übrig bleiben
 * ein Aufruf und eine Prüfung. In langlebigen Prozessen wie einem Messenger-Worker wirkt eine
 * Änderung des Schalters deshalb erst nach dessen Neustart, für einen Schalter der Beobachtung ein
 * tragbarer Preis.
 *
 * Eine Anbindung an StatsD oder UDP fehlt absichtlich; dieser Recorder ist das kleinste Beispiel ohne
 * Abhängigkeiten. Eigene Anbindungen setzen die Schnittstelle um und hängen sich ebenso als
 * Dekorierer ein.
 *
 * Jeder Zugriff auf Protokoll und Einstellung ist gekapselt; ein Fehler darin erreicht weder
 * Warenkorb noch Seite.
 */
final class LoggingMetricsRecorder implements MetricsRecorderInterface
{
    /** Der einmal gelesene Schalter; `null` heißt: noch nicht gelesen. */
    private ?bool $enabled = null;

    public function __construct(
        private readonly MetricsRecorderInterface $inner,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, scalar> $tags */
    public function increment(string $key, array $tags = []): void
    {
        $this->inner->increment($key, $tags);

        if (!$this->isEnabled()) {
            return;
        }

        try {
            $this->logger->info('rc_dynamic_price metric increment: {metricKey}', [
                'metricKey' => $key,
                'tags' => $tags,
            ]);
        } catch (\Throwable) {
            // Verschluckt: Ein gescheitertes Protokoll einer Kennzahl darf den Warenkorb nicht stören.
        }
    }

    /** @param array<string, scalar> $tags */
    public function timing(string $key, float $ms, array $tags = []): void
    {
        $this->inner->timing($key, $ms, $tags);

        if (!$this->isEnabled()) {
            return;
        }

        try {
            $this->logger->info('rc_dynamic_price metric timing: {metricKey} {metricMs}ms', [
                'metricKey' => $key,
                'metricMs' => $ms,
                'tags' => $tags,
            ]);
        } catch (\Throwable) {
            // Wie in increment(): Der Fehler bleibt folgenlos für Warenkorb und Seite.
        }
    }

    private function isEnabled(): bool
    {
        if ($this->enabled !== null) {
            return $this->enabled;
        }

        try {
            $this->enabled = $this->systemConfigService->getBool(DynamicPriceConstants::CONFIG_ENABLE_METRICS);
        } catch (\Throwable) {
            // Lässt sich die Einstellung nicht lesen, bleiben die Metriken aus. Auch das wird gemerkt,
            // sonst liefe jeder weitere Aufruf erneut in die Ausnahme.
            $this->enabled = false;
        }

        return $this->enabled;
    }
}
