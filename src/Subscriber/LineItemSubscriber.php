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

final class LineItemSubscriber implements EventSubscriberInterface
{
    /**
     * Payload-Marker, die andere Ruhrcoder-Plugins bei aktiven TMMS-/Custom-Field-Eingaben in den Request
     * schreiben. Ist einer davon gesetzt, besitzt ein höher priorisiertes Plugin die LineItem-ID — der
     * Auto-Split wird dann auf Hint-Verhalten reduziert, damit keine Sibling-Positionen ohne deren
     * Payload entstehen. Betrifft Requests mit mehreren Positionen auf einmal.
     */
    private const FOREIGN_ID_CONTROLLER_KEYS = [
        'rcTmmsActive',
        'rcCustomFieldsActive',
    ];

    /**
     * Request-Feld, unter dem Buy-Widget und Store-API-Clients die gewünschte Länge senden.
     */
    private const REQUEST_KEY_LENGTH = 'mmLength';

    /**
     * Per-Positions-Längenschlüssel im Request-Payload
     * (`lineItems[<lineItemId>][payload][meterLengthMm]`). Bewusst wertgleich mit
     * dem Cart-Payload-Key {@see DynamicPriceConstants::PAYLOAD_LENGTH_MM}: So
     * fließt der Wert ohne Umbenennung durch den Standardpfad, und es gibt genau
     * ein Literal für „meterLengthMm" (Single Source of Truth). Damit kann ein
     * einzelner Request mehrere Positionen mit unterschiedlichen Längen anlegen.
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
        $productId = $lineItem->getReferencedId();
        if ($productId === null) {
            $this->logger->info('RcDynamicPrice: LineItem ohne referencedId übersprungen', [
                'lineItemId' => $lineItem->getId(),
            ]);
            return;
        }

        // Produkt und Konfiguration werden vor der Längenprüfung geladen: Erst danach ist bekannt,
        // ob es überhaupt ein Meter-Artikel ist. Fehlt bei einem Meter-Artikel die Länge, darf er
        // nicht still zum Stückpreis durchgehen — das entscheidet sich hier, nicht am Request.
        $context = $event->getSalesChannelContext()->getContext();
        $product = $this->meterProductHelper->loadProduct($productId, $context);

        if ($product === null) {
            return;
        }

        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();
        $resolved = $this->configResolver->resolveForProduct($product, $salesChannelId, $context);

        // Scope-Herkunft pro Feld im Kontext — bei Support-Fällen "warum ist Preis X?"
        // sieht Ops sofort, ob Produkt, Kategorie, Plugin-Global oder Default gewonnen hat.
        $this->logger->info('RcDynamicPrice: Meterpreis-Konfiguration aufgeloest', [
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
            // Fail-safe: eine Fehlkonfiguration (z.B. maxLength jenseits der Splitter-Obergrenze) darf
            // den Add-to-Cart nicht mit einem 500 abreissen. Auf „kein Split" degradieren und loggen.
            $this->logger->error('RcDynamicPrice: Split-Assembler fehlgeschlagen, Position ohne Split hinzugefuegt', [
                'productId' => $productId,
                'mmLength' => $mmLength,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Erzwingt Menge 1 auf dem eingehenden LineItem einer aktiven Meter-Position.
     *
     * Das Buy-Widget sendet immer `quantity=1`; über die Store-API oder einen manipulierten Request
     * konnte bisher eine höhere Menge durchrutschen. Der Split erzeugte die Geschwister-Positionen
     * dann fest mit Menge 1, während das Original die höhere Menge behielt — der Warenkorb-Preis
     * passte damit nicht mehr zur bestellten Ware.
     *
     * Bewusst nur das eingehende LineItem, nicht der Warenkorb-Stand: Legt der Kunde dieselbe Länge
     * ein zweites Mal in den Warenkorb, darf Shopware regulär auf Menge 2 mergen. Original und
     * Geschwister mergen dabei gleichermaßen, der Preis bleibt korrekt.
     */
    private function enforceSingleQuantity(LineItem $lineItem, string $productId): void
    {
        if ($lineItem->getQuantity() === 1) {
            return;
        }

        $this->logger->warning('RcDynamicPrice: Menge einer Meter-Position auf 1 zurückgesetzt', [
            'productId' => $productId,
            'lineItemId' => $lineItem->getId(),
            'angeforderteMenge' => $lineItem->getQuantity(),
        ]);

        // setQuantity() wirft bei nicht-stackable Positionen. Der reguläre Produkt-Pfad liefert
        // stackable=true, ein direkt aufgebautes LineItem (Store-API, Fremd-Plugin) muss das nicht.
        // Die Stackable-Semantik wird dabei nicht verändert, nur kurzzeitig umgangen.
        $wasStackable = $lineItem->isStackable();
        $lineItem->setStackable(true);
        $lineItem->setQuantity(1);
        $lineItem->setStackable($wasStackable);
    }

