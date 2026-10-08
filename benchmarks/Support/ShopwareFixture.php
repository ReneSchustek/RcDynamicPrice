<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Benchmarks\Support;

use Composer\Autoload\ClassLoader;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\DbalKernelPluginLoader;
use Shopware\Core\Kernel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Der Messbestand: ein gestarteter Kern und ein Meterpreis-Artikel aus dem Bestand der Instanz.
 *
 * Fehlt der Artikel, bricht der Lauf mit Erklärung ab, statt eine Zahl für nichts zu melden.
 */
final class ShopwareFixture
{
    /** Variante eines Bodenprofils mit Meterpreis am Elternartikel, im Bestand von live-clone. */
    public const PRODUCT_NUMBER = 'BP-1110';

    private static ?KernelInterface $kernel = null;

    /**
     * Ein Dienst aus dem Container des Testmodus, der auch private Dienste herausgibt.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    public static function service(string $type, ?string $id = null): object
    {
        self::$kernel ??= self::boot();

        /** @var ContainerInterface $container */
        $container = self::$kernel->getContainer()->get('test.service_container');
        $service = $container->get($id ?? $type);

        if (!$service instanceof $type) {
            throw new RuntimeException(\sprintf('Dienst %s ist kein %s.', $id ?? $type, $type));
        }

        return $service;
    }

    /**
     * Der Artikel so, wie die Produktseite ihn dem Resolver gibt: mit Kategorien und Hauptkategorien,
     * mit Vererbung vom Elternartikel.
     */
    public static function product(): ProductEntity
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('productNumber', self::PRODUCT_NUMBER))
            ->addAssociation('mainCategories');

        $context = new Context(new SystemSource());
        $context->setConsiderInheritance(true);

        /** @var EntityRepository<ProductCollection> $repository */
        $repository = self::service(EntityRepository::class, 'product.repository');
        $product = $repository->search($criteria, $context)->getEntities()->first();

        if ($product === null || ($product->getCategoryIds() ?? []) === []) {
            throw new RuntimeException(\sprintf('Der Messartikel %s fehlt im Bestand oder hat keine Kategorie; die Kette liefe ins Leere.', self::PRODUCT_NUMBER));
        }

        return $product;
    }

    /**
     * Der Verkaufskanal zur Adresse der Instanz (`APP_URL`).
     */
    public static function salesChannelId(): string
    {
        $id = self::service(Connection::class)->fetchOne(
            'SELECT LOWER(HEX(sales_channel_id)) FROM sales_channel_domain WHERE url = :url LIMIT 1',
            ['url' => (string) ($_SERVER['APP_URL'] ?? '')],
        );
        if (!\is_string($id)) {
            throw new RuntimeException('Kein Verkaufskanal zur Adresse APP_URL gefunden.');
        }

        return $id;
    }

    /**
     * Testmodus wegen des Zugriffs auf private Dienste, ohne Debug, damit die Sammler des
     * Debug-Modus nicht mitgemessen werden.
     */
    private static function boot(): KernelInterface
    {
        /** @var ClassLoader $classLoader */
        $classLoader = $GLOBALS['rcBenchmarkClassLoader'];

        $kernel = KernelFactory::create(
            environment: 'test',
            debug: false,
            classLoader: $classLoader,
            pluginLoader: new DbalKernelPluginLoader($classLoader, null, Kernel::getConnection()),
        );
        if (!$kernel instanceof KernelInterface) {
            throw new RuntimeException('Die Kern-Fabrik lieferte keinen startbaren Kern.');
        }

        $kernel->boot();

        return $kernel;
    }
}
