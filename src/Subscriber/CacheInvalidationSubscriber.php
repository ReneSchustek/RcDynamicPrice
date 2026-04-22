<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Subscriber;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Haelt den Meterpreis-bezogenen HTTP-Cache konsistent.
 * - Aenderungen an Kategorien invalidieren den `rc-dynamic-price-category-{id}`-Tag.
 * - Aenderungen an Plugin-Config-Werten, die in die Resolver-Kette einfliessen,
 *   invalidieren den globalen `rc-dynamic-price-global`-Tag.
 *
 * Produkte, die ihre Konfiguration aus dem Resolver ziehen, lagern diese Tags
 * auf ihre Produktseiten an (siehe ProductPageSubscriber). Gezielte Invalidierung
 * verhindert Vollinvalidierung des gesamten HTTP-Caches.
 */
final class CacheInvalidationSubscriber implements EventSubscriberInterface
{
    /**
     * Plugin-Config-Keys, die eine globale Invalidierung ausloesen.
     * Nur die Keys, die wirklich in Resolver-Ergebnisse einfliessen — andere
     * Plugin-Einstellungen (z. B. reine UI-Texte) bleiben unberuehrt.
     */
    private const INVALIDATING_CONFIG_KEYS = [
        DynamicPriceConstants::CONFIG_APPLY_TO_ALL_PRODUCTS,
        DynamicPriceConstants::CONFIG_MIN_LENGTH,
        DynamicPriceConstants::CONFIG_MAX_LENGTH,
        DynamicPriceConstants::CONFIG_SPLIT_MODE,
        DynamicPriceConstants::CONFIG_MAX_PIECE_LENGTH,
        DynamicPriceConstants::CONFIG_SPLIT_HINT_TEMPLATE,
    ];

    public function __construct(
        private readonly CacheInvalidator $cacheInvalidator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityWrittenContainerEvent::class => 'onEntityWritten',
            SystemConfigChangedEvent::class => 'onSystemConfigChanged',
        ];
    }

    public function onEntityWritten(EntityWrittenContainerEvent $event): void
    {
        $categoryEvent = $event->getEventByEntityName(CategoryDefinition::ENTITY_NAME);
        if ($categoryEvent === null) {
            return;
        }

        $tags = [];
        foreach ($categoryEvent->getIds() as $id) {
            $tags[] = DynamicPriceConstants::CACHE_TAG_CATEGORY_PREFIX . $id;
        }

        if ($tags === []) {
            return;
        }

        $this->cacheInvalidator->invalidate(array_values(array_unique($tags)));
    }

    public function onSystemConfigChanged(SystemConfigChangedEvent $event): void
    {
        if (!\in_array($event->getKey(), self::INVALIDATING_CONFIG_KEYS, true)) {
            return;
        }

        $this->cacheInvalidator->invalidate([DynamicPriceConstants::CACHE_TAG_GLOBAL]);
    }
}
