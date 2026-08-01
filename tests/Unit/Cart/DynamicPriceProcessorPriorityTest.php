<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Cart;

use PHPUnit\Framework\TestCase;

/**
 * Pinning-Test gegen den Live-Bug: 3-%-Skonto wirkte nur auf den Meter-Stückpreis
 * (10 €) statt auf den ausmultiplizierten Positions-Preis (30 €). Ursache war eine
 * Cart-Processor-Priorität unterhalb des Shopware-PromotionProcessor (4900) — die
 * Promotion sah den noch nicht multiplizierten Preis.
 *
 * Korrekte Reihenfolge in der Pipeline:
 *   ProductCartProcessor (5000) → DynamicPriceProcessor (zwischen 4900 und 5000)
 *   → PromotionProcessor (4900)
 *
 * Drift in der Priorität schaltet den Bug wieder scharf. Die Werte werden hier hart
 * gepinnt, damit ein versehentliches Zurückdrehen sofort rot wird.
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
        // Exakter Pinning-Wert verhindert leise Drift innerhalb des gültigen Korridors.
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
