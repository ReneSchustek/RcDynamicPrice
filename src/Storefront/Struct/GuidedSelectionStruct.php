<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Storefront\Struct;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Was das Skript der geführten Auswahl wissen muss: die Gruppen in der Reihenfolge der Schritte, die
 * kaufbaren Kombinationen und Name und Nummer des Elternartikels.
 *
 * `lengthGroupId` ist die Gruppe des ersten Schritts. Meist ist das die Längengruppe; bei Artikeln ohne
 * Längeneinstellung ist es die oberste Gruppe der Seite.
 *
 * Die Kombinationen kommen vom Server, nicht aus den Markierungen im Markup. Der Kern kennzeichnet
 * eine Option nur als „kombinierbar mit der gerade gezeigten Variante"; welche Pfosten es zu einer
 * anderen Länge gibt, steht dort nicht.
 */
final class GuidedSelectionStruct extends Struct
{
    /**
     * @param list<array{id: string, optionIds: list<string>}> $groups       die Längengruppe zuerst, dann
     *                                                                       die übrigen in ihrer Reihenfolge
     * @param list<list<string>>                               $combinations je kaufbarer Variante ihre Optionen
     */
    public function __construct(
        private readonly string $lengthGroupId,
        private readonly array $groups,
        private readonly array $combinations,
        private readonly ?string $parentName = null,
        private readonly ?string $parentNumber = null,
    ) {
    }

    /**
     * Name des Elternartikels; steht da, solange in der geführten Auswahl nichts gewählt ist. `null`, wenn die Seite
     * den Elternartikel selbst zeigt.
     */
    public function getParentName(): ?string
    {
        return $this->parentName;
    }

    public function getParentNumber(): ?string
    {
        return $this->parentNumber;
    }

    public function getLengthGroupId(): string
    {
        return $this->lengthGroupId;
    }

    /**
     * @return list<array{id: string, optionIds: list<string>}>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @return list<list<string>>
     */
    public function getCombinations(): array
    {
        return $this->combinations;
    }
}
