<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Migration\Migration1783900000AddMeterLengthToOrderConfirmationMail;

/**
 * Die Migration fügt den Längen-Block in die Vorlagen der Bestellbestätigung ein; die Nachfolgerin
 * `Migration1784000000RemoveMeterLengthFromOrderConfirmationMail` nimmt ihn wieder heraus. Geprüft
 * wird, dass sie an der richtigen Stelle einfügt, nur einmal und nie in eine angepasste Vorlage.
 */
final class Migration1783900000AddMeterLengthToOrderConfirmationMailTest extends TestCase
{
    private Migration1783900000AddMeterLengthToOrderConfirmationMail $migration;

    protected function setUp(): void
    {
        $this->migration = new Migration1783900000AddMeterLengthToOrderConfirmationMail();
    }

    public function testHtmlBlockIsInsertedAfterLabelAnchor(): void
    {
        $html = '<div class="item">{{ nestedItem.label|u.wordwrap(80) }}</div><span>Rest</span>';

        $patched = $this->migration->patchHtml($html);

        self::assertNotNull($patched);
        self::assertStringContainsString(Migration1783900000AddMeterLengthToOrderConfirmationMail::MARKER, $patched);
        self::assertStringContainsString('nestedItem.payload.rc_split_summary', $patched);

        // Der Block sitzt hinter dem schließenden </div> des Labels, nicht davor.
        $markerPos = strpos($patched, Migration1783900000AddMeterLengthToOrderConfirmationMail::MARKER);
        $anchorPos = strpos($patched, '{{ nestedItem.label|u.wordwrap(80) }}');
        self::assertIsInt($markerPos);
        self::assertIsInt($anchorPos);
        self::assertGreaterThan($anchorPos, $markerPos);
    }

    public function testPlainBlockIsInsertedAfterLabelLine(): void
    {
        $plain = "Pos. Artikel\n{{ lineItem.label|u.wordwrap(80) }}\n{{ lineItem.quantity }}\n";

        $patched = $this->migration->patchPlain($plain);

        self::assertNotNull($patched);
        self::assertStringContainsString(Migration1783900000AddMeterLengthToOrderConfirmationMail::MARKER, $patched);
        self::assertStringContainsString('lineItem.payload.meterLengthMm', $patched);
    }

    /**
     * Ein zweiter Lauf ändert die Vorlage nicht; sonst stünde der Block nach jedem Update der
     * Erweiterung ein weiteres Mal in der Mail.
     */
    public function testSecondRunDoesNotPatchAgain(): void
    {
        $html = '<div class="item">{{ nestedItem.label|u.wordwrap(80) }}</div>';
        $plain = "{{ lineItem.label|u.wordwrap(80) }}\n";

        $htmlOnce = $this->migration->patchHtml($html);
        $plainOnce = $this->migration->patchPlain($plain);

        self::assertNotNull($htmlOnce);
        self::assertNotNull($plainOnce);

        self::assertNull($this->migration->patchHtml($htmlOnce), 'HTML darf nur einmal gepatcht werden');
        self::assertNull($this->migration->patchPlain($plainOnce), 'Plaintext darf nur einmal gepatcht werden');
    }

    /**
     * Fehlt der Anker der Standardvorlage, wurde die Vorlage im Shop angepasst. Dann wird sie nicht
     * geändert, statt den Block an einer geratenen Stelle einzufügen.
     */
    public function testShopCustomisedTemplateWithoutAnchorIsLeftUntouched(): void
    {
        self::assertNull($this->migration->patchHtml('<div>Individuelle Vorlage ohne Anker</div>'));
        self::assertNull($this->migration->patchPlain("Individuelle Vorlage ohne Anker\n"));
    }

    public function testEmptyContentIsIgnored(): void
    {
        self::assertNull($this->migration->patchHtml(null));
        self::assertNull($this->migration->patchHtml(''));
        self::assertNull($this->migration->patchPlain(null));
        self::assertNull($this->migration->patchPlain(''));
    }
}
