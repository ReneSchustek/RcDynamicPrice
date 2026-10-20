<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Blockierender Warenkorbfehler für eine Meterposition, deren Preis sich nicht ermitteln lässt.
 *
 * Fiele eine solche Position still auf den Grundpreis des Produkts zurück, bestellte der Kunde zu
 * einem Preis ohne Länge, bei Meterware praktisch immer zu billig, und niemand bemerkte es vor der
 * Auslieferung. Deshalb bleibt die Position sichtbar, die Bestellung aber gesperrt, bis die Ursache
 * behoben ist.
 */
final class MeterPriceError extends Error
{
    private const KEY = 'rc-dynamic-price-meter-price-unavailable';

    /*
     * Gründe, warum sich kein Meterpreis ermitteln lässt. Sie stehen im Protokoll und als Kennzahl-Tag, nicht in der
     * Meldung an den Kunden; deshalb englische Schlüssel, die sich zählen und filtern lassen.
     */
    public const REASON_INVALID_LENGTH = 'invalid_length';
    public const REASON_BELOW_MINIMUM = 'length_below_minimum';
    public const REASON_ABOVE_MAXIMUM = 'length_above_maximum';
    public const REASON_NO_BASE_PRICE = 'no_base_price';

    public function __construct(
        private readonly string $lineItemId,
        private readonly string $reason,
        private readonly string $productName = '',
    ) {
        $this->message = \sprintf(
            'Für die Position %s konnte kein Meterpreis ermittelt werden (%s).',
            $lineItemId,
            $reason,
        );

        parent::__construct($this->message);
    }

    public function getId(): string
    {
        return self::KEY . '-' . $this->lineItemId;
    }

    public function getMessageKey(): string
    {
        return self::KEY;
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    /**
     * Ein falscher Preis darf nicht in eine Bestellung laufen.
     */
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
            'lineItemId' => $this->lineItemId,
            'reason' => $this->reason,
        ];
    }
}
