<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Benchmarks;

use PhpBench\Attributes as Bench;
use Ruhrcoder\RcDynamicPrice\Benchmarks\Support\ShopwareFixture;
use Ruhrcoder\RcDynamicPrice\Service\MeterConfigResolverInterface;
use RuntimeException;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;

/**
 * Das Auflösen der Meterpreis-Einstellungen über Produkt, Kategoriekette und Grundeinstellung.
 *
 * Es läuft auf jeder Produktseite und bei jedem „In den Warenkorb" eines Artikels, und es fragt die
 * Datenbank nach der Kategoriekette.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Revs(20)]
#[Bench\Iterations(5)]
#[Bench\Warmup(1)]
final class MeterConfigBench
{
    private MeterConfigResolverInterface $resolver;

    private ProductEntity $product;

    private string $salesChannelId;

    public function setUp(): void
    {
        $this->resolver = ShopwareFixture::service(MeterConfigResolverInterface::class);
        $this->product = ShopwareFixture::product();
        $this->salesChannelId = ShopwareFixture::salesChannelId();

        if (!$this->resolver->resolveForProduct($this->product, $this->salesChannelId, new Context(new SystemSource()))->active) {
            throw new RuntimeException('Für den Messartikel ist der Meterpreis aus; gemessen würde der kurze Weg.');
        }
    }

    public function benchResolveForProduct(): void
    {
        $this->resolver->resolveForProduct($this->product, $this->salesChannelId, new Context(new SystemSource()));
    }
}
