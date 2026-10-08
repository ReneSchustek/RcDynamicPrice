<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Service\CartItemSplitAssemblerInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterConfigResolverInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterSplittingConfig;
use Ruhrcoder\RcDynamicPrice\Service\ResolvedMeterConfig;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Macht aus einem „In den Warenkorb" eines Meterartikels einen Zuschnitt-Auftrag: Einstellungen
 * auflösen, Länge lesen und prüfen, Teilung in den Payload schreiben.
 *
 * Eine Meterposition ohne verwertbare Länge geht nicht still zum Stückpreis durch. Sie wird markiert,
 * und der DynamicPriceProcessor sperrt dann die Bestellung.
 */
final class LineItemSubscriber implements EventSubscriberInterface
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

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly MeterProductHelperInterface $meterProductHelper,
        private readonly MeterConfigResolverInterface $configResolver,
        private readonly CartItemSplitAssemblerInterface $splitAssembler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeLineItemAddedEvent::class => 'onBeforeLineItemAdded',
        ];
    }

    public function onBeforeLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }

        $lineItem = $event->getLineItem();

        // Nur Produktpositionen. Der Warenkorb trägt auch Gutschein-Platzhalter,
        // Versandkosten und Zuschläge; ein Meterpreis ergibt dort keinen Sinn, und ein
        // Gutschein trägt in `referencedId` den Code statt einer Kennung. Ohne diese
        // Prüfung liefe für jeden Gutschein eine Produktsuche ins Leere, samt einer
        // Protokollzeile „Meterpreis-Konfiguration aufgelöst", die nichts aussagt.
        if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
            return;
        }

        $productId = $lineItem->getReferencedId();
        if ($productId === null) {
            $this->logger->info('RcDynamicPrice: LineItem ohne referencedId übersprungen', [
                'lineItemId' => $lineItem->getId(),
            ]);
            return;
        }

        // Produkt und Einstellungen vor der Längenprüfung: Erst sie sagen, ob es ein Meterartikel
        // ist. Fehlt einem Meterartikel die Länge, darf er nicht still zum Stückpreis durchgehen;
        // das entscheidet sich hier und nicht am Request.
        $context = $event->getSalesChannelContext()->getContext();
        $product = $this->meterProductHelper->loadProduct($productId, $context);

        if ($product === null) {
            return;
        }

        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();
        $resolved = $this->configResolver->resolveForProduct($product, $salesChannelId, $context);

        // Die Herkunft jedes Werts steht mit im Protokoll; bei „warum kostet das so viel?" ist dann
        // sofort zu sehen, ob Produkt, Kategorie, Grundeinstellung oder Vorgabe gewonnen hat.
        $this->logger->info('RcDynamicPrice: Meterpreis-Konfiguration aufgelöst', [
            'productId' => $productId,
            'active' => $resolved->active,
            'activeScope' => $resolved->activeScope->value,
            'minLengthScope' => $resolved->minLengthScope->value,
            'maxLengthScope' => $resolved->maxLengthScope->value,
            'roundingModeScope' => $resolved->roundingModeScope->value,
            'splitModeScope' => $resolved->splitModeScope->value,
            'maxPieceLengthScope' => $resolved->maxPieceLengthScope->value,
            'splitHintTemplateScope' => $resolved->splitHintTemplateScope->value,
        ]);

        if (!$resolved->active) {
            return;
        }

        $this->enforceSingleQuantity($lineItem, $productId);

        $mmLength = $this->readRequestedLength($request, $lineItem);

        if ($mmLength === null) {
            $this->logger->warning('RcDynamicPrice: Meter-Position ohne verwertbare Längenangabe', [
                'productId' => $productId,
                'lineItemId' => $lineItem->getId(),
            ]);
            $this->markUnpriceable($event->getCart(), $lineItem, null, $resolved);
            return;
        }

        if ($mmLength < $resolved->minLength || $mmLength > $resolved->maxLength) {
            $this->logger->warning('RcDynamicPrice: Eingabe außerhalb der erlaubten Grenzen verworfen', [
                'productId' => $productId,
                'mmLength' => $mmLength,
                'minLength' => $resolved->minLength,
                'maxLength' => $resolved->maxLength,
            ]);
            $this->markUnpriceable($event->getCart(), $lineItem, $mmLength, $resolved);
            return;
        }

        $config = $this->buildSplittingConfig($resolved, $productId, $request);

        try {
            $this->splitAssembler->assemble($event->getCart(), $lineItem, $mmLength, $config);
        } catch (\Throwable $exception) {
            // Eine Fehleinstellung, etwa eine Höchstlänge über der Grenze des Splitters, darf das
            // Hinzufügen nicht mit HTTP 500 abbrechen. Die Position bleibt ohne Teilung, der Fehler
            // steht im Protokoll.
            $this->logger->error('RcDynamicPrice: Split-Assembler fehlgeschlagen, Position ohne Split hinzugefügt', [
                'productId' => $productId,
                'mmLength' => $mmLength,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Setzt die Menge der eingehenden Meterposition auf 1.
     *
     * Das Kaufformular sendet immer `quantity=1`. Eine höhere Menge kann nur über die Store-API oder
     * einen veränderten Request kommen; sie wird zurückgesetzt und protokolliert.
     *
     * Nur die eingehende Position, nicht der Stand im Warenkorb: Legt der Kunde dieselbe Länge ein
     * zweites Mal hinein, führt Shopware beide regulär zur Menge 2 zusammen.
     */
    private function enforceSingleQuantity(LineItem $lineItem, string $productId): void
    {
        if ($lineItem->getQuantity() === 1) {
            return;
        }

        $this->logger->warning('RcDynamicPrice: Menge einer Meter-Position auf 1 zurückgesetzt', [
            'productId' => $productId,
            'lineItemId' => $lineItem->getId(),
            'requestedQuantity' => $lineItem->getQuantity(),
        ]);

        // setQuantity() wirft bei nicht stapelbaren Positionen. Der übliche Produktweg liefert
        // stapelbare, ein direkt gebautes LineItem (Store-API, fremdes Plugin) nicht unbedingt.
        // Die Eigenschaft wird nur für den Aufruf umgangen und danach zurückgesetzt.
        $wasStackable = $lineItem->isStackable();
        $lineItem->setStackable(true);
        $lineItem->setQuantity(1);
        $lineItem->setStackable($wasStackable);
    }

    /**
     * Markiert eine Meterposition, deren Preis sich nicht berechnen lässt.
     *
     * Ohne Markierung überginge der DynamicPriceProcessor die Position, weil er nur bei gesetztem
     * Kennzeichen rechnet, und der Kunde kaufte einen Zuschnitt zum Stückpreis. Mit ihr findet der
     * Processor keine oder keine zulässige Länge, meldet einen MeterPriceError und sperrt so die
     * Bestellung.
     *
     * Eine unzulässige Länge wird mitgeschrieben. Der Processor prüft sie gegen die ebenfalls
     * hinterlegten Grenzen und kann so sagen, ob sie zu kurz oder zu lang war, statt nur „ungültig".
     */
    private function markUnpriceable(Cart $cart, LineItem $incoming, ?int $mmLength, ResolvedMeterConfig $resolved): void
    {
        // Beim Zusammenführen liefert Cart::get() eine andere Instanz; wie im Assembler wird die im
        // Warenkorb beschrieben, nicht das eingehende Objekt.
        $cartItem = $cart->get($incoming->getId()) ?? $incoming;

        $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
        $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, $resolved->minLength);
        $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH, $resolved->maxLength);

        if ($mmLength !== null) {
            $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, $mmLength);
        }
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
    private function readRequestedLength(Request $request, LineItem $lineItem): ?int
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

    private function buildSplittingConfig(
        ResolvedMeterConfig $resolved,
        string $productId,
        Request $request,
    ): MeterSplittingConfig {
        return new MeterSplittingConfig(
            productId: $productId,
            minLength: $resolved->minLength,
            maxLength: $resolved->maxLength,
            maxPieceLength: $resolved->maxPieceLength,
            roundingMode: $resolved->roundingMode,
            splitMode: $this->effectiveSplitMode($resolved->splitMode, $request),
            equalSplitBilling: $resolved->equalSplitBilling,
            equalSplitEnforceMin: $resolved->equalSplitEnforceMin,
        );
    }

    /**
     * Der Modus, der für diesen Request gilt. Hat RcCartSplitter (`rcTmmsActive`) oder
     * RcCustomFields (`rcCustomFieldsActive`) den Request mitgestaltet, bestimmt dieses Plugin die
     * Kennung der Position, und statt zu teilen, gibt es nur den Hinweis.
     */
    private function effectiveSplitMode(?SplitMode $configured, Request $request): ?SplitMode
    {
        if ($configured === null || $configured === SplitMode::Hint) {
            return $configured;
        }

        if ($this->hasForeignIdControllerMarker($request)) {
            return SplitMode::Hint;
        }

        return $configured;
    }

    /**
     * Ob RcCartSplitter oder RcCustomFields den Request mitgestaltet hat. Beide schreiben ihr
     * Kennzeichen in den Payload der Position (`lineItems[{productId}][payload][rcTmmsActive]=1`);
     * die Prüfung auf oberster Ebene deckt ein Plugin ab, das es dort setzt.
     */
    private function hasForeignIdControllerMarker(Request $request): bool
    {
        foreach (self::FOREIGN_ID_CONTROLLER_KEYS as $key) {
            $value = $request->request->get($key, '');
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
