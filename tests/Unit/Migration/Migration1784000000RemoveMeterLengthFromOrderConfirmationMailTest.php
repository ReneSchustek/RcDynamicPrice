<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Migration\Migration1783900000AddMeterLengthToOrderConfirmationMail;
use Ruhrcoder\RcDynamicPrice\Migration\Migration1784000000RemoveMeterLengthFromOrderConfirmationMail;

/**
 * Der Längen-Block muss sich rückstandsfrei wieder aus den Mail-Vorlagen entfernen lassen — sonst
 * nennt die Bestellbestätigung die Länge doppelt, weil sie jetzt schon im Positionsnamen steht.
 *
 * Der schärfste Test ist der Rundlauf: einfügen, entfernen, und die Vorlage muss Zeichen für
 * Zeichen wieder die ursprüngliche sein.
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
     * Der Grund, warum nicht auf Zeichengleichheit mit dem heutigen Blocktext geprüft wird.
     *
     * Im Live-Bestand steht in der Vorlage ein echter Zeilenumbruch, wo der Code heute die
     * Zeichenfolge `\n` schreibt: Der Block stammt dort aus einer Zwischenfassung der
     * v1.16.0-Entwicklung, und die einfügende Migration ist idempotent — sie lief nie wieder. Ein
     * zeichengenauer Vergleich hätte den Block stehen lassen, und die Bestellbestätigung nennte die
     * Länge zweimal. Genau so ist es beim ersten Rückbau-Versuch passiert.
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
     * Umgestaltete Blöcke werden weiterhin entfernt — geschnitten wird über die Twig-Struktur, nicht
     * über den Wortlaut. Nur wenn das schließende `{% endif %}` fehlt, wäre der Schnitt geraten;
     * dann bleibt die Vorlage unangetastet.
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
