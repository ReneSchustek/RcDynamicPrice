<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

/**
 * Das Längenfeld des Meterpreises steht über den Eingabefeldern von TMMS, direkt hinter den Varianten.
 * Dort liegt es außerhalb des Kaufformulars; damit die Länge trotzdem mitgeschickt wird, trägt das
 * versteckte Feld die Kennung des Formulars. Ohne TMMS bleibt das Feld im Kaufformular. Gerendert wird
 * nichts, die Vorlagen hängen an TMMS und an der Produktseite; geprüft wird der Quelltext.
 */
final class LengthFieldPlacementTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../src/Resources/views/storefront/component/buy-widget/';

    public function testAboveTheInputsTheFieldPointsToTheBuyForm(): void
    {
        $widget = $this->source('buy-widget.html.twig');

        self::assertStringContainsString('{% block buy_widget_configurator_include_customerinput %}', $widget);
        self::assertStringContainsString("rcFormId: 'productDetailPageBuyProductForm'", $widget);
        self::assertLessThan(strpos($widget, '{{ parent() }}'), strpos($widget, 'rc-dynamic-price-field.html.twig'), 'Das Feld steht vor den Eingabefeldern, nicht danach.');

        $field = $this->source('rc-dynamic-price-field.html.twig');
        self::assertStringContainsString('name="mmLength"', $field);
        self::assertStringContainsString('{% if rcFormId %}form="{{ rcFormId }}"{% endif %}', $field);
        self::assertStringContainsString('{% if rcFormId %}data-form="{{ rcFormId }}"{% endif %}', $field);
    }

    public function testTheFieldAppearsExactlyOncePerCondition(): void
    {
        // Beide Stellen fragen dieselbe Angabe von TMMS ab, nur umgekehrt. Sonst stünde das Feld doppelt
        // oder gar nicht da.
        self::assertStringContainsString('page.product.extensions.tmmsCustomerInputCountValue is defined', $this->source('buy-widget.html.twig'));
        self::assertStringContainsString('page.product.extensions.tmmsCustomerInputCountValue is not defined', $this->source('buy-widget-form.html.twig'));
    }

    public function testTheBuyFormKeepsTheMarkerForTheColorPicker(): void
    {
        self::assertStringContainsString('data-dynamic-price="form"', $this->source('buy-widget-form.html.twig'));
    }

    /**
     * Was: Die Markierung für die Längeneingabe unter der Längengruppe.
     * Warum: Mit Meterpreis steht dessen Feld schon oben, bei „Größen nur über die Länge“ gibt es keine
     *        Knöpfe; dort darf die Kaufbox nicht umgeordnet werden.
     * Erwartet: Markierung nur bei Längenschalter ohne beides, mit der Nummer des Längenfelds; die Regeln im
     *           Stilbogen hängen an genau dieser Markierung.
     */
    public function testTheLengthInputIsOrderedOnlyWhereItBelongs(): void
    {
        $widget = $this->source('buy-widget.html.twig');

        self::assertStringContainsString('rcLength.lengthSwitch and rcLength.lengthGroupId and not rcLength.lengthOnly and page.extensions.rcDynamicPriceConfig is not defined', $widget);
        self::assertStringContainsString('rc-length-order--field-{{ rcLength.lengthFieldNumber }}', $widget);

        $scss = file_get_contents(__DIR__ . '/../../../src/Resources/app/storefront/src/scss/base.scss');
        self::assertIsString($scss);
        self::assertStringContainsString('div:has(> .rc-length-order)', $scss);
        self::assertStringContainsString('div:has(> .rc-length-order--field-#{$field}) > form[id^="productCustomerInputForm-"][id$="-#{$field}"]', $scss);
    }

    private function source(string $file): string
    {
        $source = file_get_contents(self::DIR . $file);
        self::assertIsString($source);

        return $source;
    }
}
