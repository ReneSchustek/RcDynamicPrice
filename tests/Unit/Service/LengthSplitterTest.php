<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Exception\DynamicPriceException;
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

    // --- Modus equal ---

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
        // Default `cut_length`: jedes Teil wird auf dieselbe aufgerundete Länge gebracht
        // (Schnittlänge) — die Summe darf die Eingabe übersteigen (3x3334 = 10002).
        $this->assertSame([3334, 3334, 3334], $this->splitter->split(10001, 5000, SplitMode::Equal));
    }

    public function testEqualExactBillingSumEqualsInputLength(): void
    {
        // Mit `exact`: kein systematisches Aufrunden — Summe == Eingabe.
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
     * berechnet — die Mindestlänge ist eine Abrechnungsregel und wirkt erst im Processor. Vorher hob
     * der Splitter das Stück physisch an: Der Kunde bekam mehr Material, als er bestellt hatte.
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

    // --- Modus max_rest ---

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
     * Der Fall aus der Praxis: 5.100 mm bei maxPiece 5.000 und min 1.000. Vorher schnitt die
     * Fertigung 5.000 + 1.000 mm — 900 mm mehr, als der Kunde bestellt hatte. Jetzt schneidet sie
     * 5.000 + 100 mm; die Mindestlänge wird nur berechnet (siehe DynamicPriceProcessor).
     */
    public function testMaxRestCutsTheRemainderAtItsActualLength(): void
    {
        $this->assertSame([5000, 100], $this->splitter->split(5100, 5000, SplitMode::MaxRest));
        $this->assertSame([5000, 1000], $this->splitter->split(6000, 5000, SplitMode::MaxRest));
    }

    public function testMaxRestDoesNotBumpRemainderAboveItsNaturalValue(): void
    {
        // Rest 3000 >= Min 1000 → unverändert
        $this->assertSame([5000, 3000], $this->splitter->split(8000, 5000, SplitMode::MaxRest));
    }

    public function testMaxRestWithThreeFullPiecesPlusRemainder(): void
    {
        $this->assertSame([5000, 5000, 5000, 2000], $this->splitter->split(17000, 5000, SplitMode::MaxRest));
    }

    public function testMaxRestUsesAtLeastOneAsMinimumFloor(): void
    {
        // Absurder Min-Wert 0 darf nicht zu einem 0-Rest führen
        $this->assertSame([5000, 1000], $this->splitter->split(6000, 5000, SplitMode::MaxRest));
    }

    // --- Fehlerfälle ---

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
        // Genau der Grenzwert muss noch akzeptiert werden
        $result = $this->splitter->split(LengthSplitter::MAX_TOTAL_MM, 5000, SplitMode::Equal);
        $this->assertNotEmpty($result);
    }

    // --- Invariante: alle Teilstücke <= maxPiece (Datenprovider-Matrix) ---

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

    // --- JSON-Fixture-Parität: dieselben Cases werden vom JS-Plugin genutzt ---

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
