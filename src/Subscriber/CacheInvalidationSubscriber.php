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
 * Hält den Meterpreis-bezogenen HTTP-Cache konsistent.
 * - Änderungen und Löschungen an Kategorien invalidieren den
 *   `rc-dynamic-price-category-{id}`-Tag.
 * - Änderungen an Plugin-Config-Werten, die in die Resolver-Kette einfließen,
 *   invalidieren den globalen `rc-dynamic-price-global`-Tag.
 *
 * Produkte, die ihre Konfiguration aus dem Resolver ziehen, hängen diese Tags
 * an ihre Produktseiten an (siehe ProductPageSubscriber). Gezielte Invalidierung
 * verhindert eine Vollinvalidierung des gesamten HTTP-Caches.
 */
final class CacheInvalidationSubscriber implements EventSubscriberInterface
{
    /**
     * Plugin-Config-Keys, die eine globale Invalidierung auslösen.
     * Nur die Keys, die wirklich in Resolver-Ergebnisse einfließen — andere
     * Plugin-Einstellungen (z. B. reine UI-Texte) bleiben unberührt.
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
        // Write- und Delete-Event getrennt abonnieren, damit das Entfernen einer Kategorie
        // ihre Meterpreis-Cache-Tags zuverlässig invalidiert. `EntityWrittenContainerEvent`
        // allein deckt Delete nicht in allen Shopware-Versionen ab.
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
        if (!\in_array($event->getKey(), self::INVALIDATING_CONFIG_KEYS, true)) {
            return;
        }

        $this->cacheInvalidator->invalidate([DynamicPriceConstants::CACHE_TAG_GLOBAL]);
    }
}
