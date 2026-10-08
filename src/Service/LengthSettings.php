<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

/**
 * Was ein Artikel an Längen-Verhalten bekommt, aufgelöst über Produkt und Kategoriekette.
 *
 * Die Längengruppen sind die Kandidaten; welche davon gilt, entscheidet erst die Produktseite, die
 * weiß, welche Gruppen der Artikel hat.
 */
final readonly class LengthSettings
{
    /**
     * @param list<string> $lengthGroupIds
     * @param int|null     $lengthFieldNumber das TMMS-Feld mit der Länge; ohne es bleibt der Längenschalter aus
     */
    public function __construct(
        public bool $guidedSelection,
        public bool $lengthSwitch,
        public bool $lengthOnly,
        public array $lengthGroupIds,
        public ?int $lengthFieldNumber = null,
    ) {
    }

    public static function none(): self
    {
        return new self(false, false, false, []);
    }

    public function isEmpty(): bool
    {
        return !$this->guidedSelection && !$this->lengthSwitch && !$this->lengthOnly;
    }
}
