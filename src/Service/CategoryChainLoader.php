<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Lädt eine Primärkategorie und alle ihre Vorfahren mit zwei Abfragen. Der Kern führt den Pfad
 * schon als `category.path` (`|eltern1|eltern2|`); daraus kommen die Vorfahren in einem Zug statt
 * einer Abfrage je Ebene.
 */
final class CategoryChainLoader implements CategoryChainLoaderInterface
{
    /** @param EntityRepository<CategoryCollection> $categoryRepository */
    public function __construct(
        private readonly EntityRepository $categoryRepository,
    ) {
    }

    public function loadChain(string $primaryCategoryId, Context $context): array
    {
        $primary = $this->loadCategory($primaryCategoryId, $context);
        if ($primary === null) {
            return [];
        }

        $ancestorIds = $this->parsePathIds($primary->getPath() ?? '');
        if ($ancestorIds === []) {
            return [$this->toEntry($primary)];
        }

        $ancestors = $this->loadCategories($ancestorIds, $context);

        $chain = [$this->toEntry($primary)];
        // Der Pfad läuft von der Wurzel zum Blatt; die Kette braucht die nächste Kategorie zuerst.
        foreach (array_reverse($ancestorIds) as $ancestorId) {
            $entity = $ancestors[$ancestorId] ?? null;
            if ($entity instanceof CategoryEntity) {
                $chain[] = $this->toEntry($entity);
            }
        }

        return $chain;
    }

    private function loadCategory(string $id, Context $context): ?CategoryEntity
    {
        $criteria = new Criteria([$id]);
        $criteria->setLimit(1);

        $entity = $this->categoryRepository->search($criteria, $context)->getEntities()->first();

        return $entity instanceof CategoryEntity ? $entity : null;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, CategoryEntity>
     */
    private function loadCategories(array $ids, Context $context): array
    {
        if ($ids === []) {
            return [];
        }

        $criteria = new Criteria($ids);
        // Mehr Treffer als gesuchte Kennungen kann es nicht geben.
        $criteria->setLimit(\count($ids));

        $result = $this->categoryRepository->search($criteria, $context);

        $map = [];
        // Die Sammlung führt laut Typangabe nur Kategorien; eine Prüfung darauf entfällt.
        foreach ($result->getEntities() as $category) {
            $map[$category->getId()] = $category;
        }

        return $map;
    }

    /**
     * Zerlegt den Shopware-Kategorie-Pfad `|id1|id2|` in eine geordnete Liste von IDs.
     *
     * @return list<string>
     */
    private function parsePathIds(string $path): array
    {
        if ($path === '') {
            return [];
        }

        $ids = array_values(array_filter(
            explode('|', $path),
            static fn (string $segment): bool => $segment !== '',
        ));

        return $ids;
    }

    /**
     * @return array{id: string, customFields: array<string, mixed>}
     */
    private function toEntry(CategoryEntity $category): array
    {
        return [
            'id' => $category->getId(),
            'customFields' => $category->getCustomFields() ?? [],
        ];
    }
}
