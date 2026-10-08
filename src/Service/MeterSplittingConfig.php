<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;

/**
 * Unveränderliche Einstellungen für einen Teilungsvorgang. Der Subscriber baut sie aus der
 * aufgelösten Konfiguration und reicht sie an den Assembler weiter, der so ohne Request auskommt.
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
        public string $equalSplitBilling = DynamicPriceConstants::EQUAL_BILLING_CUT_LENGTH,
        public bool $equalSplitEnforceMin = true,
    ) {
    }
}
