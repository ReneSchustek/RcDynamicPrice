<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Subscriber\CacheInvalidationSubscriber;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;

final class CacheInvalidationSubscriberTest extends TestCase
{
    private CacheInvalidator $cacheInvalidator;
    private CacheInvalidationSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->cacheInvalidator = $this->createMock(CacheInvalidator::class);
        $this->subscriber = new CacheInvalidationSubscriber($this->cacheInvalidator);
    }

    public function testIgnoresUnrelatedEntityWrites(): void
    {
        $container = $this->createMock(EntityWrittenContainerEvent::class);
        $container->method('getEventByEntityName')->willReturn(null);

        $this->cacheInvalidator->expects($this->never())->method('invalidate');

        $this->subscriber->onEntityWritten($container);
    }

    public function testInvalidatesCategoryTagOnCategoryWrite(): void
    {
        $writeEvent = $this->createMock(EntityWrittenEvent::class);
        $writeEvent->method('getIds')->willReturn(['cat-1', 'cat-2']);

        $container = $this->createMock(EntityWrittenContainerEvent::class);
        $container
            ->method('getEventByEntityName')
            ->with(CategoryDefinition::ENTITY_NAME)
            ->willReturn($writeEvent);

        $this->cacheInvalidator
            ->expects($this->once())
            ->method('invalidate')
            ->with($this->equalTo([
                'rc-dynamic-price-category-cat-1',
                'rc-dynamic-price-category-cat-2',
            ]));

        $this->subscriber->onEntityWritten($container);
    }

    public function testIgnoresUnrelatedConfigChange(): void
    {
        $event = new SystemConfigChangedEvent('SomeOther.config.key', null, null);

        $this->cacheInvalidator->expects($this->never())->method('invalidate');

        $this->subscriber->onSystemConfigChanged($event);
    }

    public function testInvalidatesGlobalTagOnApplyToAllConfigChange(): void
    {
        $event = new SystemConfigChangedEvent('RcDynamicPrice.config.applyToAllProducts', true, null);

        $this->cacheInvalidator
            ->expects($this->once())
            ->method('invalidate')
            ->with(['rc-dynamic-price-global']);

        $this->subscriber->onSystemConfigChanged($event);
    }

    public function testInvalidatesGlobalTagOnSplitModeConfigChange(): void
    {
        $event = new SystemConfigChangedEvent('RcDynamicPrice.config.splitMode', 'equal', null);

        $this->cacheInvalidator
            ->expects($this->once())
            ->method('invalidate')
            ->with(['rc-dynamic-price-global']);

        $this->subscriber->onSystemConfigChanged($event);
    }
}
