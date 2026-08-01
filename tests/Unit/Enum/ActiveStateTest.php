<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Enum\ActiveState;

final class ActiveStateTest extends TestCase
{
    public function testBoolTrueIsTreatedAsOnForBackwardCompatibility(): void
    {
        $this->assertSame(ActiveState::On, ActiveState::fromMixed(true));
    }

    public function testBoolFalseFallsBackToInherit(): void
    {
        $this->assertSame(ActiveState::Inherit, ActiveState::fromMixed(false));
    }

    public function testNullFallsBackToInherit(): void
    {
        $this->assertSame(ActiveState::Inherit, ActiveState::fromMixed(null));
    }

    public function testStringOnMapsToOn(): void
    {
        $this->assertSame(ActiveState::On, ActiveState::fromMixed('on'));
    }

    public function testStringOffMapsToOff(): void
    {
        $this->assertSame(ActiveState::Off, ActiveState::fromMixed('off'));
    }

    public function testStringInheritMapsToInherit(): void
    {
        $this->assertSame(ActiveState::Inherit, ActiveState::fromMixed('inherit'));
    }

    public function testMixedCaseIsNormalised(): void
    {
        $this->assertSame(ActiveState::On, ActiveState::fromMixed('ON'));
        $this->assertSame(ActiveState::Off, ActiveState::fromMixed(' Off '));
    }

    public function testUnknownStringsFallBackToInherit(): void
    {
        $this->assertSame(ActiveState::Inherit, ActiveState::fromMixed('maybe'));
    }

    public function testEmptyStringFallsBackToInherit(): void
    {
        $this->assertSame(ActiveState::Inherit, ActiveState::fromMixed(''));
    }
}
