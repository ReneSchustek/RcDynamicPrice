<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;

interface MeterProductHelperInterface
{
    public function isMeterProduct(string $productId, Context $context): bool;

    public function isMeterProductEntity(ProductEntity $product): bool;

    public function loadProduct(string $productId, Context $context): ?ProductEntity;

    public function getMinLength(ProductEntity $product, string $salesChannelId): int;

    public function getMaxLength(ProductEntity $product, string $salesChannelId): int;

    public function getRoundingMode(ProductEntity $product): string;

    public function roundUp(int $mm, string $mode): int;
}
