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
 * Sichert die geld-kritische Cart-Processor-Reihenfolge ab — **gemessen am kompilierten
 * Container**, nicht aus der services.xml abgelesen.
 *
 * Hintergrund: v1.8.1 hat einen Live-Skonto-Fehler behoben, indem der `DynamicPriceProcessor`
 * zwischen `ProductCartProcessor` und `PromotionProcessor` einsortiert wurde. Läuft er daneben,
 * bezieht sich ein prozentualer Rabatt auf den falschen Preis — roher Meter-Stückpreis statt
 * ausmultipliziertem Positions-Preis.
 *
 * Die Vorgängerfassung parste die `services.xml` und verglich die Priority gegen drei
 * hartkodierte Konstanten. Sie benannte ihre Grenze selbst: „Ohne gebooteten Shopware-Kernel
 * lässt sich der kompilierte Container nicht auslesen; neue Core-/Plugin-Processoren mit anderen
 * Prioritäten erkennt dieser Test nicht." Damit prüfte sie, ob *unsere Zahl* unverändert ist —
 * nicht, ob unser Processor tatsächlich an der richtigen Stelle läuft. Ein Core-Update, das den
 * PromotionProcessor verschiebt, oder ein Plugin, das sich dazwischenlegt, blieb unsichtbar.
 *
 * Seit die Integration-Tests einen Kernel bekommen, fällt diese Grenze weg: Hier wird die
 * tatsächliche Reihenfolge der registrierten Processoren gelesen.
 */
final class CartProcessorOrderingTest extends TestCase
{
    /**
     * Die real registrierten Cart-Processoren in Ausführungsreihenfolge.
     *
     * @var list<class-string>
     */
    private array $reihenfolge;

    protected function setUp(): void
    {
        $container = KernelLifecycleManager::getKernel()->getContainer();

        // Der Test-Container macht private Dienste zugänglich.
        $testContainer = $container->has('test.service_container')
            ? $container->get('test.service_container')
            : $container;
        self::assertInstanceOf(ContainerInterface::class, $testContainer);
        $container = $testContainer;

        // `shopware.cart.processor` ist ein Tag, kein Dienst — es gibt nichts, was man direkt
        // holen könnte. Der Core reicht die getaggten Processoren als `tagged_iterator` in den
        // Konstruktor von `Processor` (cart.xml). Genau diese Liste ist die Wahrheit über die
        // Ausführungsreihenfolge, deshalb wird sie per Reflection dort abgegriffen.
        $processorService = $container->get(Processor::class);
        $eigenschaft = (new \ReflectionClass(Processor::class))->getProperty('processors');

        /** @var iterable<CartProcessorInterface> $processors */
        $processors = $eigenschaft->getValue($processorService);

        $this->reihenfolge = [];
        foreach ($processors as $processor) {
            self::assertInstanceOf(CartProcessorInterface::class, $processor);
            $this->reihenfolge[] = $processor::class;
        }

        self::assertNotEmpty($this->reihenfolge, 'Ohne registrierte Processoren beweist der Test nichts.');
    }

    public function testDynamicPriceProcessorZwischenProduktpreisUndPromotions(): void
    {
        $eigen = $this->position(DynamicPriceProcessor::class);
        $produkt = $this->position(ProductCartProcessor::class);
        $promotion = $this->position(PromotionProcessor::class);

        self::assertGreaterThan(
            $produkt,
            $eigen,
            'DynamicPriceProcessor muss NACH dem Produktpreis laufen — sonst multipliziert er einen Preis aus, den es noch nicht gibt.',
        );
        self::assertLessThan(
            $promotion,
            $eigen,
            'DynamicPriceProcessor muss VOR den Promotions laufen, damit prozentuale Rabatte auf dem ausmultiplizierten Positions-Preis greifen.',
        );
    }

    /**
     * Der Fall, den die alte Fassung ausdrücklich nicht finden konnte: ein fremder Processor,
     * der sich zwischen unseren und den Promotion-Processor schiebt und dort mit Preisen
     * arbeitet, die wir gerade erst gesetzt haben.
     */
    public function testKeinFremderProcessorLiegtZwischenUnsUndDenPromotions(): void
    {
        $eigen = $this->position(DynamicPriceProcessor::class);
        $promotion = $this->position(PromotionProcessor::class);

        $dazwischen = \array_slice($this->reihenfolge, $eigen + 1, $promotion - $eigen - 1);

        self::assertSame(
            [],
            $dazwischen,
            'Zwischen DynamicPriceProcessor und PromotionProcessor darf nichts liegen. '
            . 'Gefunden: ' . implode(', ', $dazwischen),
        );
    }

    /** @param class-string $klasse */
    private function position(string $klasse): int
    {
        $index = array_search($klasse, $this->reihenfolge, true);

        self::assertIsInt(
            $index,
            \sprintf('%s ist nicht als shopware.cart.processor registriert.', $klasse),
        );

        return $index;
    }
}
