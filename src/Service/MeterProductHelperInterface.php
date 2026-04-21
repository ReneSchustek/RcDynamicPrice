<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
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

    /** Liefert den Split-Modus; null, wenn kein Splitting konfiguriert ist. */
    public function getSplitMode(ProductEntity $product, string $salesChannelId): ?SplitMode;

    /** Liefert die Max-Teilstuecklaenge in mm; 0 bedeutet „keine Grenze / kein Splitting". */
    public function getMaxPieceLength(ProductEntity $product, string $salesChannelId): int;

    /** Liefert das Hinweis-Template mit Platzhaltern; leer, wenn keines hinterlegt ist. */
    public function getSplitHintTemplate(ProductEntity $product, string $salesChannelId): string;
}
