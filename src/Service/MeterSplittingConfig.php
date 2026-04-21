<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;

/**
 * Unveränderliche Konfiguration für einen Split-Vorgang.
 * Kapselt die Produkt- und Sales-Channel-spezifischen Werte, die der Subscriber
 * aus MeterProductHelper ermittelt und an den Assembler weiterreicht.
 */
final readonly class MeterSplittingConfig
{
    public function __construct(
        public string $productId,
        public int $minLength,
        public int $maxLength,
        public int $maxPieceLength,
        public string $roundingMode,
        public ?SplitMode $splitMode,
    ) {
    }
}
