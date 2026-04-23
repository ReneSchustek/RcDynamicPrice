<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;

/**
 * Aufgelöster Meterpreis-Konfig-Stand für ein konkretes Produkt.
 * Unveränderlich. Enthält neben den Werten auch die Herkunft pro Feld
 * (ConfigScope), damit Logs nachvollziehbar sind, welche Ebene gewonnen hat.
 */
final readonly class ResolvedMeterConfig
{
    /**
     * @param list<string> $cacheTags Cache-Tag-Identifier für die HTTP-Invalidierung
     */
    public function __construct(
        public bool $active,
        public ConfigScope $activeScope,
        public int $minLength,
        public ConfigScope $minLengthScope,
        public int $maxLength,
        public ConfigScope $maxLengthScope,
        public string $roundingMode,
        public ConfigScope $roundingModeScope,
        public ?SplitMode $splitMode,
        public ConfigScope $splitModeScope,
        public int $maxPieceLength,
        public ConfigScope $maxPieceLengthScope,
        public string $splitHintTemplate,
        public ConfigScope $splitHintTemplateScope,
        public array $cacheTags = [],
    ) {
    }

    /**
     * Factory für "Meterpreis nicht aktiv". Numerische Defaults sind für Clients
     * ohne Bedeutung (Widget wird nicht gerendert), halten aber Invarianten ein.
     *
     * @param list<string> $cacheTags
     */
    public static function disabled(ConfigScope $activeScope, array $cacheTags = []): self
    {
        return new self(
            active: false,
            activeScope: $activeScope,
            minLength: 1,
            minLengthScope: ConfigScope::Default,
            maxLength: 10000,
            maxLengthScope: ConfigScope::Default,
            roundingMode: 'none',
            roundingModeScope: ConfigScope::Default,
            splitMode: null,
            splitModeScope: ConfigScope::Default,
            maxPieceLength: 0,
            maxPieceLengthScope: ConfigScope::Default,
            splitHintTemplate: '',
            splitHintTemplateScope: ConfigScope::Default,
            cacheTags: $cacheTags,
        );
    }
}
