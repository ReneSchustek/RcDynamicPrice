<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Storefront\Struct;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Das Längen-Verhalten der Produktseite für die Vorlage und den Längenschalter.
 *
 * `lengthGroupId` ist die eine Längengruppe, die dieser Artikel tatsächlich hat, oder `null`: Dann
 * liest der Längenschalter alle Größen der Seite, wie bei Artikeln mit nur einer Gruppe.
 */
final class LengthSettingsStruct extends Struct
{
    public function __construct(
        private readonly bool $lengthSwitch,
        private readonly bool $lengthOnly,
        private readonly ?string $lengthGroupId,
        private readonly ?int $lengthFieldNumber = null,
    ) {
    }

    /**
     * Die Nummer des TMMS-Feldes, in das der Kunde seine Länge schreibt.
     */
    public function getLengthFieldNumber(): ?int
    {
        return $this->lengthFieldNumber;
    }

    public function isLengthSwitch(): bool
    {
        return $this->lengthSwitch;
    }

    public function isLengthOnly(): bool
    {
        return $this->lengthOnly;
    }

    public function getLengthGroupId(): ?string
    {
        return $this->lengthGroupId;
    }
}
