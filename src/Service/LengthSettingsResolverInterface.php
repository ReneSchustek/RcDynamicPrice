<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;

/**
 * Entscheidet, welches Längen-Verhalten ein Artikel bekommt: geführte Auswahl, Längenschalter, „Größen nur über die
 * Länge", und mit welchen Längengruppen.
 */
interface LengthSettingsResolverInterface
{
    public function forProduct(ProductEntity $product, string $salesChannelId, Context $context): LengthSettings;
}
