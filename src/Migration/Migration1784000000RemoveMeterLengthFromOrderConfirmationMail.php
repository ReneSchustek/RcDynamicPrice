<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Entfernt den Längen-Block wieder aus den Mail-Templates vom Typ order_confirmation_mail.
 *
 * Länge und Aufteilung stehen jetzt im Positionsnamen — die Mail gibt den Namen ohnehin aus und
 * nennt die Angabe sonst doppelt.
 *
 * Entfernt wird der **exakt bekannte Textblock**, den Migration1783900000 erzeugt hat, nicht ein
 * Suchmuster: Der Block trug nur einen öffnenden Marker, ein Schnitt "von Marker bis Blockende"
 * wäre also geraten. Der Blocktext wird dort geholt, wo er entstanden ist — eine Kopie würde beim
 * ersten Tippfehler auseinanderdriften und den Block unentfernbar machen.
 *
 * Findet sich der Block nicht wortgleich, hat der Shop die Vorlage von Hand geändert. Dann bleibt
 * sie **unangetastet**: Eine doppelte Längenangabe ist ärgerlich, eine zerschossene
 * Bestellbestätigung wäre schlimmer. Der Fall ist im CHANGELOG benannt, damit der Betreiber die
 * Vorlage prüfen kann.
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
        // Forward-only, keine destruktive Phase.
    }

    /**
     * Schneidet den Block vom Marker bis zu dem `{% endif %}`, das ihn schließt.
     *
     * Ein Vergleich mit dem heute erzeugten Blocktext wäre naheliegend, ist aber falsch: Auf
     * Im Live-Bestand steht in der Vorlage `{{ "` + echter Zeilenumbruch + `" }}`, wo der Code heute
     * die Zeichenfolge `{{ "\n" }}` schreibt — der Block stammt dort aus einer Zwischenfassung der
     * v1.16.0-Entwicklung, und weil die einfügende Migration idempotent ist, lief sie nie wieder.
     * Ein zeichengenauer Vergleich hätte den Block stehen lassen und die Länge doppelt ausgegeben.
     *
     * Deshalb wird über die Twig-Struktur geschnitten: ab der Zeile mit dem Marker, dann `{% if %}`
     * und `{% endif %}` mitzählen, bis das erste geöffnete `if` wieder geschlossen ist. Das ist
     * unabhängig davon, wie der Blockinhalt im Einzelnen geschrieben wurde.
     *
     * Liefert null, wenn nichts zu tun ist: kein Marker (nie gepatcht oder bereits entfernt) oder
     * kein schließendes `endif` (die Vorlage wurde so weit von Hand verändert, dass ein Schnitt
     * geraten wäre — dann bleibt sie unangetastet). Ein zweiter Lauf ist damit folgenlos.
     *
     * Nur für interne Verwendung und Tests public.
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

        // Ab dem Anfang der Marker-Zeile schneiden, samt ihrer Einrückung — sonst bleibt eine Zeile
        // aus Leerzeichen zurück.
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
     * Liefert die Position hinter dem `{% endif %}`, das das erste `{% if %}` des Blocks schließt —
     * inklusive des abschließenden Zeilenumbruchs und einer eventuell folgenden Leerzeile, damit die
     * Vorlage wieder exakt so aussieht wie vor dem Einfügen.
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
