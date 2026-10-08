<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Exception\DynamicPriceException;
use Ruhrcoder\RcDynamicPrice\Service\LengthSplitter;

/**
 * Der Splitter teilt zu lange Zuschnitte in Teilstücke, die die Fertigung schneidet. Ein Fehler
 * hier ist ein falscher Zuschnitt; die Fälle teilt er sich mit dem Skript der Produktseite über
 * `tests/Fixtures/split-cases.json`, damit Vorschau und Warenkorb gleich rechnen.
 */
final class LengthSplitterTest extends TestCase
{
    private LengthSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new LengthSplitter();
    }

    // Grundverhalten

    public function testReturnsSingleLengthWhenBelowOrAtMaxPiece(): void
    {
        $this->assertSame([4000], $this->splitter->split(4000, 5000, SplitMode::Equal));
        $this->assertSame([5000], $this->splitter->split(5000, 5000, SplitMode::Equal));
    }

    public function testReturnsSingleLengthWhenMaxPieceIsZero(): void
    {
        $this->assertSame([8000], $this->splitter->split(8000, 0, SplitMode::Equal));
    }

    public function testReturnsSingleLengthWhenMaxPieceIsNegative(): void
    {
        $this->assertSame([8000], $this->splitter->split(8000, -100, SplitMode::Equal));
    }

    public function testReturnsSingleLengthWhenModeIsNull(): void
    {
        $this->assertSame([8000], $this->splitter->split(8000, 5000, null));
    }

    public function testReturnsSingleLengthWhenModeIsHint(): void
    {
        $this->assertSame([8000], $this->splitter->split(8000, 5000, SplitMode::Hint));
    }

    // Modus equal

    public function testEqualSplitsExactlyDivisibleIntoTwoEqualPieces(): void
    {
        $this->assertSame([4000, 4000], $this->splitter->split(8000, 5000, SplitMode::Equal));
    }

    public function testEqualSplitsThreePiecesForLargeLength(): void
    {
        $this->assertSame([4750, 4750, 4750], $this->splitter->split(14250, 5000, SplitMode::Equal));
    }

    public function testEqualDefaultBillingRoundsEachPieceUp(): void
    {
        // Vorgabe `cut_length`: Jedes Teil bekommt dieselbe aufgerundete Schnittlänge, und die
        // Summe darf die Eingabe übersteigen (3 × 3334 = 10002).
        $this->assertSame([3334, 3334, 3334], $this->splitter->split(10001, 5000, SplitMode::Equal));
    }

    public function testEqualExactBillingSumEqualsInputLength(): void
    {
        // Mit `exact` wird nicht aufgerundet; die Summe ist die Eingabe.
        $billing = DynamicPriceConstants::EQUAL_BILLING_EXACT;
        foreach ([10001, 7333, 12500, 99999, 6001] as $total) {
            $pieces = $this->splitter->split($total, 5000, SplitMode::Equal, $billing);
            $this->assertSame($total, array_sum($pieces), "Summe muss exakt {$total} sein");
        }
    }

    public function testEqualExactBillingPiecesDifferByAtMostOneMillimetre(): void
    {
        $pieces = $this->splitter->split(10001, 5000, SplitMode::Equal, DynamicPriceConstants::EQUAL_BILLING_EXACT);

        $this->assertLessThanOrEqual(1, max($pieces) - min($pieces));
    }

    /**
     * Der Splitter liefert Schnittlängen. Ein Teilstück unter der Mindestlänge wird geschnitten wie
     * berechnet; die Mindestlänge ist eine Abrechnungsregel und wirkt erst im Prozessor. Höbe der
     * Splitter das Stück an, bekäme der Kunde mehr Material, als er bestellt hat.
     */
    public function testEqualCutsShortPiecesAtTheirCalculatedLength(): void
    {
        $pieces = $this->splitter->split(1100, 1000, SplitMode::Equal, DynamicPriceConstants::EQUAL_BILLING_EXACT);

        $this->assertSame([550, 550], $pieces);
    }

    public function testEqualKeepsAllPiecesBelowOrAtMaxPiece(): void
    {
        $pieces = $this->splitter->split(12000, 5000, SplitMode::Equal);

        foreach ($pieces as $piece) {
            $this->assertLessThanOrEqual(5000, $piece);
        }
    }

    public function testEqualProducesExpectedPieceCountForExactMultiple(): void
    {
        $this->assertSame([5000, 5000], $this->splitter->split(10000, 5000, SplitMode::Equal));
    }

    // Modus max_rest

    public function testMaxRestSplitsIntoFullPiecesPlusRemainder(): void
    {
        $this->assertSame([5000, 3000], $this->splitter->split(8000, 5000, SplitMode::MaxRest));
    }

    public function testMaxRestProducesTwoFullPiecesWhenExactMultiple(): void
    {
        $this->assertSame([5000, 5000], $this->splitter->split(10000, 5000, SplitMode::MaxRest));
    }

    /**
     * Das Reststück behält seine tatsächliche Länge, auch unter der Mindestlänge.
     *
     * Der Fall aus der Praxis: 5.100 mm bei 5.000 mm Höchstmaß und 1.000 mm Mindestlänge. Die
     * Fertigung schneidet 5.000 + 100 mm; die Mindestlänge wird nur berechnet (siehe
     * `DynamicPriceProcessor`). Mit angehobenem Rest bekäme der Kunde 900 mm zu viel.
     */
    public function testMaxRestCutsTheRemainderAtItsActualLength(): void
    {
        $this->assertSame([5000, 100], $this->splitter->split(5100, 5000, SplitMode::MaxRest));
        $this->assertSame([5000, 1000], $this->splitter->split(6000, 5000, SplitMode::MaxRest));
    }

    public function testMaxRestDoesNotBumpRemainderAboveItsNaturalValue(): void
    {
        // Der Rest von 3000 mm liegt über der Mindestlänge von 1000 mm und bleibt, wie er ist.
        $this->assertSame([5000, 3000], $this->splitter->split(8000, 5000, SplitMode::MaxRest));
    }

    public function testMaxRestWithThreeFullPiecesPlusRemainder(): void
    {
        $this->assertSame([5000, 5000, 5000, 2000], $this->splitter->split(17000, 5000, SplitMode::MaxRest));
    }

    public function testMaxRestUsesAtLeastOneAsMinimumFloor(): void
    {
        // Eine Mindestlänge von 0 darf nicht zu einem Reststück von 0 mm führen.
        $this->assertSame([5000, 1000], $this->splitter->split(6000, 5000, SplitMode::MaxRest));
    }

    // Fehlerfälle

    public function testThrowsOnZeroTotal(): void
    {
        $this->expectException(DynamicPriceException::class);
        $this->expectExceptionCode(0);

        try {
            $this->splitter->split(0, 5000, SplitMode::Equal);
        } catch (DynamicPriceException $e) {
            $this->assertSame(DynamicPriceException::CODE_INVALID_TOTAL_LENGTH, $e->getErrorCode());
            throw $e;
        }
    }

    public function testThrowsOnNegativeTotal(): void
    {
        $this->expectException(DynamicPriceException::class);

        try {
            $this->splitter->split(-100, 5000, SplitMode::Equal);
        } catch (DynamicPriceException $e) {
            $this->assertSame(DynamicPriceException::CODE_INVALID_TOTAL_LENGTH, $e->getErrorCode());
            throw $e;
        }
    }

    public function testThrowsOnTotalAboveSupportedMaximum(): void
    {
        $this->expectException(DynamicPriceException::class);
        $this->expectExceptionMessageMatches('/überschreitet unterstütztes Maximum/');

        try {
            $this->splitter->split(LengthSplitter::MAX_TOTAL_MM + 1, 5000, SplitMode::Equal);
        } catch (DynamicPriceException $e) {
            $this->assertSame(DynamicPriceException::CODE_TOTAL_LENGTH_EXCEEDS_MAXIMUM, $e->getErrorCode());
            throw $e;
        }
    }

    public function testAcceptsTotalAtExactMaximum(): void
    {
        // Genau der Grenzwert wird noch angenommen.
        $result = $this->splitter->split(LengthSplitter::MAX_TOTAL_MM, 5000, SplitMode::Equal);
        $this->assertNotEmpty($result);
    }

    /**
     * Wächter der Geschwindigkeit: der schlimmste erlaubte Fall, eine Million Teilstücke. Gemessen
     * sind rund 13 ms (`benchmarks/LengthSplitterBench.php`); die Grenze von einer Sekunde ist
     * großzügig, damit der Test nicht bei jeder Schwankung anschlägt, und fängt doch einen Umbau,
     * der aus der linearen Rechnung eine quadratische macht.
     */
    public function testTheWorstAllowedCaseStaysFast(): void
    {
        $start = hrtime(true);
        $result = $this->splitter->split(LengthSplitter::MAX_TOTAL_MM, 1, SplitMode::MaxRest);
        $milliseconds = (hrtime(true) - $start) / 1_000_000;

        $this->assertCount(LengthSplitter::MAX_TOTAL_MM, $result);
        $this->assertLessThan(1000, $milliseconds);
    }

    // Kein Teilstück ist länger als das Höchstmaß, über eine Matrix von Längen geprüft.

    #[DataProvider('provideEqualInvariantCases')]
    public function testEqualPiecesNeverExceedMax(int $total, int $max): void
    {
        $pieces = $this->splitter->split($total, $max, SplitMode::Equal);

        foreach ($pieces as $piece) {
            $this->assertLessThanOrEqual(
                $max,
                $piece,
                \sprintf('Teilstück %d darf maxPiece %d nicht überschreiten (total=%d)', $piece, $max, $total)
            );
        }
    }

    /** @return array<string, array{int, int}> */
    public static function provideEqualInvariantCases(): array
    {
        return [
            'exactly_above_max' => [10001, 5000],
            'one_below_max' => [4999, 5000],
            'tiny_steps' => [100, 1],
            'very_large_small_max' => [99999, 10000],
            'exact_double' => [10000, 5000],
            'prime_numbers' => [7919, 1009],
            'barely_overspill' => [10001, 10000],
            'at_service_max' => [LengthSplitter::MAX_TOTAL_MM, 5000],
        ];
    }

    // Dieselben Fälle prüft das Skript der Produktseite gegen `tests/Fixtures/split-cases.json`.

    /**
     * @param list<int> $expected
     */
    #[DataProvider('provideFixtureCases')]
    public function testMatchesSharedFixture(int $total, int $maxPiece, int $min, string $mode, array $expected, string $equalBilling, bool $enforceMin): void
    {
        $splitMode = SplitMode::tryFromString($mode);
        $result = $this->splitter->split($total, $maxPiece, $splitMode, $equalBilling);

        $this->assertSame($expected, $result);
    }

    /** @return iterable<string, array{int, int, int, string, list<int>, string, bool}> */
    public static function provideFixtureCases(): iterable
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/split-cases.json'),
            true,
            flags: \JSON_THROW_ON_ERROR
        );

        foreach ($fixture['cases'] as $case) {
            yield $case['name'] => [
                (int) $case['total'],
                (int) $case['maxPiece'],
                (int) $case['min'],
                (string) $case['mode'],
                array_values(array_map('intval', $case['expected'])),
                (string) ($case['equalBilling'] ?? 'cut_length'),
                (bool) ($case['enforceMin'] ?? true),
            ];
        }
    }

    // SplitMode::tryFromString

    public function testSplitModeTryFromStringAcceptsValidValue(): void
    {
        $this->assertSame(SplitMode::Equal, SplitMode::tryFromString('equal'));
        $this->assertSame(SplitMode::MaxRest, SplitMode::tryFromString('max_rest'));
        $this->assertSame(SplitMode::Hint, SplitMode::tryFromString('hint'));
    }

    public function testSplitModeTryFromStringReturnsNullForInvalidValue(): void
    {
        $this->assertNull(SplitMode::tryFromString('invalid'));
        $this->assertNull(SplitMode::tryFromString(''));
        $this->assertNull(SplitMode::tryFromString(null));
        $this->assertNull(SplitMode::tryFromString(42));
    }
}
