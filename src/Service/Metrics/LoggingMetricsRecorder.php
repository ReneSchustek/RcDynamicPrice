<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service\Metrics;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Aktivierbares Beispiel: schreibt Kennzahlen in den Plugin-Logkanal `rc_dynamic_price`.
 *
 * Transparenter Decorator um den inneren Recorder (Default: NullMetricsRecorder). Er
 * delegiert immer an den inneren Recorder und schreibt NUR dann zusätzlich ein Log,
 * wenn die Plugin-Config `enableMetrics` aktiv ist. Damit bleibt das Standardverhalten
 * (Toggle aus = Default) nach außen identisch zum NullMetricsRecorder: kein Output.
 *
 * Zum Aufwand: Da der Decorator die Interface-Service-ID ersetzt, liegt er auch bei
 * ausgeschaltetem Toggle im Hot-Path. Der Toggle wird deshalb pro Instanz genau einmal
 * gelesen und danach zwischengespeichert — es bleibt ein Delegations-Aufruf plus
 * Bool-Prüfung. Folge des Cachings: In langlebigen Prozessen (Messenger-Worker) wirkt
 * eine Config-Änderung erst nach Neustart des Workers. Für einen reinen
 * Observability-Schalter ist das der bewusst gewählte Kompromiss.
 *
 * Eine echte StatsD-/UDP-Anbindung ist bewusst nicht enthalten — dieser Logging-Adapter
 * dient als minimales, abhängigkeitsfreies Aktivierungs-Beispiel. Eigene Adapter können
 * das MetricsRecorderInterface analog implementieren und per Decoration einhängen.
 *
 * Fail-Safe: jeder Log-/Config-Zugriff ist gekapselt, sodass Observability-Fehler den
 * Hot-Path (Cart/Seite) niemals beeinflussen.
 */
final class LoggingMetricsRecorder implements MetricsRecorderInterface
{
    /** Einmalig aufgelöster Toggle-Wert; null = noch nicht gelesen. */
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
            // Bewusst verschluckt: ein fehlgeschlagener Metrik-Log darf den Hot-Path nie stören.
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
            // Siehe increment(): Observability-Fehler bleiben folgenlos für Cart/Seite.
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
            // Kann die Config nicht gelesen werden, gilt der sichere Default: Metriken aus.
            // Das Ergebnis wird mitgecacht, damit eine defekte Config den Hot-Path nicht
            // bei jedem Aufruf erneut in den Exception-Pfad zwingt.
            $this->enabled = false;
        }

        return $this->enabled;
    }
}
