<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Cart;

use Ruhrcoder\RcDynamicPrice\Cart\Error\LengthStageError;
use Ruhrcoder\RcDynamicPrice\Service\LengthStage\LengthInput;
use Ruhrcoder\RcDynamicPrice\Service\LengthStage\LengthStageReader;
use Ruhrcoder\RcDynamicPrice\Service\LengthStage\LengthStages;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Prüft bei Artikeln mit Längenstufen als Varianten, ob die eingegebene Länge zur gekauften Stufe passt (BRIEF65).
 *
 * Die Länge trägt der Kunde in ein Feld von TmmsProductCustomerInputs ein. TMMS hält den Wert nicht an der Position,
 * sondern in der Sitzung unter `tmms_customer_input_{Feld}_{Artikelnummer}`; geändert wird er auf der Produktseite und
 * im Warenkorb unter „Eingaben prüfen". Der Kern ruft diesen Validator bei jeder Berechnung des Warenkorbs auf, also
 * nach dem Hinzufügen, nach jeder Änderung und unmittelbar vor der Bestellung; ein Fehler mit `blockOrder()` hält die
 * Bestellung an, auch über die Store-API.
 *
 * Gesperrt wird, wenn eine Länge länger ist als die gekaufte Stufe, wenn eine Angabe nicht lesbar ist und wenn ein
 * Pflichtfeld leer ist. Über die Store-API gibt es keinen Wert in der Sitzung; ein Pflichtfeld ist dort leer. Eine
 * kürzere Länge als die Stufe bleibt erlaubt: Sie wird aus der längeren geschnitten, und der Kunde bezahlt die Stufe.
 */
final class LengthStageValidator implements CartValidatorInterface
{
    public function __construct(
        private readonly LengthStageReader $reader,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        foreach ($cart->getLineItems()->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            $error = $this->check($lineItem, $context);
            if ($error !== null) {
                $errors->add($error);
            }
        }
    }

    private function check(LineItem $lineItem, SalesChannelContext $context): ?LengthStageError
    {
        $productNumber = $lineItem->getPayloadValue('productNumber');
        $productId = $lineItem->getReferencedId();
        if (!\is_string($productNumber) || $productId === null) {
            return null;
        }

        $stages = $this->reader->read($productId, $context);
        if ($stages === null) {
            return null;
        }

        $name = (string) $lineItem->getLabel();
        $stage = $stages['stage'];
        $value = trim($this->inputValue($stages['field'], $productNumber));
        if ($value === '') {
            return $stages['required'] ? new LengthStageError($lineItem->getId(), LengthStageError::REASON_MISSING, $name, $stage->label) : null;
        }

        $lengths = LengthInput::parse($value);
        if ($lengths === null) {
            return new LengthStageError($lineItem->getId(), LengthStageError::REASON_UNREADABLE, $name, $stage->label);
        }

        foreach ($lengths as $length) {
            if (!$stage->fitsMillimetres($length)) {
                $suggestion = LengthStages::find($stages['stages'], $length);

                return new LengthStageError($lineItem->getId(), LengthStageError::REASON_TOO_LONG, $name, $stage->label, $length, $suggestion?->label);
            }
        }

        return null;
    }

    /**
     * Der Wert aus der Sitzung, wie TMMS ihn ablegt; ohne Sitzung (Konsole, Store-API ohne Speicher) leer.
     */
    private function inputValue(int $fieldNumber, string $productNumber): string
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null || !$request->hasSession()) {
            return '';
        }

        $entry = $request->getSession()->get(\sprintf('tmms_customer_input_%d_%s', $fieldNumber, $productNumber));
        $value = \is_array($entry) ? ($entry['tmms_customer_input_value'] ?? '') : '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
