<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Cart;

use PHPUnit\Framework\TestCase;

/**
 * Hält die Priorität des Prozessors in der `services.xml` fest: zwischen `ProductCartProcessor`
 * (5000) und `PromotionProcessor` (4900). Liegt sie darunter, sieht die Promotion den noch nicht
 * ausmultiplizierten Preis, und 3 % Skonto auf 3 m zu 10 € ergeben 0,30 € statt 0,90 €.
 *
 * Der Test liest nur die eigene Zahl, ohne Kern; ob die Reihenfolge im laufenden Shop stimmt,
 * prüft `CartProcessorOrderingTest`.
 */
final class DynamicPriceProcessorPriorityTest extends TestCase
{
    private const PRODUCT_CART_PROCESSOR_PRIORITY = 5000;
    private const PROMOTION_PROCESSOR_PRIORITY = 4900;
    private const DYNAMIC_PRICE_PROCESSOR_SERVICE_ID = 'Ruhrcoder\\RcDynamicPrice\\Cart\\DynamicPriceProcessor';

    public function testServicePriorityRunsAfterProductButBeforePromotion(): void
    {
        $priority = $this->loadCartProcessorPriority();

        self::assertLessThan(
            self::PRODUCT_CART_PROCESSOR_PRIORITY,
            $priority,
            'DynamicPriceProcessor muss NACH dem ProductCartProcessor laufen (Priorität < 5000), '
                . 'sonst fehlt der vom Product-Processor gesetzte Stückpreis.',
        );

        self::assertGreaterThan(
            self::PROMOTION_PROCESSOR_PRIORITY,
            $priority,
            'DynamicPriceProcessor muss VOR dem PromotionProcessor laufen (Priorität > 4900), '
                . 'damit prozentuale Promotions auf den ausmultiplizierten Positions-Preis '
                . 'wirken und nicht nur auf den Meter-Stückpreis.',
        );
    }

    public function testServicePriorityIsExactlyPinned(): void
    {
        // Der genaue Wert fällt auf, auch wenn er sich innerhalb des erlaubten Bereichs verschiebt;
        // so ändert ihn niemand nebenbei.
        self::assertSame(4950, $this->loadCartProcessorPriority());
    }

    private function loadCartProcessorPriority(): int
    {
        $servicesXmlPath = \dirname(__DIR__, 3) . '/src/Resources/config/services.xml';
        self::assertFileExists($servicesXmlPath);

        $xml = \simplexml_load_file($servicesXmlPath);
        self::assertNotFalse($xml, 'services.xml ließ sich nicht parsen.');

        $xml->registerXPathNamespace('s', 'http://symfony.com/schema/dic/services');
        $tags = $xml->xpath(
            \sprintf(
                '//s:service[@id="%s"]/s:tag[@name="shopware.cart.processor"]',
                self::DYNAMIC_PRICE_PROCESSOR_SERVICE_ID,
            ),
        );

        self::assertIsArray($tags);
        self::assertCount(
            1,
            $tags,
            'Genau ein shopware.cart.processor-Tag am DynamicPriceProcessor erwartet.',
        );

        $priorityAttribute = $tags[0]['priority'];
        self::assertNotNull($priorityAttribute, 'Cart-Processor-Tag muss eine explizite priority tragen.');

        return (int) $priorityAttribute;
    }
}
