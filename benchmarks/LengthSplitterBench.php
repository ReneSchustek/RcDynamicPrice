<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Benchmarks;

use Generator;
use PhpBench\Attributes as Bench;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Service\LengthSplitter;
use RuntimeException;

/**
 * Die Aufteilung zu langer Zuschnitte in Teilstücke.
 *
 * Gemessen wird, was `LengthSplitter::MAX_TOTAL_MM` (1 km) begründen soll: „schützt vor absurden
 * Array-Allokationen". Vor der Grenze steht zwar die Höchstlänge der Einstellungen (Vorgabe 10 m),
 * aber sie ist frei einstellbar, und die Teilstücklänge darf bis 1 mm hinunter. Jede Variante prüft
 * vorher, dass sie die erwartete Zahl an Teilstücken erzeugt.
 */
#[Bench\Revs(5)]
#[Bench\Iterations(5)]
#[Bench\Warmup(1)]
final class LengthSplitterBench
{
    private LengthSplitter $splitter;

    public function __construct()
    {
        $this->splitter = new LengthSplitter();
    }

    /**
     * @return Generator<string, array{total: int, piece: int, mode: string, pieces: int}>
     */
    public function cases(): Generator
    {
        yield 'Handlauf 6 m in 2-m-Stücken' => ['total' => 6000, 'piece' => 2000, 'mode' => 'equal', 'pieces' => 3];
        yield 'Höchstlänge 10 m in 1-m-Stücken' => ['total' => 10000, 'piece' => 1000, 'mode' => 'max_rest', 'pieces' => 10];
        yield 'Grenze 1 km in 6-m-Stücken' => ['total' => 1_000_000, 'piece' => 6000, 'mode' => 'max_rest', 'pieces' => 167];
        yield 'Grenze 1 km in 1-mm-Stücken' => ['total' => 1_000_000, 'piece' => 1, 'mode' => 'max_rest', 'pieces' => 1_000_000];
    }

    /**
     * @param array{total: int, piece: int, mode: string, pieces: int} $params
     */
    #[Bench\ParamProviders('cases')]
    #[Bench\BeforeMethods('checkPieces')]
    public function benchSplit(array $params): void
    {
        $this->splitter->split($params['total'], $params['piece'], SplitMode::from($params['mode']));
    }

    /**
     * @param array{total: int, piece: int, mode: string, pieces: int} $params
     */
    public function checkPieces(array $params): void
    {
        $count = \count($this->splitter->split($params['total'], $params['piece'], SplitMode::from($params['mode'])));

        if ($count !== $params['pieces']) {
            throw new RuntimeException(\sprintf('Erwartet %d Teilstücke, erzeugt %d; die Messung träfe einen anderen Fall.', $params['pieces'], $count));
        }
    }
}
