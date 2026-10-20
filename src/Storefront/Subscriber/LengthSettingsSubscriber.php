<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Storefront\Subscriber;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\GuidedSelectionBuilder;
use Ruhrcoder\RcDynamicPrice\Service\LengthSettingsResolverInterface;
use Ruhrcoder\RcDynamicPrice\Service\VariantGroupOrder;
use Ruhrcoder\RcDynamicPrice\Storefront\Struct\LengthSettingsStruct;
use Shopware\Core\Content\Cms\SalesChannel\Struct\BuyBoxStruct;
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
 * der Kategorie auch dort, wo die Längen mal „Maße", mal „Länge" heißen. Die geführte Auswahl baut
 * {@see GuidedSelectionBuilder}, die Reihenfolge der Gruppen {@see VariantGroupOrder}.
 */
final class LengthSettingsSubscriber implements EventSubscriberInterface
{
    public const EXTENSION_NAME = 'rcLengthSettings';

    public const GUIDED_EXTENSION_NAME = 'rcGuidedSelection';

    public function __construct(
        private readonly LengthSettingsResolverInterface $resolver,
        private readonly GuidedSelectionBuilder $guidedSelection,
        private readonly SystemConfigService $systemConfig,
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

        // Mit der geführten Auswahl für alle Variantenartikel trifft der Kunde nirgends auf durchgestrichene
        // Kombinationen, er wird Schritt für Schritt geführt. Ohne Längengruppe beginnt sie mit der obersten Gruppe
        // (siehe VariantGroupOrder).
        $guidedForAll = $this->systemConfig->getBool(DynamicPriceConstants::CONFIG_GUIDED_SELECTION_ALL_VARIANTS, $salesChannelContext->getSalesChannelId());

        if ($settings->isEmpty()) {
            $firstGroupId = VariantGroupOrder::firstGroupId($page->getConfiguratorSettings());
            if ($guidedForAll && $firstGroupId !== null) {
                $this->addGuidedSelection($page, $parentId, $firstGroupId, $salesChannelContext);
            }

            return;
        }

        $lengthGroupId = VariantGroupOrder::lengthGroupOnPage($page->getConfiguratorSettings(), $settings);

        // Die Länge steht oben: Der Kunde soll sie kennen, bevor er über Ausführung, Farbe oder Gehrung
        // entscheidet. Der Kern ordnet die Gruppen nach ihrer eigenen Position.
        if ($lengthGroupId !== null) {
            self::putGroupFirst($page, $lengthGroupId);
        }

        // Ohne Längengruppe auf der Seite gäbe es keine Knöpfe, die sich ausblenden ließen.
        $lengthOnly = $settings->lengthOnly && $settings->lengthSwitch && $lengthGroupId !== null;

        $page->addExtension(self::EXTENSION_NAME, new LengthSettingsStruct($settings->lengthSwitch, $lengthOnly, $lengthGroupId, $settings->lengthFieldNumber));

        // Die am Artikel oder an der Kategorie eingestellte Führung braucht die Längengruppe, die für alle
        // Variantenartikel beginnt notfalls mit der obersten Gruppe. Bei „Größen nur über die Länge" sind die
        // Längenknöpfe ausgeblendet, mit denen die Führung beginnen würde.
        $entryGroupId = $lengthGroupId ?? VariantGroupOrder::firstGroupId($page->getConfiguratorSettings());
        if (!$lengthOnly && $entryGroupId !== null && (($settings->guidedSelection && $lengthGroupId !== null) || $guidedForAll)) {
            $this->addGuidedSelection($page, $parentId, $entryGroupId, $salesChannelContext);
        }
    }

    private function addGuidedSelection(ProductPage $page, string $parentId, string $entryGroupId, SalesChannelContext $context): void
    {
        $guided = $this->guidedSelection->build($page, $parentId, $entryGroupId, $context);
        if ($guided !== null) {
            $page->addExtension(self::GUIDED_EXTENSION_NAME, $guided);
        }
    }

    /**
     * An zwei Stellen: an der Seite und in der Kaufbox der Erlebniswelt. Mit einem Layout aus der
     * Erlebniswelt liest die Vorlage die Gruppen aus den Daten der Kaufbox, nicht aus der Seite; nur die
     * Seite umzustellen änderte dort nichts. Ein Variantenwechsel lädt die Seite neu und kommt wieder hier
     * vorbei.
     */
    private static function putGroupFirst(ProductPage $page, string $groupId): void
    {
        $page->setConfiguratorSettings(VariantGroupOrder::groupFirst($page->getConfiguratorSettings(), $groupId));

        // Über den Artikel, nicht über die Seite: Ohne eigenes Layout setzt der Kern die Erlebniswelt der
        // Seite nie, und der Zugriff auf die nicht belegte Eigenschaft würfe. Es ist dasselbe Objekt.
        foreach ($page->getProduct()->getCmsPage()?->getAllElements() ?? [] as $slot) {
            $data = $slot->getData();
            if ($data instanceof BuyBoxStruct && $data->getConfiguratorSettings() !== null) {
                $data->setConfiguratorSettings(VariantGroupOrder::groupFirst($data->getConfiguratorSettings(), $groupId));
            }
        }
    }
}
