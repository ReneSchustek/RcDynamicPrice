<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Storefront\Struct;

use Shopware\Core\Framework\Struct\Struct;

final class RcDynamicPriceConfigStruct extends Struct
{
    public function __construct(
        private readonly string $hintText,
        private readonly int $minLength,
        private readonly int $maxLength,
        private readonly string $roundingMode = 'none',
        private readonly string $splitMode = '',
        private readonly int $maxPieceLength = 0,
        private readonly string $splitHintTemplate = '',
    ) {
        if ($this->minLength > $this->maxLength) {
            throw new \InvalidArgumentException(
                sprintf('minLength (%d) darf maxLength (%d) nicht ueberschreiten', $this->minLength, $this->maxLength)
            );
        }

        if ($this->maxPieceLength < 0) {
            throw new \InvalidArgumentException(
                sprintf('maxPieceLength (%d) darf nicht negativ sein', $this->maxPieceLength)
            );
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
}
