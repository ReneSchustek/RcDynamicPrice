<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Subscriber;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\LengthSettingsResolver;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Kennzeichnet Positionen von Artikeln mit Längenschalter, damit der Warenkorb die eingegebene Länge
 * statt der Anleitung zeigt. Bei „Größen nur über die Länge" legt er zusätzlich den Namen ohne Größe und
 * die Namen der Längengruppen ab; dort soll im Warenkorb nur die eingegebene Länge stehen.
 *
 * Der Schalter kann an einer Kategorie stehen; die Position kennt nur ihr Produkt. Entschieden wird
 * deshalb einmal beim Hinzufügen, mit derselben Auflösung wie auf der Produktseite.
 */
final class LengthSwitchLineItemSubscriber implements EventSubscriberInterface
{
    /**
     * @param EntityRepository<PropertyGroupCollection> $propertyGroupRepository
     */
    public function __construct(
        private readonly MeterProductHelperInterface $productHelper,
        private readonly LengthSettingsResolver $resolver,
        private readonly EntityRepository $propertyGroupRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeLineItemAddedEvent::class => 'onBeforeLineItemAdded',
        ];
    }

    public function onBeforeLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        $lineItem = $event->getLineItem();

        // Eine zusammengelegte Position trägt das Kennzeichen schon vom ersten Hinzufügen.
        if ($event->isMerged() || $lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE || $lineItem->getReferencedId() === null) {
            return;
        }

        $context = $event->getSalesChannelContext();
        $product = $this->productHelper->loadProduct($lineItem->getReferencedId(), $context->getContext());
        if ($product === null) {
            return;
        }

        $settings = $this->resolver->forProduct($product, $context->getSalesChannelId(), $context->getContext());

        // Die Nummer des Längenfeldes, nicht nur „an": Ein Artikel trägt weitere Eingaben wie Endkappen,
        // und nur das Längenfeld darf im Warenkorb als Länge erscheinen.
        if ($settings->lengthSwitch && $settings->lengthFieldNumber !== null) {
            $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_SWITCH, $settings->lengthFieldNumber);
        }

        if ($settings->lengthOnly) {
            $lineItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_ONLY, [
                'label' => $this->labelWithoutSize($product, $context->getContext()),
                'groups' => $this->groupNames($settings->lengthGroupIds, $context->getContext()),
            ]);
        }
    }

    /**
     * Der Name der Variante trägt die Größe („…, 3,00 m"); der Name des Elternartikels nicht. Ohne
     * Elternartikel bleibt der Name, wie er ist, und die Vorlage zeigt den Namen der Position.
     */
    private function labelWithoutSize(ProductEntity $product, Context $context): ?string
    {
        $parentId = $product->getParentId();
        $parent = $parentId !== null ? $this->productHelper->loadProduct($parentId, $context) : null;
        $name = $parent?->getTranslation('name') ?? $parent?->getName();

        return \is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Die Position kennt ihre Eigenschaften nur mit Namen („Maße: 3,0 m"); ausgeblendet wird deshalb über
     * den Namen der Gruppe, in der Sprache des Verkaufskanals.
     *
     * @param list<string> $groupIds
     *
     * @return list<string>
     */
    private function groupNames(array $groupIds, Context $context): array
    {
        if ($groupIds === []) {
            return [];
        }

        $groups = $this->propertyGroupRepository->search(new Criteria($groupIds), $context)->getEntities();

        $names = [];
        foreach ($groups as $group) {
            $name = $group->getTranslation('name') ?? $group->getName();
            if (\is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