    /**
     * Markiert eine aktive Meter-Position, deren Preis nicht berechnet werden kann.
     *
     * Ohne Markierung überspringt der DynamicPriceProcessor die Position — er steigt nur bei
     * gesetztem Aktiv-Flag ein — und der Kunde kauft einen Zuschnitt-Artikel zum Stückpreis.
     * Mit ihr greift die vorhandene Ablehnung: Der Processor findet keine oder keine zulässige
     * Länge, meldet einen MeterPriceError und blockiert damit die Bestellung.
     *
     * Die unzulässige Länge wird bewusst mitgeschrieben. Der Processor prüft sie gegen die
     * ebenfalls hinterlegten Grenzen und kann dem Kunden dadurch sagen, ob sie zu kurz oder zu
     * lang war, statt nur "ungültig".
     */
    private function markUnpriceable(Cart $cart, LineItem $incoming, ?int $mmLength, ResolvedMeterConfig $resolved): void
    {
        // Beim Merging liefert Shopware eine abweichende Instanz über Cart::get() — wie im
        // Assembler muss der Cart-Stand beschrieben werden, nicht das eingehende Objekt.
        $cartItem = $cart->get($incoming->getId()) ?? $incoming;

        $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_METER_ACTIVE, true);
        $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MIN_LENGTH, $resolved->minLength);
        $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_MAX_LENGTH, $resolved->maxLength);

        if ($mmLength !== null) {
            $cartItem->setPayloadValue(DynamicPriceConstants::PAYLOAD_LENGTH_MM, $mmLength);
        }
    }

    /**
     * Liest die angeforderte Länge aus drei Quellen — die erste gültige gewinnt:
     *
     *   1. Per-Positions-Payload im Request:
     *      `lineItems[<lineItemId>][payload][meterLengthMm]`. Erlaubt einem einzelnen
     *      Request, mehrere Positionen mit unterschiedlichen Längen anzulegen
     *      (RcB2bSuite QuickOrder/QuoteRequest).
     *   2. Bereits gesetzter LineItem-Payload:
     *      `$lineItem->getPayloadValue(meterLengthMm)`. Deckt die Warenkorb-
     *      Wiederherstellung ab (z. B. FroshPlatformShareBasket), bei der der Request
     *      keine Länge trägt, der restaurierte Payload sie aber bereits enthält.
     *   3. Flacher Key `mmLength`: das bestehende Buy-Widget / Store-API-Clients —
     *      unverändert, damit sich das Kaufformular exakt wie bisher verhält.
     *
     * Validierung (in allen Quellen identisch): Das Storefront-Formular sendet die
     * Länge als String, ein JSON-Client als Zahl — beides gilt. Alles andere
     * (Kommazahl, "5000abc", true, Array) bleibt ungültig; ein blinder (int)-Cast
     * würde solche Eingaben stillschweigend in eine Länge verwandeln.
     *
     * Gelesen wird über all(), nicht über get(): InputBag::get() wirft bei einem Array
     * eine BadRequestException und quittiert einen manipulierten Request mit 400 statt
     * mit einer sauberen Ablehnung im Warenkorb.
     */
    private function readRequestedLength(Request $request, LineItem $lineItem): ?int
    {
        // 1. Per-Positions-Payload
        $perPosition = $this->normalizeLength($this->readPerPositionLength($request, $lineItem->getId()));
        if ($perPosition !== null) {
            $flat = $this->normalizeLength($request->request->all()[self::REQUEST_KEY_LENGTH] ?? null);
            if ($flat !== null && $flat !== $perPosition) {
                // Beide Quellen gesetzt und widersprüchlich: Per-Positions-Key gewinnt,
                // aber sichtbar loggen — deutet auf einen fehlkonstruierten Request hin.
                $this->logger->warning('RcDynamicPrice: mehrdeutige Längenangabe, Per-Positions-Key gewinnt', [
                    'lineItemId' => $lineItem->getId(),
                    'perPosition' => $perPosition,
                    'flat' => $flat,
                ]);
            }

            return $perPosition;
        }

        // 2. Bereits gesetzter LineItem-Payload (Warenkorb-Wiederherstellung)
        $fromPayload = $this->normalizeLength($lineItem->getPayloadValue(self::REQUEST_KEY_LENGTH_PAYLOAD));
        if ($fromPayload !== null) {
            return $fromPayload;
        }

        // 3. Flacher Key (bestehendes Kaufformular)
        return $this->normalizeLength($request->request->all()[self::REQUEST_KEY_LENGTH] ?? null);
    }

    /**
     * Holt den rohen Per-Positions-Längenwert aus `lineItems[<id>][payload][meterLengthMm]`.
     * Liefert `null`, wenn der Request-Baum an keiner Stelle passt — die Validierung
     * übernimmt {@see normalizeLength()}.
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
     * Validiert einen rohen Längenwert (String aus dem Formular oder int aus JSON)
     * zu einer positiven Ganzzahl in Millimetern — oder `null`, wenn ungültig.
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
     * Liefert den anwendbaren Split-Modus. Hat ein Plugin mit höherer ID-Priorität (Marker
     * `rcTmmsActive` aus TmmsProductCustomerInputs bzw. `rcCustomFieldsActive` aus RcCustomFields)
     * den Request mitgestaltet, wird Auto-Split auf Hint reduziert, damit keine Sibling-LineItems
     * ohne deren Payload entstehen.
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
     * Prüft, ob ein ID-Controller-Plugin (RcCartSplitter, RcCustomFields) im Add-to-Cart-Request
     * aktiv ist. Beide Plugins injizieren ihre Marker genested ins Buy-Form-Payload
     * (`lineItems[{productId}][payload][rcTmmsActive]=1`), nicht top-level — die Top-Level-Prüfung
     * bleibt als Legacy-Pfad erhalten, falls ein Plugin den Marker dort setzt.
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
