<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Storefront\Struct;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Exception\DynamicPriceException;
use Shopware\Core\Framework\Struct\Struct;

/**
 * Was das Meterpreis-Widget der Produktseite braucht, um Preis und Teilung vorab so zu zeigen, wie
 * der Warenkorb sie rechnet. Der Konstruktor weist widersprüchliche Grenzen ab, statt sie an die
 * Seite durchzureichen.
 */
final class RcDynamicPriceConfigStruct extends Struct
{
    /**
     * @param array<string, int> $roundingSteps Modus → Schrittweite; das Storefront-Skript liest sie
     *                                          als `data-rounding-steps`, damit Seite und Server mit
     *                                          derselben Tabelle runden.
     */
    public function __construct(
        private readonly string $hintText,
        private readonly int $minLength,
        private readonly int $maxLength,
        private readonly string $roundingMode = 'none',
        // Als Text statt SplitMode, damit Twig ihn ohne `.value` ins Datenattribut schreibt. Maßgeblich
        // bleibt das Enum; umgewandelt wird im ProductPageSubscriber.
        private readonly string $splitMode = '',
        private readonly int $maxPieceLength = 0,
        private readonly string $splitHintTemplate = '',
        private readonly array $roundingSteps = [],
        // Abrechnung im equal-Modus für die Vorschau; weicht sie vom Server ab, zeigt die Seite eine
        // andere Stückelung als der Warenkorb berechnet.
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
