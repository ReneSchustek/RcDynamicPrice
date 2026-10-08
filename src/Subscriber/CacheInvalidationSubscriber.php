<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Subscriber;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\Content\Category\CategoryEvents;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Verwirft zwischengespeicherte Produktseiten, deren Meterpreis-Einstellungen sich geändert haben.
 *
 * Eine geänderte oder gelöschte Kategorie verwirft den Tag `rc-dynamic-price-category-{id}`, eine
 * geänderte Grundeinstellung den Tag `rc-dynamic-price-global`. Die Produktseiten tragen diese Tags
 * (siehe ProductPageSubscriber); so muss nicht der ganze HTTP-Cache geleert werden.
 */
final class CacheInvalidationSubscriber implements EventSubscriberInterface
{
    /**
     * Jede Einstellung der Erweiterung verwirft den globalen Tag, nicht nur eine Liste ausgewählter.
     *
     * Fast jede geht in die Produktseite ein: die Grenzen und die Stückelung über
     * `MeterConfigResolver`, die Abrechnung der gleichmäßigen Teilung und der Hinweistext über
     * `ProductPageSubscriber`. Eine Liste müsste bei jeder neuen Einstellung nachgezogen werden und
     * vergäße die erste, die niemand einträgt. Einstellungen ändern sich selten; ein Leeren zu viel
     * kostet nichts, ein vergessenes zeigt Kunden alte Preise.
     */
    private const CONFIG_PREFIX = 'RcDynamicPrice.config.';

    public function __construct(
        private readonly CacheInvalidator $cacheInvalidator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Schreiben und Löschen getrennt, damit auch eine entfernte Kategorie ihren Tag verwirft;
        // `EntityWrittenContainerEvent` allein deckt das Löschen nicht in allen Shopware-Fassungen ab.
        return [
            CategoryEvents::CATEGORY_WRITTEN_EVENT => 'onCategoryWritten',
            CategoryEvents::CATEGORY_DELETED_EVENT => 'onCategoryWritten',
            SystemConfigChangedEvent::class => 'onSystemConfigChanged',
        ];
    }

    public function onCategoryWritten(EntityWrittenEvent $event): void
    {
        $tags = [];
        foreach ($event->getIds() as $id) {
            $tags[] = DynamicPriceConstants::CACHE_TAG_CATEGORY_PREFIX . $id;
        }

        if ($tags === []) {
            return;
        }

        $this->cacheInvalidator->invalidate(array_values(array_unique($tags)));
    }

    public function onSystemConfigChanged(SystemConfigChangedEvent $event): void
    {
        if (!str_starts_with($event->getKey(), self::CONFIG_PREFIX)) {
            return;
        }

        $this->cacheInvalidator->invalidate([DynamicPriceConstants::CACHE_TAG_GLOBAL]);
    }
}
