<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;

/**
 * Die aufgelösten Meterpreis-Einstellungen eines Produkts, unveränderlich. Neben jedem Wert steht
 * seine Herkunft (ConfigScope), damit das Protokoll zeigt, welche Ebene gewonnen hat.
 */
final readonly class ResolvedMeterConfig
{
    /**
     * @param string       $equalSplitBilling    Abrechnung im equal-Modus (`cut_length`/`exact`), nur kanalweit einstellbar
     * @param bool         $equalSplitEnforceMin equal-Modus: kurze Teilstücke mit der Mindestlänge abrechnen
     * @param list<string> $cacheTags            Cache-Tag-Identifier für die HTTP-Invalidierung
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
        public string $equalSplitBilling = DynamicPriceConstants::EQUAL_BILLING_CUT_LENGTH,
        public bool $equalSplitEnforceMin = true,
        public array $cacheTags = [],
    ) {
    }

    /**
     * Der Stand „Meterpreis aus". Die Zahlen bedeuten dann nichts, weil kein Widget erscheint; sie
     * sind die Vorgaben des Resolvers (1 mm bis 10 m) und halten so die Bedingung Mindest- nicht
     * über Höchstlänge ein.
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
