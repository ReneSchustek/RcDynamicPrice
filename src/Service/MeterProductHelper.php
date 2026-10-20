<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Produktladung und Rundung für den Meterpreis. Welche Einstellung gilt, entscheidet nicht diese
 * Klasse, sondern der MeterConfigResolver.
 */
final class MeterProductHelper implements MeterProductHelperInterface, ResetInterface
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

    /**
     * Beim Hinzufügen fragen zwei Subscriber nach demselben Produkt; geladen wird es je Anfrage einmal.
     *
     * @var array<string, ProductEntity|null>
     */
    private array $loaded = [];

    /** @param EntityRepository<ProductCollection> $productRepository */
    public function __construct(
        private readonly EntityRepository $productRepository,
    ) {
    }

    public function reset(): void
    {
        $this->loaded = [];
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

        $key = $productId . '|' . implode(',', $context->getLanguageIdChain()) . '|' . $context->getVersionId();
        if (\array_key_exists($key, $this->loaded)) {
            return $this->loaded[$key];
        }

        $criteria = new Criteria([$productId]);
        $criteria->setLimit(1);
        // Der Resolver braucht die Kategorien des Produkts, um die Kette zu finden.
        $criteria->addAssociation('categories');
        // Mit den Hauptkategorien erbt das Produkt je Verkaufskanal von der gepflegten Kategorie
        // statt von irgendeiner (siehe PrimaryCategory).
        $criteria->addAssociation('mainCategories');

        $product = $this->productRepository->search($criteria, $context)->getEntities()->first();

        return $this->loaded[$key] = $product instanceof ProductEntity ? $product : null;
    }

    public function roundUp(int $mm, string $mode): int
    {
        $step = self::ROUNDING_STEPS[$mode] ?? 0;

        return $step <= 0 ? $mm : (int) (ceil($mm / $step) * $step);
    }
}
