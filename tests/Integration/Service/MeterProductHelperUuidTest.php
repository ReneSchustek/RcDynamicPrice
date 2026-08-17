<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Integration\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelper;
use Ruhrcoder\RcDynamicPrice\Service\Metrics\NullMetricsRecorder;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Was passiert, wenn die Produktkennung gar keine ist.
 *
 * Der Subscriber hängt am Warenkorb-Zugang und liest `referencedId` als Produktkennung.
 * Bei einem Gutschein-Platzhalter steht dort aber der **Code** — Shopwares eigener
 * `PromotionItemBuilder` setzt ihn so. Genau daran ist RcCartSplitter gescheitert: Die
 * Ausnahme riss den ganzen Vorgang mit, und kein Gutscheincode war mehr einlösbar.
 *
 * Dieser Test klärt die offene Frage für dieses Plugin an der echten Datenbankschicht,
 * nicht an einer Attrappe: Wirft die Produktsuche bei einer Kennung, die keine UUID ist?
 */
class MeterProductHelperUuidTest extends TestCase
{
    use IntegrationTestBehaviour;

    /**
     * Was: Produktsuche mit einem Gutscheincode statt einer Kennung.
     * Warum: Der Warenkorb-Zugang darf an keiner Position zerbrechen, die kein Produkt ist.
     * Erwartet: kein Wurf, Ergebnis null.
     */
    public function testLoadingAProductWithACouponCodeInsteadOfAnIdDoesNotThrow(): void
    {
        $helper = $this->createHelper();

        $ergebnis = $helper->loadProduct('Sommer2026', Context::createDefaultContext());

        self::assertNull($ergebnis);
    }

    /**
     * Was: Eine gültige, aber unbekannte Kennung.
     * Warum: Gegenprobe — der Normalfall „Produkt gibt es nicht" muss weiterhin still null
     *        liefern und darf sich nicht anders verhalten als der Fehlerfall darüber.
     * Erwartet: kein Wurf, Ergebnis null.
     */
    public function testLoadingAnUnknownButValidIdReturnsNull(): void
    {
        $helper = $this->createHelper();

        self::assertNull($helper->loadProduct(Uuid::randomHex(), Context::createDefaultContext()));
    }

    private function createHelper(): MeterProductHelper
    {
        $repository = static::getContainer()->get('product.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        /** @var EntityRepository<ProductCollection> $repository */
        return new MeterProductHelper($repository, new NullMetricsRecorder());
    }
}
