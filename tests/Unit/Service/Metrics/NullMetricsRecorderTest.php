<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service\Metrics;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Service\Metrics\MetricsRecorderInterface;
use Ruhrcoder\RcDynamicPrice\Service\Metrics\NullMetricsRecorder;

/**
 * Der leere Rekorder ist die Vorgabe, wenn keine Metrik gewünscht ist; er darf nichts tun und nie
 * werfen.
 */
final class NullMetricsRecorderTest extends TestCase
{
    public function testImplementsRecorderInterface(): void
    {
        $this->assertInstanceOf(MetricsRecorderInterface::class, new NullMetricsRecorder());
    }

    public function testIncrementIsNoOpAndDoesNotThrow(): void
    {
        $recorder = new NullMetricsRecorder();

        // Beobachten lässt sich nichts; geprüft wird nur, dass der Aufruf nicht wirft.
        $recorder->increment('cart.meter_item.processed', ['mode' => 'full_m']);

        $this->expectNotToPerformAssertions();
    }

    public function testTimingIsNoOpAndDoesNotThrow(): void
    {
        $recorder = new NullMetricsRecorder();

        $recorder->timing('rounding.duration_ms', 1.234, ['mode' => 'cm']);

        $this->expectNotToPerformAssertions();
    }
}
