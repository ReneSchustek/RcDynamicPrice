<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\RcDynamicPrice;

final class RcDynamicPriceTest extends TestCase
{
    public function testPluginClassExists(): void
    {
        $this->assertTrue(class_exists(RcDynamicPrice::class));
    }
}
