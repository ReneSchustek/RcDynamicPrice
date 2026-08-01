<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Service\CartItemSplitAssembler;
use Ruhrcoder\RcDynamicPrice\Service\LengthSplitter;
use Ruhrcoder\RcDynamicPrice\Service\MeterSplittingConfig;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;

final class CartItemSplitAssemblerTest extends TestCase
{
    private CartItemSplitAssembler $assembler;

    protected function setUp(): void
    {
        $this->assembler = new CartItemSplitAssembler(new LengthSplitter(), new NullLogger());
    }

    public function testWritesPayloadWithoutSplittingWhenModeIsNull(): void
    {
        $cart = $this->cartWithSingleItem('primary-id');
        $this->assembler->assemble($cart, $this->lineItem($cart, 'primary-id'), 4000, $this->config(splitMode: null));

        $this->assertCount(1, $cart->getLineItems());
        $this->assertSame(4000, $this->lineItem($cart, 'primary-id')->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
        $this->assertSame([4000], $this->lineItem($cart, 'primary-id')->getPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES));
    }

    public function testHintModeDoesNotSplit(): void
    {
        $cart = $this->cartWithSingleItem('primary-id');
        $this->assembler->assemble(
            $cart,
            $this->lineItem($cart, 'primary-id'),
            4000,
            $this->config(splitMode: SplitMode::Hint, maxPieceLength: 5000),
        );

        $this->assertCount(1, $cart->getLineItems());
        $this->assertSame(4000, $this->lineItem($cart, 'primary-id')->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
        $this->assertSame([4000], $this->lineItem($cart, 'primary-id')->getPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES));
    }

    /**
     * Kern des Auftrags-Modells: Die Teilstücke sind eine Fertigungsfolge im Payload, keine
     * eigenen Cart-Einträge. Der Kunde bestellt eine Länge und sieht eine Position.
     */
    public function testEqualSplitKeepsOneLineItemAndStoresPiecesInPayload(): void
    {
        $cart = $this->cartWithSingleItem('primary-id');
        $this->assembler->assemble(
            $cart,
            $this->lineItem($cart, 'primary-id'),
            8000,
            $this->config(splitMode: SplitMode::Equal, maxPieceLength: 5000),
        );

        $this->assertCount(1, $cart->getLineItems());

        $item = $this->lineItem($cart, 'primary-id');
        $this->assertSame(8000, $item->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
        $this->assertSame([4000, 4000], $item->getPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES));
    }

    public function testMaxRestSplitStoresRemainderAsLastPiece(): void
    {
        $cart = $this->cartWithSingleItem('primary-id');
        $this->assembler->assemble(
            $cart,
            $this->lineItem($cart, 'primary-id'),
            8000,
            $this->config(splitMode: SplitMode::MaxRest, maxPieceLength: 5000),
        );

        $this->assertCount(1, $cart->getLineItems());

        $item = $this->lineItem($cart, 'primary-id');
        $this->assertSame(8000, $item->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
        $this->assertSame([5000, 3000], $item->getPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES));
    }

