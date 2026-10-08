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
 * Der Subscriber hängt am Warenkorb-Zugang und liest `referencedId` als Produktkennung. Bei einem
 * Gutschein-Platzhalter steht dort der Code, so setzt ihn Shopwares eigener `PromotionItemBuilder`.
 * Wirft die Produktsuche dann eine Ausnahme, reißt sie den ganzen Vorgang mit, und kein
 * Gutscheincode ist mehr einlösbar.
 *
 * Geprüft wird an der echten Datenbankschicht, weil nur sie entscheidet, ob eine Kennung, die
 * keine UUID ist, zu einer Ausnahme führt.
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

        $result = $helper->loadProduct('Sommer2026', Context::createDefaultContext());

        self::assertNull($result);
    }

    /**
     * Was: Eine gültige, aber unbekannte Kennung.
     * Warum: Gegenprobe. Der Normalfall „Produkt gibt es nicht" liefert still null und verhält
     *        sich nicht anders als der Fall mit dem Gutscheincode darüber.
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
