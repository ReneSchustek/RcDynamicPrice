<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service\LengthStage;

use Ruhrcoder\RcDynamicPrice\Service\LengthSettingsResolverInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Ruhrcoder\RcDynamicPrice\Service\ProductCustomFields;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Welche Längenstufe eine Variante ist und welche Stufen es neben ihr gibt.
 *
 * Die Längengruppen kommen wie beim Längenschalter aus Produkt und Kategoriekette (`LengthSettingsResolver`); die
 * Stufen sind die Optionen dieser Gruppen, die die Geschwister der Variante tragen. Ohne Elternartikel, ohne
 * Längenfeld, ohne Längengruppe oder ohne lesbare Stufe gibt es nichts zu prüfen (`null`).
 *
 * Längenfeld und Pflicht-Kennzeichen kommen aus dem geladenen Artikel, nicht aus den Daten der Warenkorbposition: Der
 * Kern speichert den Warenkorb ohne Zusatzfelder, die nicht für den Warenkorb freigegeben sind
 * (`CartSerializationCleaner`); ab der zweiten Anfrage, also auch beim Bestellen, fehlen dort die TMMS-Felder. Je Artikel wird einmal je Anfrage gelesen,
 * denn der Warenkorb wird oft berechnet.
 */
class LengthStageReader implements ResetInterface
{
    /** @var array<string, array{stage: LengthStage, stages: list<LengthStage>, field: int, required: bool}|null> */
    private array $cache = [];

    /**
     * @param EntityRepository<PropertyGroupOptionCollection> $optionRepository
     */
    public function __construct(
        private readonly MeterProductHelperInterface $productHelper,
        private readonly LengthSettingsResolverInterface $resolver,
        private readonly EntityRepository $optionRepository,
    ) {
    }

    /**
     * @return array{stage: LengthStage, stages: list<LengthStage>, field: int, required: bool}|null
     */
    public function read(string $productId, SalesChannelContext $context): ?array
    {
        if (!\array_key_exists($productId, $this->cache)) {
            $this->cache[$productId] = $this->load($productId, $context);
        }

        return $this->cache[$productId];
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * @return array{stage: LengthStage, stages: list<LengthStage>, field: int, required: bool}|null
     */
    private function load(string $productId, SalesChannelContext $context): ?array
    {
        $product = $this->productHelper->loadProduct($productId, $context->getContext());
        $parentId = $product?->getParentId();
        if ($product === null || $parentId === null) {
            return null;
        }

        $settings = $this->resolver->forProduct($product, $context->getSalesChannelId(), $context->getContext());
        $groupIds = $settings->lengthGroupIds;
        $field = $settings->lengthFieldNumber;
        if ($groupIds === [] || $field === null) {
            return null;
        }
        $required = ProductCustomFields::isChecked(ProductCustomFields::of($product)["tmms_customer_input_{$field}_required"] ?? null);

        $criteria = (new Criteria())
            ->addFilter(new EqualsAnyFilter('groupId', $groupIds))
            ->addFilter(new EqualsFilter('productOptions.parentId', $parentId));
        $options = $this->optionRepository->search($criteria, $context->getContext())->getEntities();

        $labels = [];
        $ownLabel = null;
        $ownOptionIds = $product->getOptionIds() ?? [];
        foreach ($options as $option) {
            $label = (string) ($option->getTranslation('name') ?? $option->getName());
            $labels[] = $label;
            if (\in_array($option->getId(), $ownOptionIds, true)) {
                $ownLabel = $label;
            }
        }

        $stages = LengthStages::fromLabels(array_values(array_unique($labels)));
        $ownRange = $ownLabel === null ? null : LengthStages::parseLabel($ownLabel);
        foreach ($stages as $stage) {
            if ($ownRange !== null && $stage->label === $ownRange['label']) {
                return ['stage' => $stage, 'stages' => $stages, 'field' => $field, 'required' => $required];
            }
        }

        return null;
    }
}
