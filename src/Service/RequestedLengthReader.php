<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\HttpFoundation\Request;

/**
 * Liest aus dem Request, was das Hinzufügen einer Meterposition braucht: die gewünschte Länge und ob ein anderes
 * Plugin die Kennung der Position bestimmt.
 */
final class RequestedLengthReader
{
    /**
     * Kennzeichen, die RcCartSplitter (bei TMMS-Eingaben) und RcCustomFields in den Request
     * schreiben. Ist eines gesetzt, bestimmt dieses Plugin die Kennung der Position, und statt
     * automatisch zu teilen, gibt es nur den Hinweis.
     */
    private const FOREIGN_ID_CONTROLLER_KEYS = [
        'rcTmmsActive',
        'rcCustomFieldsActive',
    ];

    /**
     * Das Feld, unter dem Kaufformular und Store-API-Clients die gewünschte Länge senden.
     */
    private const REQUEST_KEY_LENGTH = 'mmLength';

    /**
     * Längenschlüssel je Position im Request (`lineItems[<lineItemId>][payload][meterLengthMm]`),
     * damit ein Request mehrere Positionen mit verschiedenen Längen anlegen kann. Er ist derselbe
     * wie der Payload-Schlüssel {@see DynamicPriceConstants::PAYLOAD_LENGTH_MM}: Der Wert läuft so
     * ohne Umbenennung durch, und „meterLengthMm" steht nur an einer Stelle.
     */
    private const REQUEST_KEY_LENGTH_PAYLOAD = DynamicPriceConstants::PAYLOAD_LENGTH_MM;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Liest die angeforderte Länge aus drei Quellen; die erste gültige gewinnt:
     *
     *   1. Länge je Position im Request: `lineItems[<lineItemId>][payload][meterLengthMm]`. Damit
     *      legt ein Request mehrere Positionen mit verschiedenen Längen an (RcB2bSuite
     *      QuickOrder/QuoteRequest).
     *   2. Der schon gesetzte Payload der Position, `meterLengthMm`. Er trägt die Länge beim
     *      Wiederherstellen eines Warenkorbs (etwa FroshPlatformShareBasket), wenn der Request keine
     *      Länge mitbringt.
     *   3. Der flache Schlüssel `mmLength` aus dem Kaufformular und von Store-API-Clients.
     *
     * Geprüft wird in allen Quellen gleich: Das Formular sendet die Länge als Text, ein JSON-Client
     * als Zahl, beides gilt. Alles andere (Kommazahl, "5000abc", true, Array) bleibt ungültig; ein
     * blinder (int)-Cast verwandelte solche Eingaben still in eine Länge.
     *
     * Gelesen wird über all() statt get(): InputBag::get() wirft bei einem Array eine
     * BadRequestException und beantwortete einen veränderten Request mit 400 statt mit einer
     * sauberen Ablehnung im Warenkorb.
     */
    public function lengthFor(Request $request, LineItem $lineItem): ?int
    {
        $perPosition = $this->normalizeLength($this->readPerPositionLength($request, $lineItem->getId()));
        if ($perPosition !== null) {
            $flat = $this->normalizeLength($request->request->all()[self::REQUEST_KEY_LENGTH] ?? null);
            if ($flat !== null && $flat !== $perPosition) {
                // Beide Quellen gesetzt und widersprüchlich: Die Länge je Position gewinnt, und der
                // Widerspruch kommt ins Protokoll, weil er auf einen falsch gebauten Request deutet.
                $this->logger->warning('RcDynamicPrice: mehrdeutige Längenangabe, Per-Positions-Key gewinnt', [
                    'lineItemId' => $lineItem->getId(),
                    'perPosition' => $perPosition,
                    'flat' => $flat,
                ]);
            }

            return $perPosition;
        }

        $fromPayload = $this->normalizeLength($lineItem->getPayloadValue(self::REQUEST_KEY_LENGTH_PAYLOAD));
        if ($fromPayload !== null) {
            return $fromPayload;
        }

        return $this->normalizeLength($request->request->all()[self::REQUEST_KEY_LENGTH] ?? null);
    }

    /**
     * Der rohe Wert aus `lineItems[<id>][payload][meterLengthMm]`, oder `null`, wenn der Request dort
     * nichts trägt. Geprüft wird er in {@see normalizeLength()}.
     */
    private function readPerPositionLength(Request $request, string $lineItemId): mixed
    {
        $lineItems = $request->request->all('lineItems');

        $entry = $lineItems[$lineItemId] ?? null;
        if (!\is_array($entry)) {
            return null;
        }

        $payload = $entry['payload'] ?? null;
        if (!\is_array($payload)) {
            return null;
        }

        return $payload[self::REQUEST_KEY_LENGTH_PAYLOAD] ?? null;
    }

    /**
     * Eine positive Ganzzahl in Millimetern aus Text (Formular) oder Zahl (JSON), sonst `null`.
     */
    private function normalizeLength(mixed $raw): ?int
    {
        if (\is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        if (!\is_string($raw) || !\ctype_digit($raw)) {
            return null;
        }

        $mm = (int) $raw;

        return $mm > 0 ? $mm : null;
    }

    /**
     * Ob RcCartSplitter oder RcCustomFields den Request mitgestaltet hat. Beide schreiben ihr
     * Kennzeichen in den Payload der Position (`lineItems[{productId}][payload][rcTmmsActive]=1`);
     * die Prüfung auf oberster Ebene deckt ein Plugin ab, das es dort setzt.
     */
    public function hasForeignIdControllerMarker(Request $request): bool
    {
        foreach (self::FOREIGN_ID_CONTROLLER_KEYS as $key) {
            // all() statt get(): get() wirft bei einem Array-Wert (`rcTmmsActive[]=1`), siehe lengthFor().
            $value = $request->request->all()[$key] ?? '';
            if (\is_string($value) && $value !== '') {
                return true;
            }
        }

        $lineItems = $request->request->all('lineItems');
        foreach ($lineItems as $lineItemData) {
            if (!\is_array($lineItemData)) {
                continue;
            }

            $payload = $lineItemData['payload'] ?? null;
            if (!\is_array($payload)) {
                continue;
            }

            foreach (self::FOREIGN_ID_CONTROLLER_KEYS as $key) {
                $payloadValue = $payload[$key] ?? null;
                if (\is_string($payloadValue) && $payloadValue !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
