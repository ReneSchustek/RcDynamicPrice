<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Subscriber;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Subscriber\CacheInvalidationSubscriber;
use Shopware\Core\Content\Category\CategoryEvents;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;

/**
 * Ändert sich eine Kategorie oder eine Grundeinstellung des Meterpreises, leert der Subscriber
 * genau die betroffenen Cache-Tags. Bleibt ein Tag stehen, zeigt die Produktseite weiter die alten
 * Grenzen oder den alten Preis.
 */
final class CacheInvalidationSubscriberTest extends TestCase
{
    private CacheInvalidator&MockObject $cacheInvalidator;
    private CacheInvalidationSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->cacheInvalidator = $this->createMock(CacheInvalidator::class);
        $this->subscriber = new CacheInvalidationSubscriber($this->cacheInvalidator);
    }

    public function testSubscribesToCategoryWriteAndDeleteAndConfigChange(): void
    {
        $subscribed = CacheInvalidationSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(CategoryEvents::CATEGORY_WRITTEN_EVENT, $subscribed);
        $this->assertArrayHasKey(CategoryEvents::CATEGORY_DELETED_EVENT, $subscribed);
        $this->assertArrayHasKey(SystemConfigChangedEvent::class, $subscribed);
    }

    public function testIgnoresEmptyCategoryIds(): void
    {
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getIds')->willReturn([]);

        $this->cacheInvalidator->expects($this->never())->method('invalidate');

        $this->subscriber->onCategoryWritten($event);
    }

    public function testInvalidatesCategoryTagOnCategoryWrite(): void
    {
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getIds')->willReturn(['cat-1', 'cat-2']);

        $this->cacheInvalidator
            ->expects($this->once())
            ->method('invalidate')
            ->with($this->equalTo([
                'rc-dynamic-price-category-cat-1',
                'rc-dynamic-price-category-cat-2',
            ]));

        $this->subscriber->onCategoryWritten($event);
    }

    public function testInvalidatesCategoryTagOnCategoryDelete(): void
    {
        // EntityDeletedEvent ist eine Unterklasse von EntityWrittenEvent und läuft deshalb durch
        // denselben Handler; das Ereignis ist hier nur nachgebildet.
        $event = $this->createMock(EntityDeletedEvent::class);
        $event->method('getIds')->willReturn(['cat-deleted-id']);

        $this->cacheInvalidator
            ->expects($this->once())
            ->method('invalidate')
            ->with(['rc-dynamic-price-category-cat-deleted-id']);

        $this->subscriber->onCategoryWritten($event);
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

    /**
     * Die drei Einstellungen, die eine feste Liste vergessen hatte: Sie gehen über die Abrechnung der
     * gleichmäßigen Teilung und den Hinweistext in die Produktseite ein.
     */
    #[DataProvider('pageSettings')]
    public function testEverySettingThatReachesTheProductPageInvalidatesIt(string $key): void
    {
        $this->cacheInvalidator
            ->expects($this->once())
            ->method('invalidate')
            ->with(['rc-dynamic-price-global']);

        $this->subscriber->onSystemConfigChanged(new SystemConfigChangedEvent($key, 'neu', null));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pageSettings(): iterable
    {
        yield 'Abrechnung der gleichmäßigen Teilung' => [DynamicPriceConstants::CONFIG_EQUAL_BILLING];
        yield 'Mindestlänge je Teilstück erzwingen' => [DynamicPriceConstants::CONFIG_EQUAL_ENFORCE_MIN];
        yield 'Hinweistext' => [DynamicPriceConstants::CONFIG_HINT_TEXT];
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
