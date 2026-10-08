<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;

/**
 * Schreibt einen Zuschnitt-Auftrag in eine Warenkorbposition. Die Schnittstelle trennt den
 * Subscriber, der den Request liest, von der Rechnung, die ohne HTTP-Kontext testbar bleibt.
 */
interface CartItemSplitAssemblerInterface
{
    /**
     * Berechnet die Teilung und schreibt sie in den Payload des eingehenden oder bereits im
     * Warenkorb liegenden LineItems. Eine Position bildet einen Zuschnitt-Auftrag ab; die
     * Teilstücke werden nicht als eigene Warenkorbeinträge angelegt.
     */
    public function assemble(Cart $cart, LineItem $incoming, int $mmLength, MeterSplittingConfig $config): void;
}
