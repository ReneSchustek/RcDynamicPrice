<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Service\CategoryChainLoader;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

final class CategoryChainLoaderTest extends TestCase
{
    private EntityRepository $categoryRepository;
    private CategoryChainLoader $loader;

    protected function setUp(): void
    {
        $this->categoryRepository = $this->createMock(EntityRepository::class);
        $this->loader = new CategoryChainLoader($this->categoryRepository);
    }

    public function testReturnsEmptyWhenPrimaryCategoryMissing(): void
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn(null);

        $this->categoryRepository->method('search')->willReturn($result);

        $this->assertSame([], $this->loader->loadChain('missing-id', Context::createDefaultContext()));
    }

    public function testReturnsSingleEntryWhenNoAncestors(): void
    {
        $primary = $this->buildCategory('primary-id', '', ['key' => 'value']);

        $this->categoryRepository
            ->expects($this->once())
            ->method('search')
            ->willReturn($this->singleResult($primary));

        $chain = $this->loader->loadChain('primary-id', Context::createDefaultContext());

        $this->assertCount(1, $chain);
        $this->assertSame('primary-id', $chain[0]['id']);
        $this->assertSame(['key' => 'value'], $chain[0]['customFields']);
    }

    public function testReturnsChainFromPrimaryToRoot(): void
    {
        $primary = $this->buildCategory('leaf-id', '|root-id|mid-id|', ['pos' => 'leaf']);
        $root = $this->buildCategory('root-id', null, ['pos' => 'root']);
        $mid = $this->buildCategory('mid-id', '|root-id|', ['pos' => 'mid']);

        $this->categoryRepository
            ->method('search')
            ->willReturnOnConsecutiveCalls(
                $this->singleResult($primary),
                $this->collectionResult([$root, $mid]),
            );

        $chain = $this->loader->loadChain('leaf-id', Context::createDefaultContext());

        $this->assertCount(3, $chain);
        $this->assertSame('leaf-id', $chain[0]['id']);
        $this->assertSame('mid-id', $chain[1]['id']);
        $this->assertSame('root-id', $chain[2]['id']);
        $this->assertSame('leaf', $chain[0]['customFields']['pos']);
        $this->assertSame('mid', $chain[1]['customFields']['pos']);
        $this->assertSame('root', $chain[2]['customFields']['pos']);
    }

    public function testSkipsMissingAncestorWithoutFailing(): void
    {
        $primary = $this->buildCategory('leaf-id', '|root-id|mid-id|', []);
        $root = $this->buildCategory('root-id', null, []);
        // mid-id fehlt — Loader muss robust die Reste liefern.

        $this->categoryRepository
            ->method('search')
            ->willReturnOnConsecutiveCalls(
                $this->singleResult($primary),
                $this->collectionResult([$root]),
            );

        $chain = $this->loader->loadChain('leaf-id', Context::createDefaultContext());

        $this->assertCount(2, $chain);
        $this->assertSame('leaf-id', $chain[0]['id']);
        $this->assertSame('root-id', $chain[1]['id']);
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function buildCategory(string $id, ?string $path, array $customFields): CategoryEntity
    {
        $category = new CategoryEntity();
        $category->setId($id);
        $category->setUniqueIdentifier($id);
        if ($path !== null) {
            $category->setPath($path);
        }
        $category->setCustomFields($customFields);

        return $category;
    }

    private function singleResult(?CategoryEntity $entity): EntitySearchResult
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn($entity);

        return $result;
    }

    /** @param list<CategoryEntity> $entities */
    private function collectionResult(array $entities): EntitySearchResult
    {
        $collection = new CategoryCollection($entities);

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn($collection);

        return $result;
    }
}
