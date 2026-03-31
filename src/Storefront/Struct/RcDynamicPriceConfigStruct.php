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
    ) {
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
}
