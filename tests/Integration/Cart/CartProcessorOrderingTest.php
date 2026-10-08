<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Integration\Cart;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Ruhrcoder\RcDynamicPrice\Cart\DynamicPriceProcessor;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\Processor;
use Shopware\Core\Checkout\Promotion\Cart\PromotionProcessor;
use Shopware\Core\Content\Product\Cart\ProductCartProcessor;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;

/**
 * Prüft die Reihenfolge der Warenkorb-Prozessoren am kompilierten Container, nicht an der
 * `services.xml`.
 *
 * Der `DynamicPriceProcessor` muss zwischen `ProductCartProcessor` und `PromotionProcessor` laufen.
 * Läuft er daneben, bezieht sich ein prozentualer Rabatt auf den rohen Meter-Stückpreis statt auf
 * den ausmultiplizierten Positionspreis, und der Kunde bekommt zu wenig Skonto.
 *
 * Ein Vergleich der eigenen Priorität gegen feste Zahlen sähe nur, ob die eigene Zahl gleich
 * geblieben ist. Ein Kern-Update, das den `PromotionProcessor` verschiebt, oder ein Plugin, das sich
 * dazwischenlegt, bliebe unsichtbar; deshalb liest der Test die Reihenfolge, die tatsächlich läuft.
 */
final class CartProcessorOrderingTest extends TestCase
{
    /**
     * Die real registrierten Cart-Processoren in Ausführungsreihenfolge.
     *
     * @var list<class-string>
     */
    private array $order;

    protected function setUp(): void
    {
        $container = KernelLifecycleManager::getKernel()->getContainer();

        // Der Test-Container macht private Dienste zugänglich.
        $testContainer = $container->has('test.service_container')
            ? $container->get('test.service_container')
            : $container;
        self::assertInstanceOf(ContainerInterface::class, $testContainer);
        $container = $testContainer;

        // `shopware.cart.processor` ist ein Tag, kein Dienst, und lässt sich nicht direkt holen. Der
        // Kern reicht die getaggten Prozessoren als `tagged_iterator` in den Konstruktor von
        // `Processor` (cart.xml). Diese Liste bestimmt die Ausführungsreihenfolge, deshalb wird sie
        // per Reflection dort gelesen.
        $processorService = $container->get(Processor::class);
        $eigenschaft = (new \ReflectionClass(Processor::class))->getProperty('processors');

        /** @var iterable<CartProcessorInterface> $processors */
        $processors = $eigenschaft->getValue($processorService);

        $this->order = [];
        foreach ($processors as $processor) {
            self::assertInstanceOf(CartProcessorInterface::class, $processor);
            $this->order[] = $processor::class;
        }

        self::assertNotEmpty($this->order, 'Ohne registrierte Processoren beweist der Test nichts.');
    }

    public function testDynamicPriceProcessorRunsBetweenProductPriceAndPromotions(): void
    {
        $own = $this->position(DynamicPriceProcessor::class);
        $produkt = $this->position(ProductCartProcessor::class);
        $promotion = $this->position(PromotionProcessor::class);

        self::assertGreaterThan(
            $produkt,
            $own,
            'DynamicPriceProcessor muss NACH dem Produktpreis laufen — sonst multipliziert er einen Preis aus, den es noch nicht gibt.',
        );
        self::assertLessThan(
            $promotion,
            $own,
            'DynamicPriceProcessor muss VOR den Promotions laufen, damit prozentuale Rabatte auf dem ausmultiplizierten Positions-Preis greifen.',
        );
    }

    /**
     * Ein fremder Prozessor zwischen dem eigenen und dem Promotion-Prozessor arbeitete mit Preisen,
     * die gerade erst ausmultipliziert wurden, und könnte sie vor dem Rabatt noch einmal ändern.
     */
    public function testNoForeignProcessorSitsBetweenUsAndThePromotions(): void
    {
        $own = $this->position(DynamicPriceProcessor::class);
        $promotion = $this->position(PromotionProcessor::class);

        $between = \array_slice($this->order, $own + 1, $promotion - $own - 1);

        self::assertSame(
            [],
            $between,
            'Zwischen DynamicPriceProcessor und PromotionProcessor darf nichts liegen. '
            . 'Gefunden: ' . implode(', ', $between),
        );
    }

    /** @param class-string $className */
    private function position(string $className): int
    {
        $index = array_search($className, $this->order, true);

        self::assertIsInt(
            $index,
            \sprintf('%s ist nicht als shopware.cart.processor registriert.', $className),
        );

        return $index;
    }
}
