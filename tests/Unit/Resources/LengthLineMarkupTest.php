<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

/**
 * Die Längenzeile im Warenkorb nimmt dasselbe Element wie TMMS: `<div>` im aufklappbaren Bereich, sonst
 * `<li>`. Ein festes `<li>` im `<div>` von TMMS ließ den Browser Elemente zu früh schließen; in der
 * Warenkorb-Leiste standen Zwischensumme und Kassenknöpfe danach außerhalb des scrollenden Bereichs.
 * Gerendert wird hier nichts, die Vorlage hängt an TMMS; geprüft wird der Quelltext.
 */
final class LengthLineMarkupTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../src/Resources/views/storefront/component/line-item/type/line-item-customer-input-field-container.html.twig';

    public function testTheElementFollowsTheAccordionSettingOfTmms(): void
    {
        $source = $this->source();

        self::assertStringContainsString("config('TmmsProductCustomerInputs.config.customerInputIsInAnAccordionInCheckout')", $source);
        self::assertStringContainsString("rcInAccordion ? 'div' : 'li'", $source);
        self::assertStringContainsString('<{{ rcTag }} ', $source);
        self::assertStringContainsString('</{{ rcTag }}>', $source);
        self::assertDoesNotMatchRegularExpression('/<li[\s>]/', $this->withoutComments($source), 'Kein fest verdrahtetes <li> mehr.');
    }

    public function testTheLineAppearsOnlyWhereTmmsShowsInputs(): void
    {
        $source = $this->source();

        foreach (['customerInputShowOnOffcanvasCartPage', 'customerInputShowOnCartPage', 'customerInputShowOnConfirmPage'] as $setting) {
            self::assertStringContainsString("config('TmmsProductCustomerInputs.config." . $setting . "')", $source);
        }
        self::assertStringContainsString('{% if rcShownHere and ', $source);
    }

    private function source(): string
    {
        $source = file_get_contents(self::TEMPLATE);
        self::assertIsString($source);

        return $source;
    }

    private function withoutComments(string $source): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $source);
    }
}
