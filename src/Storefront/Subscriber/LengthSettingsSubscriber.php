<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Storefront\Subscriber;

use Ruhrcoder\RcDynamicPrice\Service\LengthSettings;
use Ruhrcoder\RcDynamicPrice\Service\LengthSettingsResolver;
use Ruhrcoder\RcDynamicPrice\Storefront\Struct\GuidedSelectionStruct;
use Ruhrcoder\RcDynamicPrice\Storefront\Struct\LengthSettingsStruct;
use Shopware\Core\Content\Cms\SalesChannel\Struct\BuyBoxStruct;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractAvailableCombinationLoader;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
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

    /**
     * @param EntityRepository<ProductCollection>|null $productRepository
     */
    public function __construct(
        private readonly LengthSettingsResolver $resolver,
        private readonly AbstractAvailableCombinationLoader $combinationLoader,
        private readonly ?SystemConfigService $systemConfig = null,
        private readonly ?EntityRepository $productRepository = null,
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

        // Geführte Auswahl für alle Variantenartikel (Rene, 14.10.2026: „Es darf nirgends mehr die durchgestrichenen
        // Artikel da sein. Das soll ja den Kunden führen und muss logisch sein."): Ohne Längengruppe beginnt sie mit
        // der obersten Gruppe der Seite; welche das ist, legt das Theme fest (etwa die Glasstärke vor dem Glashalter).
        $guidedForAll = $this->systemConfig?->getBool('RcDynamicPrice.config.guidedSelectionAllVariants', $salesChannelContext->getSalesChannelId()) ?? false;

        if ($settings->isEmpty()) {
            $firstGroupId = self::firstGroupId($page->getConfiguratorSettings());
            if ($guidedForAll && $firstGroupId !== null) {
                $this->addGuidedSelection($page, $parentId, $firstGroupId, $salesChannelContext);
            }

            return;
        }

        $lengthGroupId = self::lengthGroupOnPage($page->getConfiguratorSettings(), $settings);

        // Die Länge steht oben: Der Kunde soll sie kennen, bevor er über Ausführung, Farbe oder Gehrung
        // entscheidet. Der Kern ordnet die Gruppen nach ihrer eigenen Position.
        if ($lengthGroupId !== null) {
            self::putLengthGroupFirst($page, $lengthGroupId);
        }

        // Ohne Längengruppe auf der Seite gäbe es keine Knöpfe, die sich ausblenden ließen.
        $lengthOnly = $settings->lengthOnly && $settings->lengthSwitch && $lengthGroupId !== null;

        $page->addExtension(self::EXTENSION_NAME, new LengthSettingsStruct($settings->lengthSwitch, $lengthOnly, $lengthGroupId, $settings->lengthFieldNumber));

        $entryGroupId = $lengthGroupId ?? self::firstGroupId($page->getConfiguratorSettings());
        if (!$lengthOnly && $entryGroupId !== null && (($settings->guidedSelection && $lengthGroupId !== null) || $guidedForAll)) {
            $this->addGuidedSelection($page, $parentId, $entryGroupId, $salesChannelContext);
        }
    }

    /**
     * Die oberste Gruppe der Seite, wenn es mindestens zwei gibt; mit einer gibt es nichts zu führen.
     */
    public static function firstGroupId(PropertyGroupCollection $groups): ?string
    {
        return $groups->count() >= 2 ? $groups->first()?->getId() : null;
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
     * An zwei Stellen: an der Seite und in der Kaufbox der Erlebniswelt. Mit einem Layout aus der
     * Erlebniswelt liest die Vorlage die Gruppen aus den Daten der Kaufbox, nicht aus der Seite; nur die
     * Seite umzustellen änderte dort nichts. Ein Variantenwechsel lädt die Seite neu und kommt wieder hier
     * vorbei.
     */
    private static function putLengthGroupFirst(ProductPage $page, string $lengthGroupId): void
    {
        $page->setConfiguratorSettings(self::lengthGroupFirst($page->getConfiguratorSettings(), $lengthGroupId));

        // Über den Artikel, nicht über die Seite: Ohne eigenes Layout setzt der Kern die Erlebniswelt der
        // Seite nie, und der Zugriff auf die nicht belegte Eigenschaft würfe. Es ist dasselbe Objekt.
        foreach ($page->getProduct()->getCmsPage()?->getAllElements() ?? [] as $slot) {
            $data = $slot->getData();
            if ($data instanceof BuyBoxStruct && $data->getConfiguratorSettings() !== null) {
                $data->setConfiguratorSettings(self::lengthGroupFirst($data->getConfiguratorSettings(), $lengthGroupId));
            }
        }
    }

    /**
     * Dieselben Gruppen, die Längengruppe vorn; die übrigen behalten ihre Reihenfolge.
     */
    public static function lengthGroupFirst(PropertyGroupCollection $groups, string $lengthGroupId): PropertyGroupCollection
    {
        $lengthGroup = $groups->get($lengthGroupId);
        if ($lengthGroup === null) {
            return $groups;
        }

        $ordered = new PropertyGroupCollection([$lengthGroup]);
        foreach ($groups as $group) {
            if ($group->getId() !== $lengthGroupId) {
                $ordered->add($group);
            }
        }

        return $ordered;
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

        [$parentName, $parentNumber] = $this->parentLabels($page, $parentId, $context);

        $page->addExtension(
            self::GUIDED_EXTENSION_NAME,
            new GuidedSelectionStruct($lengthGroupId, self::orderedGroups($settings, $lengthGroupId), $combinations, $parentName, $parentNumber),
        );
    }

    /**
     * Name und Nummer des Elternartikels. Ruft jemand den Elternartikel auf, lädt der Kern die beste Variante, und die
     * Seite hieße „… Milchglas (3m)", obwohl noch keine Länge gewählt ist. Bis zur Wahl zeigt das Skript deshalb Name
     * und Nummer des Elternartikels; die Variantennamen bleiben im Markup für Suchmaschinen und ohne Skript.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parentLabels(ProductPage $page, string $parentId, SalesChannelContext $context): array
    {
        $product = $page->getProduct();
        if ($this->productRepository === null || $product->getId() === $parentId) {
            return [null, null];
        }

        $parent = $this->productRepository->search(new Criteria([$parentId]), $context->getContext())->getEntities()->first();
        if ($parent === null) {
            return [null, null];
        }

        $name = trim((string) ($parent->getTranslation('name') ?? $parent->getName()));

        return [$name !== '' ? $name : null, $parent->getProductNumber()];
    }
}
