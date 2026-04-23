<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Service\CartItemSplitAssemblerInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterConfigResolverInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterSplittingConfig;
use Ruhrcoder\RcDynamicPrice\Service\ResolvedMeterConfig;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LineItemSubscriber implements EventSubscriberInterface
{
    /**
     * Payload-Marker, die andere Ruhrcoder-Plugins bei aktiven TMMS-/Custom-Field-Eingaben in den Request
     * schreiben. Ist einer davon gesetzt, besitzt ein höher priorisiertes Plugin die LineItem-ID — der
     * Auto-Split wird dann auf Hint-Verhalten reduziert, damit keine Sibling-Positionen ohne deren
     * Payload entstehen. Siehe plugin-interaction.md Sektion "Multi-LineItem-Requests".
     */
    private const FOREIGN_ID_CONTROLLER_KEYS = [
        'rcTmmsActive',
        'rcCustomFieldsActive',
    ];

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

        $mmLength = $this->readRequestedLength($request);
        if ($mmLength === null) {
            return;
        }

        $productId = $event->getLineItem()->getReferencedId();
        if ($productId === null) {
            $this->logger->info('RcDynamicPrice: LineItem ohne referencedId übersprungen', [
                'lineItemId' => $event->getLineItem()->getId(),
            ]);
            return;
        }

        $context = $event->getSalesChannelContext()->getContext();
        $product = $this->meterProductHelper->loadProduct($productId, $context);

        if ($product === null) {
            return;
        }

        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();
        $resolved = $this->configResolver->resolveForProduct($product, $salesChannelId, $context);

        // Scope-Herkunft pro Feld im Kontext — bei Support-Faellen "warum ist Preis X?"
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

        if ($mmLength < $resolved->minLength || $mmLength > $resolved->maxLength) {
            $this->logger->warning('RcDynamicPrice: Eingabe außerhalb der erlaubten Grenzen verworfen', [
                'productId' => $productId,
                'mmLength' => $mmLength,
                'minLength' => $resolved->minLength,
                'maxLength' => $resolved->maxLength,
            ]);
            return;
        }

        $config = $this->buildSplittingConfig($resolved, $productId, $request);

        $this->splitAssembler->assemble($event->getCart(), $event->getLineItem(), $mmLength, $config);
    }

    /**
     * Liest die angeforderte Länge aus dem Request. Strenge ctype_digit-Prüfung statt blinder
     * (int)-Konversion — verhindert, dass Eingaben wie "5000abc" oder "500.5" stillschweigend
     * in gültige Längen umgewandelt werden.
     */
    private function readRequestedLength(Request $request): ?int
    {
        $raw = $request->request->get('mmLength', '');

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
        );
    }

    /**
     * Liefert den anwendbaren Split-Modus. Hat ein Plugin mit höherer ID-Priorität (RcCartSplitter,
     * RcCustomFields) den Request mitgestaltet, wird Auto-Split auf Hint reduziert, damit keine
     * Sibling-LineItems ohne deren Payload entstehen.
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
     * Prueft, ob ein ID-Controller-Plugin (RcCartSplitter, RcCustomFields) im Add-to-Cart-Request
     * aktiv ist. Beide Plugins injizieren ihre Marker genested ins Buy-Form-Payload
     * (`lineItems[{productId}][payload][rcTmmsActive]=1`), nicht top-level — die Top-Level-Pruefung
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
