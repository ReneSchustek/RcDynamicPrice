<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Storefront\Struct;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Storefront\Struct\RcDynamicPriceConfigStruct;

final class RcDynamicPriceConfigStructTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $struct = new RcDynamicPriceConfigStruct('Hinweis', 100, 5000, 'quarter_m');

        $this->assertSame('Hinweis', $struct->getHintText());
        $this->assertSame(100, $struct->getMinLength());
        $this->assertSame(5000, $struct->getMaxLength());
        $this->assertSame('quarter_m', $struct->getRoundingMode());
    }

    public function testRoundingModeDefaultsToNone(): void
    {
        $struct = new RcDynamicPriceConfigStruct('Text', 1, 10000);

        $this->assertSame('none', $struct->getRoundingMode());
    }

    public function testThrowsExceptionWhenMinExceedsMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RcDynamicPriceConfigStruct('Text', 5000, 1000);
    }

    public function testAcceptsEqualMinAndMax(): void
    {
        $struct = new RcDynamicPriceConfigStruct('Text', 1000, 1000);

        $this->assertSame(1000, $struct->getMinLength());
        $this->assertSame(1000, $struct->getMaxLength());
    }

    public function testAcceptsEmptyHintText(): void
    {
        $struct = new RcDynamicPriceConfigStruct('', 1, 10000);

        $this->assertSame('', $struct->getHintText());
    }

    // --- Split-Felder ---

    public function testSplitGettersReturnDefaults(): void
    {
        $struct = new RcDynamicPriceConfigStruct('Hinweis', 1, 10000);

        $this->assertSame('', $struct->getSplitMode());
        $this->assertSame(0, $struct->getMaxPieceLength());
        $this->assertSame('', $struct->getSplitHintTemplate());
    }

    public function testSplitGettersReturnConstructorValues(): void
    {
        $struct = new RcDynamicPriceConfigStruct(
            hintText: 'Text',
            minLength: 1,
            maxLength: 10000,
            roundingMode: 'none',
            splitMode: 'equal',
            maxPieceLength: 5000,
            splitHintTemplate: 'Bitte {pieces} Teile',
        );

        $this->assertSame('equal', $struct->getSplitMode());
        $this->assertSame(5000, $struct->getMaxPieceLength());
        $this->assertSame('Bitte {pieces} Teile', $struct->getSplitHintTemplate());
    }

    public function testThrowsExceptionWhenMaxPieceLengthIsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RcDynamicPriceConfigStruct(
            hintText: 'Text',
            minLength: 1,
            maxLength: 10000,
            roundingMode: 'none',
            splitMode: 'equal',
            maxPieceLength: -1,
        );
    }
}
