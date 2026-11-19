<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service\LengthStage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Service\LengthStage\LengthInput;
use Ruhrcoder\RcDynamicPrice\Service\LengthStage\LengthStages;

/**
 * Stufen und Längen lesen wie der Längenschalter im Browser (BRIEF65). Die Fälle sind dieselben wie in
 * `tests/Js/length-variant-switch.test.mjs`; weichen Server und Seite ab, sagte die Seite „passt" und der Warenkorb nicht.
 */
final class LengthStagesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, float|null, float|null}>
     */
    public static function labels(): iterable
    {
        yield 'Meter mit Komma' => ['0,2 m', null, 20.0];
        yield 'Meter mit Punkt' => ['1.5 m', null, 150.0];
        yield 'Zentimeter' => ['150 cm', null, 150.0];
        yield 'Millimeter' => ['1500 mm', null, 150.0];
        yield 'ohne Leerzeichen' => ['6,0m', null, 600.0];
        yield 'Bereich' => ['96 - 116 cm', 96.0, 116.0];
        yield 'Bereich mit Gedankenstrich und Umbruch' => ["116 –\n130 cm", 116.0, 130.0];
        yield 'Bereich falsch herum' => ['130 - 116 cm', null, null];
        yield 'Text' => ['Sonderlänge', null, null];
    }

    #[DataProvider('labels')]
    public function testLabels(string $label, ?float $min, ?float $max): void
    {
        $parsed = LengthStages::parseLabel($label);

        if ($max === null) {
            self::assertNull($parsed);

            return;
        }
        self::assertNotNull($parsed);
        self::assertSame($min, $parsed['min']);
        self::assertSame($max, $parsed['max']);
    }

    /**
     * Was: Die Stufen des Rohrs 424100V2A-0, 0,2 bis 6,0 m.
     * Warum: Für 800 mm ist 1,0 m die passende Stufe; 0,2 m reicht nicht.
     */
    public function testTheTubeGetsTheNextLargerStage(): void
    {
        $stages = LengthStages::fromLabels(['0,2 m', '0,5 m', '1,0 m', '1,5 m', '2,0 m', '6,0 m', 'Sonderlänge']);

        self::assertCount(6, $stages);
        self::assertSame('1,0 m', LengthStages::find($stages, 800)?->label);
        self::assertSame('0,2 m', LengthStages::find($stages, 200)?->label);
        self::assertSame('0,2 m', LengthStages::find($stages, 50)?->label);
        self::assertSame('6,0 m', LengthStages::find($stages, 5999)?->label);
        self::assertNull(LengthStages::find($stages, 6001));

        self::assertSame(50.0, $stages[2]->minCentimetres);
        self::assertTrue($stages[2]->fitsMillimetres(1000));
        self::assertFalse($stages[0]->fitsMillimetres(800));
    }

    /**
     * An der Grenze gilt die untere Stufe: 1300 mm gehört zu „116 - 130 cm".
     */
    public function testAtTheBoundaryTheLowerRangeWins(): void
    {
        $stages = LengthStages::fromLabels(['96 - 116 cm', '116 - 130 cm', '130 - 140 cm']);

        self::assertSame('116 - 130 cm', LengthStages::find($stages, 1300)?->label);
        self::assertSame('130 - 140 cm', LengthStages::find($stages, 1301)?->label);
        self::assertNull(LengthStages::find($stages, 900));
    }

    /**
     * @return iterable<string, array{string, list<int>|null}>
     */
    public static function inputs(): iterable
    {
        yield 'Millimeter' => ['800', [800]];
        yield 'Meter mit Komma' => ['4,2', [4200]];
        yield 'Meter mit Einheit' => ['4,2 m', [4200]];
        yield 'Zentimeter mit Leerzeichen' => ['420 cm', [4200]];
        yield 'zwei Längen mit Semikolon' => ['1200; 1300', [1200, 1300]];
        yield 'zwei Längen mit Komma ohne Leerzeichen' => ['1200,1300', [1200, 1300]];
        yield 'zwei Längen mit Leerzeichen' => ['1200 1300', [1200, 1300]];
        yield 'Einheit vor dem Komma' => ['1,2m,800', [1200, 800]];
        yield 'leer' => ['  ', []];
        yield 'Zahl unter 10 ohne Einheit' => ['5', null];
        yield 'Text' => ['bitte anrufen', null];
        yield 'Null' => ['0', null];
    }

    /**
     * @param list<int>|null $expected
     */
    #[DataProvider('inputs')]
    public function testInputs(string $input, ?array $expected): void
    {
        self::assertSame($expected, LengthInput::parse($input));
    }
}
