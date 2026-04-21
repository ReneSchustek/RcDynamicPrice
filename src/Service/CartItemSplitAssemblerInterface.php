<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;

interface CartItemSplitAssemblerInterface
{
    /**
     * Berechnet die Teilung und präpariert den Cart:
     *  - schreibt Payload für das eingehende (oder bereits im Cart existierende) LineItem
     *  - hängt bei Bedarf Sibling-LineItems an den Cart an
     */
    public function assemble(Cart $cart, LineItem $incoming, int $mmLength, MeterSplittingConfig $config): void;
}
