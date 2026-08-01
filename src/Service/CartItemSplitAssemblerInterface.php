<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;

interface CartItemSplitAssemblerInterface
{
    /**
     * Berechnet die Teilung und schreibt sie in den Payload des eingehenden (oder bereits im Cart
     * existierenden) LineItems. Eine Position bildet einen Zuschnitt-Auftrag ab — die Teilstücke
     * werden nicht als eigene Cart-Einträge angelegt.
     */
    public function assemble(Cart $cart, LineItem $incoming, int $mmLength, MeterSplittingConfig $config): void;
}
