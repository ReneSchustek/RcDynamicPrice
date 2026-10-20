<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Content\Property\PropertyGroupCollection;

/**
 * Reihenfolge der Variantengruppen für Längenwahl und geführte Auswahl. Reine Rechnung, kein Dienst.
 *
 * „Oberste Gruppe" ist die erste in der Reihenfolge, in der die Seite die Gruppen zeigt: im Kern nach ihrer Position im
 * Verwaltungsbereich, mit Design 2027 nach der Ordnung des Themes (`VariantGroupOrderSubscriber`).
 */
final class VariantGroupOrder
{
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
     * Dieselben Gruppen, die genannte vorn; die übrigen behalten ihre Reihenfolge.
     */
    public static function groupFirst(PropertyGroupCollection $groups, string $groupId): PropertyGroupCollection
    {
        $first = $groups->get($groupId);
        if ($first === null) {
            return $groups;
        }

        $ordered = new PropertyGroupCollection([$first]);
        foreach ($groups as $group) {
            if ($group->getId() !== $groupId) {
                $ordered->add($group);
            }
        }

        return $ordered;
    }

    /**
     * Die Gruppen der Seite als Schritte der geführten Auswahl: die Gruppe des ersten Schritts zuerst, die übrigen in
     * der Reihenfolge, in der die Seite sie zeigt.
     *
     * @return list<array{id: string, optionIds: list<string>}>
     */
    public static function steps(PropertyGroupCollection $groups, string $entryGroupId): array
    {
        $steps = [];
        foreach ($groups as $group) {
            $step = ['id' => $group->getId(), 'optionIds' => array_values($group->getOptions()?->getIds() ?? [])];

            if ($group->getId() === $entryGroupId) {
                array_unshift($steps, $step);
            } else {
                $steps[] = $step;
            }
        }

        return $steps;
    }
}
