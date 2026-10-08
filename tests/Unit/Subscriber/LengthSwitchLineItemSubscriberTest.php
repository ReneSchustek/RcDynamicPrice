<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\CategoryChainLoaderInterface;
use Ruhrcoder\RcDynamicPrice\Service\LengthSettingsResolver;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Ruhrcoder\RcDynamicPrice\Subscriber\LengthSwitchLineItemSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Das Kennzeichen an der Warenkorbposition: Nur das Längenfeld eines Artikels mit Längenschalter
 * erscheint im Warenkorb als „Gewünschte Länge".
 */
final class LengthSwitchLineItemSubscriberTest extends TestCase
{
    public function testTheLengthFieldNumberIsWrittenToThePosition(): void
    {
        $lineItem = new LineItem('position', LineItem::PRODUCT_LINE_ITEM_TYPE, 'produkt');

        $this->subscriber([
            'tmms_customer_input_1_active' => true,
            'tmms_customer_input_1_title' => 'Wandabstand',
            'tmms_customer_input_2_active' => true,
            'tmms_customer_input_2_placeholder' => 'Gewünschte Länge in mm',
            DynamicPriceConstants::FIELD_LENGTH_VARIANT_SWITCH => true,
        ])->onBeforeLineItemAdded($this->event($lineItem));

        self::assertSame(2, $lineItem->getPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_SWITCH));
    }

    public function testWithoutLengthSwitchThePositionStaysUnmarked(): void
    {
        $lineItem = new LineItem('position', LineItem::PRODUCT_LINE_ITEM_TYPE, 'produkt');

        $this->subscriber([
            'tmms_customer_input_1_active' => true,
            'tmms_customer_input_1_placeholder' => 'Gewünschte Länge in mm',
        ])->onBeforeLineItemAdded($this->event($lineItem));

        self::assertFalse($lineItem->hasPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_SWITCH));
    }

    /**
     * Gutscheine und zusammengelegte Positionen brauchen keine Produktsuche.
     */
    public function testOtherPositionsAreNotLoaded(): void
    {
        $helper = $this->createMock(MeterProductHelperInterface::class);
        $helper->expects(self::never())->method('loadProduct');
        $subscriber = new LengthSwitchLineItemSubscriber($helper, new LengthSettingsResolver($this->createMock(CategoryChainLoaderInterface::class)));

        $subscriber->onBeforeLineItemAdded($this->event(new LineItem('gutschein', LineItem::PROMOTION_LINE_ITEM_TYPE, 'CODE')));
        $subscriber->onBeforeLineItemAdded($this->event(new LineItem('position', LineItem::PRODUCT_LINE_ITEM_TYPE, 'produkt'), merged: true));
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function subscriber(array $customFields): LengthSwitchLineItemSubscriber
    {
        $product = new ProductEntity();
        $product->setId('produkt');
        $product->setCustomFields($customFields);

        $helper = $this->createMock(MeterProductHelperInterface::class);
        $helper->method('loadProduct')->willReturn($product);

        $chainLoader = $this->createMock(CategoryChainLoaderInterface::class);
        $chainLoader->method('loadChain')->willReturn([]);

        return new LengthSwitchLineItemSubscriber($helper, new LengthSettingsResolver($chainLoader));
    }

    private function event(LineItem $lineItem, bool $merged = false): BeforeLineItemAddedEvent
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('kanal');
        $context->method('getContext')->willReturn(new Context(new SystemSource()));

        return new BeforeLineItemAddedEvent($lineItem, new Cart('warenkorb'), $context, $merged);
    }
}
