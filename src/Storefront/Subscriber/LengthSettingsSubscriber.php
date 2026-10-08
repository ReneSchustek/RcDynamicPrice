<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Storefront\Subscriber;

use Ruhrcoder\RcDynamicPrice\Service\LengthSettings;
use Ruhrcoder\RcDynamicPrice\Service\LengthSettingsResolver;
use Ruhrcoder\RcDynamicPrice\Storefront\Struct\GuidedSelectionStruct;
use Ruhrcoder\RcDynamicPrice\Storefront\Struct\LengthSettingsStruct;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractAvailableCombinationLoader;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gibt der Produktseite das Längen-Verhalten mit: Längenschalter, „Größen nur über die Länge" und die
 * Angaben der geführten Auswahl.
 *
 * Von den eingestellten Längengruppen gilt die erste, die der Artikel hat. So passt eine Einstellung an
 * der Kategorie auch dort, wo die Längen mal „Maße", mal „Länge" heißen.
 *
 * Kaufbar heißt für die geführte Auswahl dasselbe wie beim Kern, der unmögliche Kombinationen
 * durchstreicht: aktiv, im Verkaufskanal sichtbar und verfügbar. Deshalb kommen die Kombinationen aus
 * seinem eigenen Lader.
 */
final class LengthSettingsSubscriber implements EventSubscriberInterface
{
    public const EXTENSION_NAME = 'rcLengthSettings';

    public const GUIDED_EXTENSION_NAME = 'rcGuidedSelection';

    public function __construct(
        private readonly LengthSettingsResolver $resolver,
        private readonly AbstractAvailableCombinationLoader $combinationLoader,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $product = $page->getProduct();
        $salesChannelContext = $event->getSalesChannelContext();

        // Gezeigt wird eine Variante; ohne Hauptvariante kann es auch das Elternprodukt selbst sein.
        $parentId = $product->getParentId() ?? ($product->getChildCount() > 0 ? $product->getId() : null);
        if ($parentId === null) {
            return;
        }

        $settings = $this->resolver->forProduct($product, $salesChannelContext->getSalesChannelId(), $salesChannelContext->getContext());
        if ($settings->isEmpty()) {
            return;
        }

        $lengthGroupId = self::lengthGroupOnPage($page->getConfiguratorSettings(), $settings);

        // Ohne Längengruppe auf der Seite gäbe es keine Knöpfe, die sich ausblenden ließen.
        $lengthOnly = $settings->lengthOnly && $settings->lengthSwitch && $lengthGroupId !== null;

        $page->addExtension(self::EXTENSION_NAME, new LengthSettingsStruct($settings->lengthSwitch, $lengthOnly, $lengthGroupId, $settings->lengthFieldNumber));

        if ($settings->guidedSelection && $lengthGroupId !== null && !$lengthOnly) {
            $this->addGuidedSelection($page, $parentId, $lengthGroupId, $salesChannelContext);
        }
    }

    /**
     * Die erste eingestellte Längengruppe, die unter den Gruppen der Seite ist.
     */
    public static function lengthGroupOnPage(PropertyGroupCollection $pageGroups, LengthSettings $settings): ?string
    {
        foreach ($settings->lengthGroupIds as $groupId) {
            if ($pageGroups->has($groupId)) {
                return $groupId;
            }
        }

        return null;
    }

    /**
     * Die Gruppen der Seite als Schritte: die Längengruppe zuerst, die übrigen in der Reihenfolge, in der
     * der Kern sie zeigt.
     *
     * @return list<array{id: string, optionIds: list<string>}>
     */
    public static function orderedGroups(PropertyGroupCollection $settings, string $lengthGroupId): array
    {
        $groups = [];
        foreach ($settings as $group) {
            $entry = ['id' => $group->getId(), 'optionIds' => array_values($group->getOptions()?->getIds() ?? [])];

            if ($group->getId() === $lengthGroupId) {
                array_unshift($groups, $entry);
            } else {
                $groups[] = $entry;
            }
        }

        return $groups;
    }

    private function addGuidedSelection(ProductPage $page, string $parentId, string $lengthGroupId, SalesChannelContext $context): void
    {
        $settings = $page->getConfiguratorSettings();

        $result = $this->combinationLoader->loadCombinations($parentId, $context);
        $combinations = array_values(array_filter(
            array_map(static fn (array $optionIds): array => array_values($optionIds), $result->getCombinations()),
            static fn (array $optionIds): bool => $result->isAvailable($optionIds),
        ));

        if ($combinations === []) {
            return;
        }

        $page->addExtension(
            self::GUIDED_EXTENSION_NAME,
            new GuidedSelectionStruct($lengthGroupId, self::orderedGroups($settings, $lengthGroupId), $combinations),
        );
    }
}
