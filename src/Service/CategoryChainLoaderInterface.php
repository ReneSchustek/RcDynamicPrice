<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Shopware\Core\Framework\Context;

/**
 * Liefert die Kategoriekette, über die Meterpreis und geführte Auswahl ihre Einstellungen erben.
 */
interface CategoryChainLoaderInterface
{
    /**
     * Lädt die Kette einer Primärkategorie von der Kategorie selbst bis zur Wurzel, die nächste
     * zuerst. Je Eintrag kommen `id` und `customFields`; die Reihenfolge entscheidet im Resolver,
     * welche Kategorie gewinnt.
     *
     * @return list<array{id: string, customFields: array<string, mixed>}>
     */
    public function loadChain(string $primaryCategoryId, Context $context): array;
}
