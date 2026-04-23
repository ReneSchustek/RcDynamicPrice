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
        $this->assembler->assemble($cart, $cart->get('primary-id'), 4000, $this->config(splitMode: null));

        $this->assertCount(1, $cart->getLineItems());
        $this->assertSame(4000, $cart->get('primary-id')?->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
    }

    public function testHintModeDoesNotAppendSiblings(): void
    {
        $cart = $this->cartWithSingleItem('primary-id');
        $this->assembler->assemble(
            $cart,
            $cart->get('primary-id'),
            4000,
            $this->config(splitMode: SplitMode::Hint, maxPieceLength: 5000),
        );

        $this->assertCount(1, $cart->getLineItems());
        $this->assertSame(4000, $cart->get('primary-id')?->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
    }

    public function testEqualSplitAppendsSiblingsWithPiecePayload(): void
    {
        $cart = $this->cartWithSingleItem('primary-id');
        $this->assembler->assemble(
            $cart,
            $cart->get('primary-id'),
            8000,
            $this->config(splitMode: SplitMode::Equal, maxPieceLength: 5000),
        );

        $this->assertCount(2, $cart->getLineItems());
        $this->assertSame(4000, $cart->get('primary-id')?->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
        $this->assertSame(4000, $cart->get('primary-id-piece1')?->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
    }

    public function testMaxRestSplitAppendsRemainderAsSibling(): void
    {
        $cart = $this->cartWithSingleItem('primary-id');
        $this->assembler->assemble(
            $cart,
            $cart->get('primary-id'),
            8000,
            $this->config(splitMode: SplitMode::MaxRest, maxPieceLength: 5000),
        );

        $this->assertSame(5000, $cart->get('primary-id')?->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
        $this->assertSame(3000, $cart->get('primary-id-piece1')?->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
    }

    public function testSiblingIdFollowsCartItemInsteadOfEventInstanceOnMerging(): void
    {
        // Cart enthält bereits ein gemergtes LineItem mit derselben ID; der Subscriber übergibt
        // das eingehende (frische) LineItem an den Assembler. Sibling-IDs müssen sich am Cart-Item orientieren.
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

        $this->assertNotNull($cart->get('merged-id-piece1'));
        $this->assertSame('product-id', $cart->get('merged-id-piece1')?->getReferencedId());
    }

    public function testSiblingPayloadContainsBoundsAndRoundingMode(): void
    {
        $cart = $this->cartWithSingleItem('primary-id');
        $this->assembler->assemble(
            $cart,
            $cart->get('primary-id'),
            8000,
            $this->config(
                splitMode: SplitMode::Equal,
                maxPieceLength: 5000,
                minLength: 10,
                maxLength: 10000,
                roundingMode: 'full_m',
            ),
        );

        $sibling = $cart->get('primary-id-piece1');
        $this->assertNotNull($sibling);
        $this->assertTrue($sibling->getPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE));
        $this->assertSame('full_m', $sibling->getPayloadValue(DynamicPriceConstants::PAYLOAD_ROUNDING));
        $this->assertSame(10, $sibling->getPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH));
        $this->assertSame(10000, $sibling->getPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH));
    }

    public function testParadoxMinExceedingMaxPieceBumpsRemainderInMaxRest(): void
    {
        // minLength > maxPieceLength ist fachlich absurd, aber der Splitter muss nicht abstürzen.
        // Der Rest wird auf minLength angehoben, auch wenn dieser minLength größer ist als maxPieceLength.
        $cart = $this->cartWithSingleItem('primary-id');
        $this->assembler->assemble(
            $cart,
            $cart->get('primary-id'),
            6000,
            $this->config(splitMode: SplitMode::MaxRest, maxPieceLength: 5000, minLength: 2000),
        );

        $this->assertSame(5000, $cart->get('primary-id')?->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
        $this->assertSame(2000, $cart->get('primary-id-piece1')?->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM));
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
    ): MeterSplittingConfig {
        return new MeterSplittingConfig(
            productId: $productId,
            minLength: $minLength,
            maxLength: $maxLength,
            maxPieceLength: $maxPieceLength,
            roundingMode: $roundingMode,
            splitMode: $splitMode,
        );
    }
}
