<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Cart;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Cart\Error\LengthStageError;
use Ruhrcoder\RcDynamicPrice\Cart\LengthStageValidator;
use Ruhrcoder\RcDynamicPrice\Service\LengthStage\LengthStageReader;
use Ruhrcoder\RcDynamicPrice\Service\LengthStage\LengthStages;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Länge gegen Längenstufe im Warenkorb (BRIEF65). Anlass: Ein Kunde kaufte das Rohr 424100V2A-0 in der Stufe 0,2 m
 * und trug im Warenkorb 800 mm ein; bestellt war 800 mm zum Preis von 0,2 m.
 */
final class LengthStageValidatorTest extends TestCase
{
    private const PRODUCT = '0198d3a9c7c5715e8f2bb1a0a3b5c001';
    private const NUMBER = '424020V2A';

    /** @var LengthStageReader&MockObject */
    private LengthStageReader $reader;

    protected function setUp(): void
    {
        $this->reader = $this->reader(false);
    }

    /**
     * @return LengthStageReader&MockObject
     */
    private function reader(bool $required): LengthStageReader
    {
        $stages = LengthStages::fromLabels(['0,2 m', '0,5 m', '1,0 m', '6,0 m']);
        $reader = $this->createMock(LengthStageReader::class);
        $reader->method('read')->willReturnCallback(static fn (string $productId): ?array => match ($productId) {
            self::PRODUCT => ['stage' => $stages[0], 'stages' => $stages, 'field' => 1, 'required' => $required],
            default => null,
        });

        return $reader;
    }

    /**
     * Was: Die Position trägt keine Zusatzfelder, wie jede Position ab der zweiten Anfrage.
     * Warum: Der Kern speichert den Warenkorb ohne nicht freigegebene Zusatzfelder. Hinge die Prüfung an ihnen, liefe
     * die Bestellung durch, obwohl der Warenkorb eben noch gesperrt war (auf live-latest so geschehen).
     */
    public function testAPersistedLineItemWithoutCustomFieldsIsStillChecked(): void
    {
        $lineItem = new LineItem('tube', LineItem::PRODUCT_LINE_ITEM_TYPE, self::PRODUCT);
        $lineItem->setPayload(['productNumber' => self::NUMBER]);
        $cart = new Cart('token');
        $cart->add($lineItem);

        self::assertCount(1, $this->validate($cart, '800'));
    }

    public function testTheSmallestStageWithEightHundredMillimetresIsBlocked(): void
    {
        $errors = $this->validate($this->cart(), '800');

        self::assertCount(1, $errors);
        $error = $errors->first();
        self::assertInstanceOf(LengthStageError::class, $error);
        self::assertTrue($error->blockOrder());
        self::assertSame('rc-dynamic-price-length-stage-too-long', $error->getMessageKey());
        self::assertSame('1,0 m', $error->getParameters()['suggestion']);
        self::assertSame('800 mm', $error->getParameters()['length']);
        self::assertSame('0,2 m', $error->getParameters()['stage']);
    }

    public function testALengthWithinTheStagePasses(): void
    {
        self::assertCount(0, $this->validate($this->cart(), '200'));
        self::assertCount(0, $this->validate($this->cart(), '0,15 m'));
    }

    /**
     * Was: Mehrere Längen, eine davon zu lang.
     * Warum: Jede Länge wird aus der gekauften Stufe geschnitten; eine zu lange reicht für die Sperre.
     */
    public function testEveryLengthMustFit(): void
    {
        self::assertCount(1, $this->validate($this->cart(), '150; 900'));
    }

    public function testLongerThanEveryStageNamesNoSuggestion(): void
    {
        $error = $this->validate($this->cart(), '7000')->first();

        self::assertInstanceOf(LengthStageError::class, $error);
        self::assertSame('rc-dynamic-price-length-stage-too-long-for-all', $error->getMessageKey());
        self::assertSame('7.000 mm', $error->getParameters()['length']);
    }

    public function testAnUnreadableLengthIsBlocked(): void
    {
        $error = $this->validate($this->cart(), 'bitte anrufen')->first();

        self::assertInstanceOf(LengthStageError::class, $error);
        self::assertSame('rc-dynamic-price-length-stage-unreadable', $error->getMessageKey());
    }

    /**
     * Was: Leeres Längenfeld, das Pflicht ist.
     * Warum: Über die Store-API kommt kein Wert in die Sitzung; ohne diese Sperre ginge die kleinste Stufe ohne Länge durch.
     */
    public function testAnEmptyRequiredFieldIsBlocked(): void
    {
        $this->reader = $this->reader(true);
        $error = $this->validate($this->cart(), '')->first();

        self::assertInstanceOf(LengthStageError::class, $error);
        self::assertSame('rc-dynamic-price-length-stage-missing', $error->getMessageKey());
    }

    public function testAnEmptyOptionalFieldPasses(): void
    {
        self::assertCount(0, $this->validate($this->cart(), ''));
    }

    public function testWithoutSessionAnOptionalFieldPasses(): void
    {
        $validator = new LengthStageValidator($this->reader, new RequestStack());
        $errors = new ErrorCollection();

        $validator->validate($this->cart(), $errors, $this->createMock(SalesChannelContext::class));

        self::assertCount(0, $errors);
    }

    /**
     * Was: Artikel ohne Stufe (ohne Längengruppe oder Längenfeld, siehe LengthStageReaderTest) und Positionen ohne Produkt.
     * Warum: Es gibt keine Stufe, gegen die geprüft würde; sie bleiben unberührt.
     */
    public function testEverythingElseIsLeftAlone(): void
    {
        $otherProduct = $this->lineItem('other', '0198d3a9c7c5715e8f2bb1a0a3b5c0ff', 'OTHER');
        $promotion = new LineItem('promo', LineItem::PROMOTION_LINE_ITEM_TYPE);

        $cart = new Cart('token');
        $cart->add($otherProduct);
        $cart->add($promotion);

        self::assertCount(0, $this->validate($cart, '800'));
    }

    private function validate(Cart $cart, string $value): ErrorCollection
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('tmms_customer_input_1_' . self::NUMBER, ['tmms_customer_input_value' => $value]);
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        $errors = new ErrorCollection();
        (new LengthStageValidator($this->reader, $stack))->validate($cart, $errors, $this->createMock(SalesChannelContext::class));

        return $errors;
    }

    private function cart(): Cart
    {
        $cart = new Cart('token');
        $cart->add($this->lineItem('tube', self::PRODUCT, self::NUMBER));

        return $cart;
    }

    private function lineItem(string $id, string $productId, string $number): LineItem
    {
        $lineItem = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $productId);
        $lineItem->setLabel('Edelstahlrohr 42,4 x 2,0 mm');
        $lineItem->setPayload(['productNumber' => $number]);

        return $lineItem;
    }
}
