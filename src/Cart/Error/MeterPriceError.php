<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Blockierender Warenkorb-Fehler für eine Meter-Position, deren Preis nicht ermittelt werden kann.
 *
 * Vorher fiel eine solche Position still auf den Basispreis des Produkts zurück. Der Kunde
 * bestellte damit zu einem Preis, der die Länge nicht berücksichtigt — bei Meterware praktisch
 * immer zu billig, und niemand bemerkte es bis zur Auslieferung. Fail-Fast statt falscher Preis:
 * die Position bleibt sichtbar, die Bestellung ist aber blockiert, bis die Ursache behoben ist.
 */
final class MeterPriceError extends Error
{
    private const KEY = 'rc-dynamic-price-meter-price-unavailable';

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
     * Blockiert die Bestellung. Ein falscher Preis darf nicht in eine Bestellung laufen.
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
