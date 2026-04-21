<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Service\LengthSplitter;

final class LengthSplitterTest extends TestCase
{
    private LengthSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new LengthSplitter();
    }

    // --- Grundverhalten ---

    public function testReturnsSingleLengthWhenBelowOrAtMaxPiece(): void
    {
        $this->assertSame([4000], $this->splitter->split(4000, 5000, 1, SplitMode::Equal));
        $this->assertSame([5000], $this->splitter->split(5000, 5000, 1, SplitMode::Equal));
    }

    public function testReturnsSingleLengthWhenMaxPieceIsZero(): void
    {
        $this->assertSame([8000], $this->splitter->split(8000, 0, 1, SplitMode::Equal));
    }

    public function testReturnsSingleLengthWhenMaxPieceIsNegative(): void
    {
        $this->assertSame([8000], $this->splitter->split(8000, -100, 1, SplitMode::Equal));
    }

    public function testReturnsSingleLengthWhenModeIsNull(): void
    {
        $this->assertSame([8000], $this->splitter->split(8000, 5000, 1, null));
    }

    public function testReturnsSingleLengthWhenModeIsHint(): void
    {
        $this->assertSame([8000], $this->splitter->split(8000, 5000, 1, SplitMode::Hint));
    }

    // --- Modus equal ---

    public function testEqualSplitsExactlyDivisibleIntoTwoEqualPieces(): void
    {
        $this->assertSame([4000, 4000], $this->splitter->split(8000, 5000, 1, SplitMode::Equal));
    }

    public function testEqualSplitsThreePiecesForLargeLength(): void
    {
        $this->assertSame([4750, 4750, 4750], $this->splitter->split(14250, 5000, 1, SplitMode::Equal));
    }

    public function testEqualSplitsThreePiecesWhenTwoWouldExceedMax(): void
    {
        $this->assertSame([3334, 3334, 3334], $this->splitter->split(10001, 5000, 1, SplitMode::Equal));
    }

    public function testEqualKeepsAllPiecesBelowOrAtMaxPiece(): void
    {
        $pieces = $this->splitter->split(12000, 5000, 1, SplitMode::Equal);

        foreach ($pieces as $piece) {
            $this->assertLessThanOrEqual(5000, $piece);
        }
    }

    public function testEqualProducesExpectedPieceCountForExactMultiple(): void
    {
        $this->assertSame([5000, 5000], $this->splitter->split(10000, 5000, 1, SplitMode::Equal));
    }

    // --- Modus max_rest ---

    public function testMaxRestSplitsIntoFullPiecesPlusRemainder(): void
    {
        $this->assertSame([5000, 3000], $this->splitter->split(8000, 5000, 1, SplitMode::MaxRest));
    }

    public function testMaxRestProducesTwoFullPiecesWhenExactMultiple(): void
    {
        $this->assertSame([5000, 5000], $this->splitter->split(10000, 5000, 1, SplitMode::MaxRest));
    }

    public function testMaxRestBumpsRemainderToMinWhenBelowMinLength(): void
    {
        // 6000 / 5000 = 1 Stueck + 1000 mm Rest; Min ist 2000 → Rest wird auf 2000 angehoben
        $this->assertSame([5000, 2000], $this->splitter->split(6000, 5000, 2000, SplitMode::MaxRest));
    }

    public function testMaxRestDoesNotBumpRemainderAboveItsNaturalValue(): void
    {
        // Rest 3000 >= Min 1000 → unveraendert
        $this->assertSame([5000, 3000], $this->splitter->split(8000, 5000, 1000, SplitMode::MaxRest));
    }

    public function testMaxRestWithThreeFullPiecesPlusRemainder(): void
    {
        $this->assertSame([5000, 5000, 5000, 2000], $this->splitter->split(17000, 5000, 1, SplitMode::MaxRest));
    }

    public function testMaxRestUsesAtLeastOneAsMinimumFloor(): void
    {
        // Absurder Min-Wert 0 darf nicht zu einem 0-Rest fuehren
        $this->assertSame([5000, 1000], $this->splitter->split(6000, 5000, 0, SplitMode::MaxRest));
    }

    // --- Fehlerfaelle ---

    public function testThrowsOnZeroTotal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->splitter->split(0, 5000, 1, SplitMode::Equal);
    }

    public function testThrowsOnNegativeTotal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->splitter->split(-100, 5000, 1, SplitMode::Equal);
    }

    public function testThrowsOnTotalAboveSupportedMaximum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ueberschreitet unterstuetztes Maximum/');

        $this->splitter->split(LengthSplitter::MAX_TOTAL_MM + 1, 5000, 1, SplitMode::Equal);
    }

    public function testAcceptsTotalAtExactMaximum(): void
    {
        // Genau der Grenzwert muss noch akzeptiert werden
        $result = $this->splitter->split(LengthSplitter::MAX_TOTAL_MM, 5000, 1, SplitMode::Equal);
        $this->assertNotEmpty($result);
    }

    // --- Invariante: alle Teilstuecke <= maxPiece (Datenprovider-Matrix) ---

    #[DataProvider('provideEqualInvariantCases')]
    public function testEqualPiecesNeverExceedMax(int $total, int $max): void
    {
        $pieces = $this->splitter->split($total, $max, 1, SplitMode::Equal);

        foreach ($pieces as $piece) {
            $this->assertLessThanOrEqual(
                $max,
                $piece,
                \sprintf('Teilstueck %d darf maxPiece %d nicht ueberschreiten (total=%d)', $piece, $max, $total)
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

    // --- JSON-Fixture-Paritaet: dieselben Cases werden vom JS-Plugin genutzt ---

    /**
     * @param list<int> $expected
     */
    #[DataProvider('provideFixtureCases')]
    public function testMatchesSharedFixture(int $total, int $maxPiece, int $min, string $mode, array $expected): void
    {
        $splitMode = SplitMode::tryFromString($mode);
        $result = $this->splitter->split($total, $maxPiece, $min, $splitMode);

        $this->assertSame($expected, $result);
    }

    /** @return iterable<string, array{int, int, int, string, list<int>}> */
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
                array_map('intval', $case['expected']),
            ];
        }
    }

    // --- Enum tryFromString ---

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
