<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Nimmt den Längen-Block, den `Migration1783900000AddMeterLengthToOrderConfirmationMail` eingefügt hat,
 * wieder aus den Vorlagen der Bestellbestätigung, in HTML und Klartext.
 *
 * Länge und Aufteilung stehen im Positionsnamen, und den gibt die Mail ohnehin aus; mit dem Block stünde
 * die Angabe doppelt da. Gefunden wird der Block über seinen Marker, dessen Text aus der einfügenden
 * Migration kommt statt aus einer Kopie. Fehlt der Marker oder lässt sich das Blockende nicht eindeutig
 * finden, bleibt die Vorlage unangetastet: Eine doppelte Längenangabe ist ärgerlich, eine zerschossene
 * Bestellbestätigung wäre schlimmer.
 */
final class Migration1784000000RemoveMeterLengthFromOrderConfirmationMail extends MigrationStep
{
    private const TYPE_TECHNICAL_NAME = 'order_confirmation_mail';

    public function getCreationTimestamp(): int
    {
        return 1784000000;
    }

    public function update(Connection $connection): void
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT mtt.mail_template_id, mtt.language_id, mtt.content_html, mtt.content_plain
             FROM mail_template_translation mtt
             INNER JOIN mail_template mt ON mt.id = mtt.mail_template_id
             INNER JOIN mail_template_type mtype ON mtype.id = mt.mail_template_type_id
             WHERE mtype.technical_name = :type',
            ['type' => self::TYPE_TECHNICAL_NAME],
        );

        foreach ($rows as $row) {
            $update = [];

            $html = $this->removeBlock($row['content_html'] ?? null, insertedWithLeadingNewline: true);
            if ($html !== null) {
                $update['content_html'] = $html;
            }

            $plain = $this->removeBlock($row['content_plain'] ?? null, insertedWithLeadingNewline: false);
            if ($plain !== null) {
                $update['content_plain'] = $plain;
            }

            if ($update === []) {
                continue;
            }

            $connection->update(
                'mail_template_translation',
                $update,
                [
                    'mail_template_id' => $row['mail_template_id'],
                    'language_id' => $row['language_id'],
                ],
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nichts zu tun: Entfernt wird nur, was die Migration eindeutig als ihren Block erkennt.
    }

    /**
     * Schneidet den Block vom Marker bis zu dem `{% endif %}`, das ihn schließt.
     *
     * Ein Vergleich mit dem Blocktext, den die einfügende Migration erzeugt, läge nahe, träfe aber nicht
     * jeden Shop. Im Live-Bestand steht in der Vorlage `{{ "`, ein echter Zeilenumbruch und `" }}`, wo
     * der Code die Zeichenfolge `{{ "\n" }}` schreibt; der Block stammt dort aus einer früheren Fassung,
     * und die einfügende Migration läuft kein zweites Mal. Ein zeichengenauer Vergleich ließe den Block
     * stehen und die Länge doppelt ausgeben.
     *
     * Deshalb wird über die Twig-Struktur geschnitten: ab der Zeile mit dem Marker, `{% if %}` und
     * `{% endif %}` mitzählend, bis das erste geöffnete `if` wieder geschlossen ist. Wie der Inhalt
     * dazwischen geschrieben ist, spielt dann keine Rolle.
     *
     * Liefert null, wenn nichts zu tun ist: ohne Marker (nie eingefügt oder schon entfernt) oder ohne
     * schließendes `endif` (dann wäre der Schnitt geraten, und die Vorlage bleibt). Ein zweiter Lauf ist
     * damit folgenlos.
     *
     * Öffentlich nur für die Tests.
     */
    public function removeBlock(?string $content, bool $insertedWithLeadingNewline): ?string
    {
        if ($content === null || $content === '') {
            return null;
        }

        $markerPos = strpos($content, Migration1783900000AddMeterLengthToOrderConfirmationMail::MARKER);
        if ($markerPos === false) {
            return null;
        }

        $blockEnd = $this->findBlockEnd($content, $markerPos);
        if ($blockEnd === null) {
            return null;
        }

        // Ab dem Anfang der Marker-Zeile schneiden, samt ihrer Einrückung; sonst bliebe eine Zeile aus
        // Leerzeichen zurück.
        $lineStart = strrpos(substr($content, 0, $markerPos), "\n");
        $cutFrom = $lineStart === false ? 0 : $lineStart + 1;

        // Der HTML-Block kam mit einem eigenen führenden Zeilenumbruch hinzu (patchHtml schreibt
        // "\n" . block), der Plaintext-Block nicht (patchPlain schreibt den Block direkt hinter die
        // Label-Zeile). Im HTML-Fall gehört dieser Umbruch also zum eingefügten Text und muss mit
        // weg; im Plaintext-Fall gehört er zur Label-Zeile der Vorlage und muss bleiben.
        if ($insertedWithLeadingNewline && $lineStart !== false) {
            $cutFrom = $lineStart;
        }

        return substr($content, 0, $cutFrom) . substr($content, $blockEnd);
    }

    /**
     * Liefert die Position hinter dem `{% endif %}`, das das erste `{% if %}` des Blocks schließt, samt
     * dem Rest dieser Zeile und ihrem Zeilenumbruch, damit keine leere Zeile zurückbleibt.
     */
    private function findBlockEnd(string $content, int $markerPos): ?int
    {
        $depth = 0;
        $offset = $markerPos;

        while (true) {
            $nextIf = strpos($content, '{% if ', $offset);
            $nextEndif = strpos($content, '{% endif %}', $offset);

            if ($nextEndif === false) {
                return null;
            }

            if ($nextIf !== false && $nextIf < $nextEndif) {
                ++$depth;
                $offset = $nextIf + 1;
                continue;
            }

            --$depth;
            $offset = $nextEndif + \strlen('{% endif %}');

            if ($depth <= 0) {
                break;
            }
        }

        // Rest der Zeile (nur Whitespace) plus den abschließenden Zeilenumbruch mitnehmen.
        $eol = strpos($content, "\n", $offset);

        return $eol === false ? $offset : $eol + 1;
    }
}
