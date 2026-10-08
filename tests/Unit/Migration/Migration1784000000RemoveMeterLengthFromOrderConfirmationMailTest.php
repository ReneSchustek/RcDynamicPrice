<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Migration\Migration1783900000AddMeterLengthToOrderConfirmationMail;
use Ruhrcoder\RcDynamicPrice\Migration\Migration1784000000RemoveMeterLengthFromOrderConfirmationMail;

/**
 * Der Längen-Block muss sich rückstandsfrei aus den Mail-Vorlagen entfernen lassen; sonst nennt
 * die Bestellbestätigung die Länge doppelt, weil sie auch im Positionsnamen steht.
 *
 * Am schärfsten prüft der Rundlauf: einfügen, entfernen, und die Vorlage ist Zeichen für Zeichen
 * wieder die ursprüngliche.
 */
final class Migration1784000000RemoveMeterLengthFromOrderConfirmationMailTest extends TestCase
{
    private Migration1783900000AddMeterLengthToOrderConfirmationMail $inserting;
    private Migration1784000000RemoveMeterLengthFromOrderConfirmationMail $removing;

    protected function setUp(): void
    {
        $this->inserting = new Migration1783900000AddMeterLengthToOrderConfirmationMail();
        $this->removing = new Migration1784000000RemoveMeterLengthFromOrderConfirmationMail();
    }

    public function testHtmlRoundtripRestoresTheOriginalTemplate(): void
    {
        $original = '<div class="item">{{ nestedItem.label|u.wordwrap(80) }}</div><span>Rest</span>';

        $patched = $this->inserting->patchHtml($original);
        self::assertNotNull($patched);
        self::assertNotSame($original, $patched);

        self::assertSame($original, $this->removing->removeBlock($patched, insertedWithLeadingNewline: true));
    }

    public function testPlainRoundtripRestoresTheOriginalTemplate(): void
    {
        $original = "Pos. Artikel\n{{ lineItem.label|u.wordwrap(80) }}\n{{ lineItem.quantity }}\n";

        $patched = $this->inserting->patchPlain($original);
        self::assertNotNull($patched);
        self::assertNotSame($original, $patched);

        self::assertSame($original, $this->removing->removeBlock($patched, insertedWithLeadingNewline: false));
    }

    /**
     * Im Live-Bestand steht in der Vorlage ein echter Zeilenumbruch, wo die einfügende Migration
     * die Zeichenfolge `\n` schreibt; der Block stammt dort aus einer älteren Fassung, und die
     * einfügende Migration läuft kein zweites Mal. Ein zeichengenauer Vergleich mit dem Blocktext
     * ließe den Block deshalb stehen, und die Bestellbestätigung nennte die Länge zweimal.
     */
    public function testRemovesAnOlderVariantOfTheBlockAsFoundInTheWild(): void
    {
        $original = "Pos.\n{{ lineItem.label|u.wordwrap(80) }}\nMenge\n";

        $patched = $this->inserting->patchPlain($original);
        self::assertNotNull($patched);

        // Die Fassung, wie sie tatsächlich in der Datenbank steht: echter Umbruch statt der
        // Zeichenfolge Backslash-n.
        $asStored = str_replace('\n', "\n", $patched);
        self::assertNotSame($patched, $asStored);
        self::assertStringContainsString(Migration1783900000AddMeterLengthToOrderConfirmationMail::MARKER, $asStored);

        $restored = $this->removing->removeBlock($asStored, insertedWithLeadingNewline: false);

        self::assertSame($original, $restored);
    }

    public function testSecondRunChangesNothing(): void
    {
        $original = '<div class="item">{{ nestedItem.label|u.wordwrap(80) }}</div>';

        $patched = $this->inserting->patchHtml($original);
        self::assertNotNull($patched);

        $restored = $this->removing->removeBlock($patched, insertedWithLeadingNewline: true);
        self::assertSame($original, $restored);

        self::assertNull(
            $this->removing->removeBlock($restored, insertedWithLeadingNewline: true),
            'Ein zweiter Lauf darf die Vorlage nicht erneut anfassen.',
        );
    }

    /**
     * Geschnitten wird über die Twig-Struktur, nicht über den Wortlaut; deshalb verschwinden auch
     * umgestaltete Blöcke. Fehlt das schließende `{% endif %}`, wäre der Schnitt geraten, und die
     * Vorlage bleibt unangetastet.
     */
    public function testTemplateWithoutClosingEndifIsLeftUntouched(): void
    {
        $broken = "Pos.\n" . Migration1783900000AddMeterLengthToOrderConfirmationMail::MARKER
            . "\n{% if mpLength %}\n  irgendwas\n";

        self::assertNull($this->removing->removeBlock($broken, insertedWithLeadingNewline: false));
    }

    public function testTemplateWithoutTheBlockIsLeftUntouched(): void
    {
        self::assertNull(
            $this->removing->removeBlock('<div>{{ nestedItem.label }}</div>', insertedWithLeadingNewline: true),
        );
        self::assertNull($this->removing->removeBlock('', insertedWithLeadingNewline: true));
        self::assertNull($this->removing->removeBlock(null, insertedWithLeadingNewline: true));
    }
}
