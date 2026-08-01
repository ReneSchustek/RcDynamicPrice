<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service\Metrics;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Service\Metrics\MetricsRecorderInterface;
use Ruhrcoder\RcDynamicPrice\Service\Metrics\NullMetricsRecorder;

final class NullMetricsRecorderTest extends TestCase
{
    public function testImplementsRecorderInterface(): void
    {
        $this->assertInstanceOf(MetricsRecorderInterface::class, new NullMetricsRecorder());
    }

    public function testIncrementIsNoOpAndDoesNotThrow(): void
    {
        $recorder = new NullMetricsRecorder();

        // Es gibt keinen beobachtbaren Seiteneffekt — der No-Op darf lediglich nicht werfen.
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
