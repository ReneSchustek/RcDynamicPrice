<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\Metrics\MetricsRecorderInterface;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Schlanke Utility-Klasse für Produkt-Ladung und Rundungs-Arithmetik.
 * Scope-abhängige Konfigurationsauflösung liegt im MeterConfigResolver.
 */
final class MeterProductHelper implements MeterProductHelperInterface
{
    /**
     * Single-Source-Tabelle aller Rundungsmodi. Wird vom Server in `roundUp()` genutzt
     * und vom Storefront-JS via `RcDynamicPriceConfigStruct->roundingSteps` über ein
     * Twig-Data-Attribut gelesen (siehe `dynamic-price.plugin.js::_roundUp()`).
     *
     * @var array<string, int>
     */
    public const ROUNDING_STEPS = [
        DynamicPriceConstants::ROUNDING_NONE => 0,
        DynamicPriceConstants::ROUNDING_CM => 10,
        DynamicPriceConstants::ROUNDING_QUARTER_M => 250,
        DynamicPriceConstants::ROUNDING_HALF_M => 500,
        DynamicPriceConstants::ROUNDING_FULL_M => 1000,
    ];

    /** @param EntityRepository<ProductCollection> $productRepository */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly MetricsRecorderInterface $metrics,
    ) {
    }

    public function loadProduct(string $productId, Context $context): ?ProductEntity
    {
        // Nicht darauf verlassen, dass der Aufrufer eine Produktkennung liefert.
        //
        // `Criteria` prüft beim Anlegen nur den Typ, nicht die Form — die Ausnahme fällt
        // erst tief in der Datenbankschicht (`InvalidUuidException`). Ein Gutschein-Platzhalter
        // trägt in `referencedId` den **Code** statt einer Kennung; ohne diese Prüfung riss
        // jeder Gutschein-Zugang den ganzen Warenkorb-Vorgang mit. Bei RcCartSplitter ist
        // genau das auf Live passiert — dort war monatelang kein Code einlösbar.
        if (!Uuid::isValid($productId)) {
            return null;
        }

        $criteria = new Criteria([$productId]);
        $criteria->setLimit(1);
        // Kategorie-Ketten-Resolver braucht categoryIds — categories-Assoziation füllt das zuverlässig.
        $criteria->addAssociation('categories');
        // mainCategories erlaubt dem Resolver, die händler-gepflegte Hauptkategorie pro
        // Sales Channel deterministisch zu bevorzugen (statt einer beliebigen Kategorie).
        $criteria->addAssociation('mainCategories');

        $product = $this->productRepository->search($criteria, $context)->getEntities()->first();

        return $product instanceof ProductEntity ? $product : null;
    }

    public function roundUp(int $mm, string $mode): int
    {
        // Timing-Hook (optionale Observability) — die Rechenlogik bleibt unverändert.
        // Der Recorder ist per Vertrag fail-safe (Default = NullMetricsRecorder).
        $start = microtime(true);

        $step = self::ROUNDING_STEPS[$mode] ?? 0;
        $result = $step <= 0 ? $mm : (int) (ceil($mm / $step) * $step);

        $this->metrics->timing(
            DynamicPriceConstants::METRIC_ROUNDING_DURATION,
            (microtime(true) - $start) * 1000.0,
            ['mode' => $mode],
        );

        return $result;
    }
}
