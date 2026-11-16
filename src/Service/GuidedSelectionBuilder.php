<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\Storefront\Struct\GuidedSelectionStruct;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractAvailableCombinationLoader;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Product\ProductPage;

/**
 * Baut die Angaben der geführten Auswahl: Schritte, kaufbare Kombinationen, Name und Nummer des Elternartikels.
 *
 * Kaufbar heißt dasselbe wie beim Kern, der unmögliche Kombinationen durchstreicht: aktiv, im Verkaufskanal sichtbar
 * und verfügbar. Deshalb kommen die Kombinationen aus seinem eigenen Lader.
 */
final class GuidedSelectionBuilder
{
    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly AbstractAvailableCombinationLoader $combinationLoader,
        private readonly EntityRepository $productRepository,
    ) {
    }

    /**
     * `null` ohne kaufbare Kombination: Dann gibt es nichts zu führen, und die Seite bleibt beim Verhalten des Kerns.
     */
    public function build(ProductPage $page, string $parentId, string $entryGroupId, SalesChannelContext $context): ?GuidedSelectionStruct
    {
        $result = $this->combinationLoader->loadCombinations($parentId, $context);
        $combinations = array_values(array_filter(
            array_map(static fn (array $optionIds): array => array_values($optionIds), $result->getCombinations()),
            static fn (array $optionIds): bool => $result->isAvailable($optionIds),
        ));

        if ($combinations === []) {
            return null;
        }

        return new GuidedSelectionStruct(
            $entryGroupId,
            VariantGroupOrder::steps($page->getConfiguratorSettings(), $entryGroupId),
            $combinations,
            $this->parentName($page, $parentId, $context),
        );
    }

    /**
     * Name des Elternartikels. Ruft jemand den Elternartikel auf, lädt der Kern die beste Variante, und die Seite hieße
     * „… Milchglas (3m)", obwohl noch keine Länge gewählt ist. Bis zur Wahl zeigt das Skript deshalb diesen Namen; der
     * Variantenname bleibt im Markup für Suchmaschinen und ohne Skript. Die Nummer bleibt die der vorbelegten Variante.
     */
    private function parentName(ProductPage $page, string $parentId, SalesChannelContext $context): ?string
    {
        // Zeigt die Seite den Elternartikel selbst, trägt sie schon seinen Namen.
        if ($page->getProduct()->getId() === $parentId) {
            return null;
        }

        $parent = $this->productRepository->search(new Criteria([$parentId]), $context->getContext())->getEntities()->first();
        if ($parent === null) {
            return null;
        }

        $name = trim((string) ($parent->getTranslation('name') ?? $parent->getName()));

        return $name !== '' ? $name : null;
    }
}
