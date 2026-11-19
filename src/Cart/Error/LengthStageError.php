<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Sperrt die Bestellung, wenn die eingegebene Länge nicht zur gekauften Längenstufe passt (BRIEF65).
 *
 * Bei Artikeln mit Längenstufen als Varianten kaufte ein Kunde die kleinste Stufe (0,2 m) und trug im Warenkorb 800 mm
 * ein; bestellt war dann 800 mm zum Preis von 0,2 m. Die Position bleibt sichtbar, mit der Stufe, die passt, damit der
 * Kunde weiß, was er wählen muss; bestellt werden kann erst nach der Korrektur.
 */
final class LengthStageError extends Error
{
    public const REASON_TOO_LONG = 'too-long';
    public const REASON_MISSING = 'missing';
    public const REASON_UNREADABLE = 'unreadable';

    private const KEY = 'rc-dynamic-price-length-stage-';

    public function __construct(
        private readonly string $lineItemId,
        private readonly string $reason,
        private readonly string $productName,
        private readonly string $stage,
        private readonly ?int $lengthInMillimetres = null,
        private readonly ?string $suggestedStage = null,
    ) {
        $this->message = \sprintf('Länge passt nicht zur Stufe der Position %s (%s).', $lineItemId, $reason);

        parent::__construct($this->message);
    }

    public function getId(): string
    {
        return self::KEY . $this->reason . '-' . $this->lineItemId;
    }

    public function getMessageKey(): string
    {
        return self::KEY . ($this->reason === self::REASON_TOO_LONG && $this->suggestedStage === null ? 'too-long-for-all' : $this->reason);
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    public function getName(): string
    {
        return $this->productName;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return [
            'name' => $this->productName,
            'stage' => $this->stage,
            'length' => $this->lengthInMillimetres === null ? '' : number_format($this->lengthInMillimetres, 0, ',', '.') . ' mm',
            'suggestion' => $this->suggestedStage ?? '',
            'lineItemId' => $this->lineItemId,
            'reason' => $this->reason,
        ];
    }
}
