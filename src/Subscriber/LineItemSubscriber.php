<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcDynamicPrice\Enum\SplitMode;
use Ruhrcoder\RcDynamicPrice\Service\CartItemSplitAssemblerInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Ruhrcoder\RcDynamicPrice\Service\MeterSplittingConfig;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Content\Product\ProductEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LineItemSubscriber implements EventSubscriberInterface
{
    /**
     * Payload-Marker, die andere Ruhrcoder-Plugins bei aktiven TMMS-/Custom-Field-Eingaben in den Request
     * schreiben. Ist einer davon gesetzt, besitzt ein hoeher priorisiertes Plugin die LineItem-ID — der
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
            $this->logger->info('RcDynamicPrice: LineItem ohne referencedId uebersprungen', [
                'lineItemId' => $event->getLineItem()->getId(),
            ]);
            return;
        }

        $context = $event->getSalesChannelContext()->getContext();
        $product = $this->meterProductHelper->loadProduct($productId, $context);

        if ($product === null || !$this->meterProductHelper->isMeterProductEntity($product)) {
            return;
        }

        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();
        $config = $this->buildConfig($product, $salesChannelId, $productId, $request);

        if ($mmLength < $config->minLength || $mmLength > $config->maxLength) {
            $this->logger->warning('RcDynamicPrice: Eingabe ausserhalb der erlaubten Grenzen verworfen', [
                'productId' => $productId,
                'mmLength' => $mmLength,
                'minLength' => $config->minLength,
                'maxLength' => $config->maxLength,
            ]);
            return;
        }

        $this->splitAssembler->assemble($event->getCart(), $event->getLineItem(), $mmLength, $config);
    }

    /**
     * Liest die angeforderte Laenge aus dem Request. Strenge ctype_digit-Pruefung statt blinder
     * (int)-Konversion — verhindert, dass Eingaben wie "5000abc" oder "500.5" stillschweigend
     * in gueltige Laengen umgewandelt werden.
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

    private function buildConfig(
        ProductEntity $product,
        string $salesChannelId,
        string $productId,
        Request $request,
    ): MeterSplittingConfig {
        return new MeterSplittingConfig(
            productId: $productId,
            minLength: $this->meterProductHelper->getMinLength($product, $salesChannelId),
            maxLength: $this->meterProductHelper->getMaxLength($product, $salesChannelId),
            maxPieceLength: $this->meterProductHelper->getMaxPieceLength($product, $salesChannelId),
            roundingMode: $this->meterProductHelper->getRoundingMode($product),
            splitMode: $this->effectiveSplitMode($product, $salesChannelId, $request),
        );
    }

    /**
     * Liefert den anwendbaren Split-Modus.
     * Wenn ein Plugin mit hoeherer ID-Prioritaet (RcCartSplitter, RcCustomFields) am Request beteiligt ist,
     * wird Auto-Split deaktiviert, um Payload-Verlust auf Sibling-LineItems zu vermeiden.
     */
    private function effectiveSplitMode(ProductEntity $product, string $salesChannelId, Request $request): ?SplitMode
    {
        $configured = $this->meterProductHelper->getSplitMode($product, $salesChannelId);

        if ($configured === null || $configured === SplitMode::Hint) {
            return $configured;
        }

        foreach (self::FOREIGN_ID_CONTROLLER_KEYS as $key) {
            $value = $request->request->get($key, '');
            if ($value !== '' && $value !== null) {
                return SplitMode::Hint;
            }
        }

        return $configured;
    }
}
