<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service\LengthStage;

/**
 * Eine Längenstufe eines Artikels: die Beschriftung der Option und der Bereich in Zentimetern, den sie abdeckt.
 *
 * Ein Bereich wie „96 - 116 cm" deckt 96 bis 116 cm. Ein festes Maß wie „1,5 m" deckt alles bis 150 cm über dem
 * nächstkleineren Maß; das kleinste beginnt bei null (siehe `LengthStages::fromLabels()`).
 */
final readonly class LengthStage
{
    public function __construct(
        public string $label,
        public float $minCentimetres,
        public float $maxCentimetres,
    ) {
    }

    public function fitsMillimetres(int $millimetres): bool
    {
        return $millimetres / 10 <= $this->maxCentimetres;
    }
}
