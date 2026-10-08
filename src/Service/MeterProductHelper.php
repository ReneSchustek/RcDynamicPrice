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
 * Produktladung und Rundung für den Meterpreis. Welche Einstellung gilt, entscheidet nicht diese
 * Klasse, sondern der MeterConfigResolver.
 */
final class MeterProductHelper implements MeterProductHelperInterface
{
    /**
     * Die Schrittweite jedes Rundungsmodus in Millimetern, die einzige Tabelle dafür. Der Server
     * rundet in `roundUp()` damit; das Storefront-Skript bekommt sie über
     * `RcDynamicPriceConfigStruct->roundingSteps` als Datenattribut
     * (`dynamic-price.plugin.js::_roundUp()`), sonst zeigte die Seite einen anderen Preis als der
     * Warenkorb.
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
        // Der Aufrufer liefert nicht immer eine Produktkennung. Ein Gutschein-Platzhalter trägt in
        // `referencedId` den Code. `Criteria` prüft beim Anlegen nur den Typ, die Ausnahme
        // (`InvalidUuidException`) fiele erst tief in der Datenbankschicht und risse den ganzen
        // Warenkorbvorgang mit; kein Gutschein wäre mehr einlösbar.
        if (!Uuid::isValid($productId)) {
            return null;
        }

        $criteria = new Criteria([$productId]);
        $criteria->setLimit(1);
        // Der Resolver braucht die Kategorien des Produkts, um die Kette zu finden.
        $criteria->addAssociation('categories');
        // Mit den Hauptkategorien erbt das Produkt je Verkaufskanal von der gepflegten Kategorie
        // statt von irgendeiner (siehe PrimaryCategory).
        $criteria->addAssociation('mainCategories');

        $product = $this->productRepository->search($criteria, $context)->getEntities()->first();

        return $product instanceof ProductEntity ? $product : null;
    }

    public function roundUp(int $mm, string $mode): int
    {
        // Die Dauer geht an den Recorder; ohne eingeschaltete Metriken verwirft er sie. Er wirft per
        // Vertrag nie, die Rechnung bleibt davon unberührt.
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