    /**
     * Regressions-Gate für den Defekt, der zum Umbau geführt hat: Solange kein Teilstück eine eigene
     * Position ist, kann der Kunde auch keines einzeln löschen und damit unbemerkt eine kürzere
     * Länge bestellen, als er eingegeben hat.
     */
    public function testSplitPiecesNeverBecomeSeparateLineItems(): void
    {
        $cart = $this->cartWithSingleItem('primary-id');
        $this->assembler->assemble(
            $cart,
            $this->lineItem($cart, 'primary-id'),
            15000,
            $this->config(splitMode: SplitMode::Equal, maxPieceLength: 5000),
        );

        $this->assertCount(1, $cart->getLineItems(), 'Ein Zuschnitt-Auftrag ist genau eine Position');
        $this->assertNull($cart->get('primary-id-piece1'), 'Teilstücke dürfen keine eigenen Cart-Einträge sein');
        $this->assertNull($cart->get('primary-id-piece2'), 'Teilstücke dürfen keine eigenen Cart-Einträge sein');
        $this->assertSame(
            [5000, 5000, 5000],
            $this->lineItem($cart, 'primary-id')->getPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES),
        );
    }

    public function testPayloadFollowsCartItemInsteadOfEventInstanceOnMerging(): void
    {
        // Cart enthält bereits ein gemergtes LineItem mit derselben ID; der Subscriber übergibt
        // das eingehende (frische) LineItem an den Assembler. Der Payload muss am Cart-Item landen.
        $existingCartItem = new LineItem('merged-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id', 2);
        $incoming = new LineItem('merged-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id', 1);

        $cart = new Cart('test-token');
        $cart->add($existingCartItem);

        $this->assembler->assemble(
            $cart,
            $incoming,
            8000,
            $this->config(splitMode: SplitMode::Equal, maxPieceLength: 5000, productId: 'product-id'),
        );

        $this->assertSame(
            [4000, 4000],
            $this->lineItem($cart, 'merged-id')->getPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES),
        );
        $this->assertSame(2, $this->lineItem($cart, 'merged-id')->getQuantity(), 'Merge-Menge bleibt unangetastet');
    }

    public function testPayloadContainsBoundsAndRoundingMode(): void
    {
        $cart = $this->cartWithSingleItem('primary-id');
        $this->assembler->assemble(
            $cart,
            $this->lineItem($cart, 'primary-id'),
            8000,
            $this->config(
                splitMode: SplitMode::Equal,
                maxPieceLength: 5000,
                minLength: 10,
                maxLength: 10000,
                roundingMode: 'full_m',
            ),
        );

        $item = $this->lineItem($cart, 'primary-id');
        $this->assertNotNull($item);
        $this->assertTrue($item->getPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE));
        $this->assertSame('full_m', $item->getPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING));
        $this->assertSame(10, $item->getPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH));
        $this->assertSame(10000, $item->getPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH));
    }

    /**
     * Geschnitten wird die bestellte Länge — auch wenn das Reststück unter der Mindestlänge liegt.
     * Die Anhebung passiert nur noch in der Abrechnung; der Assembler hält dafür fest, dass sie
     * gilt (`rc_min_billing`).
     */
    public function testRemainderBelowMinimumIsCutAsIsAndFlaggedForMinimumBilling(): void
    {
        $cart = $this->cartWithSingleItem('primary-id');
        $this->assembler->assemble(
            $cart,
            $this->lineItem($cart, 'primary-id'),
            5100,
            $this->config(splitMode: SplitMode::MaxRest, maxPieceLength: 5000, minLength: 1000),
        );

        $item = $this->lineItem($cart, 'primary-id');

        $this->assertSame(
            [5000, 100],
            $item->getPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES),
            'Die Fertigung schneidet 5.000 + 100 mm — nicht 5.000 + 1.000 mm.',
        );
        $this->assertTrue(
            $item->getPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_BILLING),
            'Das Reststück muss mit der Mindestlänge abgerechnet werden.',
        );
    }

    /**
     * Im equal-Modus entscheidet die Händler-Option, ob kurze Teilstücke mit der Mindestlänge
     * abgerechnet werden. Geschnitten werden sie in beiden Fällen wie berechnet.
     */
    public function testEqualSplitCarriesTheMinimumBillingOptionIntoThePayload(): void
    {
        foreach ([true, false] as $enforceMin) {
            $cart = $this->cartWithSingleItem('primary-id');
            $this->assembler->assemble(
                $cart,
                $this->lineItem($cart, 'primary-id'),
                1100,
                $this->config(
                    splitMode: SplitMode::Equal,
                    maxPieceLength: 1000,
                    minLength: 600,
                    equalSplitEnforceMin: $enforceMin,
                ),
            );

            $item = $this->lineItem($cart, 'primary-id');

            $this->assertSame([550, 550], $item->getPayloadValue(DynamicPriceConstants::PAYLOAD_SPLIT_PIECES));
            $this->assertSame($enforceMin, $item->getPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_BILLING));
        }
    }

    private function cartWithSingleItem(string $id): Cart
    {
        $cart = new Cart('test-token');
        $cart->add(new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id', 1));

        return $cart;
    }

    private function config(
        ?SplitMode $splitMode = null,
        int $maxPieceLength = 0,
        int $minLength = 1,
        int $maxLength = 10000,
        string $roundingMode = 'none',
        string $productId = 'product-id',
        bool $equalSplitEnforceMin = true,
    ): MeterSplittingConfig {
        return new MeterSplittingConfig(
            productId: $productId,
            minLength: $minLength,
            maxLength: $maxLength,
            maxPieceLength: $maxPieceLength,
            roundingMode: $roundingMode,
            splitMode: $splitMode,
            equalSplitEnforceMin: $equalSplitEnforceMin,
        );
    }

    /**
     * Holt eine Position und stellt sicher, dass es sie gibt. Cart::get() liefert null,
     * wenn die Kennung nicht vorkommt — ohne diese Prüfung würde ein Tippfehler in der
     * Kennung den Test nicht scheitern lassen, sondern stillschweigend nichts prüfen.
     */
    private function lineItem(Cart $cart, string $id): LineItem
    {
        $lineItem = $cart->get($id);
        $this->assertInstanceOf(LineItem::class, $lineItem);

        return $lineItem;
    }

}
