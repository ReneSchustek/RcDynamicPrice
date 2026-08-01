<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Storefront\Struct;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Exception\DynamicPriceException;
use Shopware\Core\Framework\Struct\Struct;

final class RcDynamicPriceConfigStruct extends Struct
{
    /**
     * @param array<string, int> $roundingSteps Modus → Schrittweite-Map; wird vom Storefront-JS
     *                                          als `data-rounding-steps`-JSON gelesen, damit
     *                                          Server und Client gegen dieselbe Tabelle runden.
     */
    public function __construct(
        private readonly string $hintText,
        private readonly int $minLength,
        private readonly int $maxLength,
        private readonly string $roundingMode = 'none',
        // Als String statt SplitMode-Enum, damit Twig ohne expliziten .value-Cast auf das Data-Attribut
        // serialisieren kann. Zentrale Quelle bleibt der Enum; die Konvertierung erfolgt im Subscriber.
        private readonly string $splitMode = '',
        private readonly int $maxPieceLength = 0,
        private readonly string $splitHintTemplate = '',
        private readonly array $roundingSteps = [],
        // equal-Modus-Abrechnung für die Storefront-Vorschau (muss serverseitig identisch sein,
        // sonst weicht die angezeigte Stückelung vom berechneten Cart-Preis ab).
        private readonly string $equalSplitBilling = DynamicPriceConstants::EQUAL_BILLING_CUT_LENGTH,
        private readonly bool $equalSplitEnforceMin = true,
    ) {
        if ($this->minLength > $this->maxLength) {
            throw DynamicPriceException::invalidMinMaxLength($this->minLength, $this->maxLength);
        }

        if ($this->maxPieceLength < 0) {
            throw DynamicPriceException::negativeMaxPieceLength($this->maxPieceLength);
        }
    }

    public function getHintText(): string
    {
        return $this->hintText;
    }

    public function getMinLength(): int
    {
        return $this->minLength;
    }

    public function getMaxLength(): int
    {
        return $this->maxLength;
    }

    public function getRoundingMode(): string
    {
        return $this->roundingMode;
    }

    public function getSplitMode(): string
    {
        return $this->splitMode;
    }

    public function getMaxPieceLength(): int
    {
        return $this->maxPieceLength;
    }

    public function getSplitHintTemplate(): string
    {
        return $this->splitHintTemplate;
    }

    /** @return array<string, int> */
    public function getRoundingSteps(): array
    {
        return $this->roundingSteps;
    }

    public function getEqualSplitBilling(): string
    {
        return $this->equalSplitBilling;
    }

    public function isEqualSplitEnforceMin(): bool
    {
        return $this->equalSplitEnforceMin;
    }
}
