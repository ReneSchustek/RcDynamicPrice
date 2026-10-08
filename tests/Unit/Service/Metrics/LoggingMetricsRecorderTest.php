<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service\Metrics;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\Metrics\LoggingMetricsRecorder;
use Ruhrcoder\RcDynamicPrice\Service\Metrics\MetricsRecorderInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Der protokollierende Metrik-Rekorder liegt in jedem Warenkorb-Durchlauf. Er darf dort weder
 * werfen noch bei jedem Aufruf die Einstellungen neu lesen, auch wenn das Protokoll aus ist.
 */
final class LoggingMetricsRecorderTest extends TestCase
{
    private MetricsRecorderInterface&MockObject $inner;
    private SystemConfigService&MockObject $systemConfig;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(MetricsRecorderInterface::class);
        $this->systemConfig = $this->createMock(SystemConfigService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testDelegatesToInnerButDoesNotLogWhenDisabled(): void
    {
        $this->systemConfig
            ->method('getBool')
            ->with(DynamicPriceConstants::CONFIG_ENABLE_METRICS)
            ->willReturn(false);

        $this->inner->expects($this->once())->method('increment')->with('key', ['a' => 'b']);
        $this->logger->expects($this->never())->method('info');

        $this->recorder()->increment('key', ['a' => 'b']);
    }

    public function testDelegatesAndLogsIncrementWhenEnabled(): void
    {
        $this->systemConfig->method('getBool')->willReturn(true);

        $this->inner->expects($this->once())->method('increment')->with('key', []);
        $this->logger->expects($this->once())->method('info');

        $this->recorder()->increment('key');
    }

    public function testDelegatesToInnerButDoesNotLogTimingWhenDisabled(): void
    {
        $this->systemConfig->method('getBool')->willReturn(false);

        $this->inner->expects($this->once())->method('timing')->with('key', 12.5, []);
        $this->logger->expects($this->never())->method('info');

        $this->recorder()->timing('key', 12.5);
    }

    public function testDelegatesAndLogsTimingWhenEnabled(): void
    {
        $this->systemConfig->method('getBool')->willReturn(true);

        $this->inner->expects($this->once())->method('timing')->with('key', 12.5, ['mode' => 'cm']);
        $this->logger->expects($this->once())->method('info');

        $this->recorder()->timing('key', 12.5, ['mode' => 'cm']);
    }

    public function testTogglesIsReadOnlyOncePerInstance(): void
    {
        // Der Rekorder liegt auch bei ausgeschaltetem Protokoll im Warenkorb-Durchlauf; die
        // Einstellung wird deshalb nur einmal je Instanz gelesen.
        $this->systemConfig
            ->expects($this->once())
            ->method('getBool')
            ->with(DynamicPriceConstants::CONFIG_ENABLE_METRICS)
            ->willReturn(false);

        $recorder = $this->recorder();
        $recorder->increment('key');
        $recorder->increment('key');
        $recorder->timing('key', 1.0);
        $recorder->timing('key', 2.0);
    }

    public function testConfigFailureIsNotRetriedOnEveryCall(): void
    {
        // Wirft das Lesen der Einstellung, wird es nicht bei jedem Aufruf wiederholt.
        $this->systemConfig
            ->expects($this->once())
            ->method('getBool')
            ->willThrowException(new \RuntimeException('config down'));

        $this->logger->expects($this->never())->method('info');

        $recorder = $this->recorder();
        $recorder->increment('key');
        $recorder->increment('key');
    }

    public function testLoggerFailureNeverPropagates(): void
    {
        // Ein werfender Logger erreicht den Aufrufer nie; die Metrik ist Beiwerk, der Warenkorb nicht.
        $this->systemConfig->method('getBool')->willReturn(true);
        $this->logger->method('info')->willThrowException(new \RuntimeException('boom'));

        $this->recorder()->increment('key');
        $this->recorder()->timing('key', 1.0);

        $this->expectNotToPerformAssertions();
    }

    public function testConfigFailureFallsBackToDisabled(): void
    {
        // Lässt sich die Einstellung nicht lesen, bleiben die Metriken aus.
        $this->systemConfig->method('getBool')->willThrowException(new \RuntimeException('config down'));

        $this->inner->expects($this->once())->method('increment')->with('key', []);
        $this->logger->expects($this->never())->method('info');

        $this->recorder()->increment('key');
    }

    private function recorder(): LoggingMetricsRecorder
    {
        return new LoggingMetricsRecorder($this->inner, $this->systemConfig, $this->logger);
    }
}
