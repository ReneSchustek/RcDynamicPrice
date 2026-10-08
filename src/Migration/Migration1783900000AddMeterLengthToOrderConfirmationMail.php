<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Ergänzt die Vorlagen der Bestellbestätigung, HTML und Klartext, je Position um Länge und Aufteilung
 * des Zuschnitts, damit Kunde und Fertigung sehen, was bestellt ist.
 *
 * Zwei Riegel schützen eine Vorlage vor dem Überschreiben. Trägt sie schon den Marker, bleibt sie, wie
 * sie ist; ein zweiter Lauf ändert nichts. Fehlt die Zeile, hinter die der Block gehört, in der Form, wie
 * Shopware sie ausliefert, hat der Shop die Vorlage angepasst, und sie bleibt ebenfalls unberührt.
 * `Migration1784000000RemoveMeterLengthFromOrderConfirmationMail` nimmt den Block wieder heraus, seit die
 * Länge im Positionsnamen steht.
 */
final class Migration1783900000AddMeterLengthToOrderConfirmationMail extends MigrationStep
{
    public const MARKER = '{# RcDynamicPrice:mail-length-block-v1 #}';

    private const TYPE_TECHNICAL_NAME = 'order_confirmation_mail';
    private const HTML_ANCHOR = '{{ nestedItem.label|u.wordwrap(80) }}';
    private const PLAIN_ANCHOR = '{{ lineItem.label|u.wordwrap(80) }}';
    private const HTML_CLOSE_DIV = '</div>';

    public function getCreationTimestamp(): int
    {
        return 1783900000;
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
            $htmlPatched = $this->patchHtml($row['content_html'] ?? null);
            $plainPatched = $this->patchPlain($row['content_plain'] ?? null);

            $update = [];
            if ($htmlPatched !== null) {
                $update['content_html'] = $htmlPatched;
            }
            if ($plainPatched !== null) {
                $update['content_plain'] = $plainPatched;
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
        // Nichts zu tun: Die Migration greift nur an Stellen, die sie eindeutig erkennt.
    }

    /**
     * Setzt den Längen-Block in die HTML-Vorlage, direkt hinter das `</div>`, das auf die Ausgabe der
     * Positionsbezeichnung folgt. Liefert null bei leerem Inhalt, vorhandenem Marker oder fehlender
     * Bezeichnungszeile.
     *
     * Öffentlich nur für die Tests.
     */
    public function patchHtml(?string $content): ?string
    {
        if ($content === null || $content === '') {
            return null;
        }
        if (str_contains($content, self::MARKER)) {
            return null;
        }

        $anchorPos = strpos($content, self::HTML_ANCHOR);
        if ($anchorPos === false) {
            return null;
        }

        $closeDivPos = strpos($content, self::HTML_CLOSE_DIV, $anchorPos);
        if ($closeDivPos === false) {
            return null;
        }

        $insertPos = $closeDivPos + \strlen(self::HTML_CLOSE_DIV);

        return substr($content, 0, $insertPos)
            . "\n" . $this->htmlLengthBlock()
            . substr($content, $insertPos);
    }

    /**
     * Setzt den Längen-Block in die Klartext-Vorlage, direkt hinter die Zeile mit der
     * Positionsbezeichnung. Liefert null in denselben Fällen wie die HTML-Fassung.
     *
     * Öffentlich nur für die Tests.
     */
    public function patchPlain(?string $content): ?string
    {
        if ($content === null || $content === '') {
            return null;
        }
        if (str_contains($content, self::MARKER)) {
            return null;
        }

        $anchorPos = strpos($content, self::PLAIN_ANCHOR);
        if ($anchorPos === false) {
            return null;
        }

        $eolPos = strpos($content, "\n", $anchorPos);
        if ($eolPos === false) {
            return null;
        }

        return substr($content, 0, $eolPos + 1)
            . $this->plainLengthBlock()
            . substr($content, $eolPos + 1);
    }

    private function htmlLengthBlock(): string
    {
        return <<<TWIG
                        {$this->markerLine()}
                        {% set mpLength = nestedItem.payload.meterLengthMm|default(0) %}
                        {% set mpBilled = nestedItem.payload.rc_billed_length_mm|default(mpLength) %}
                        {% set mpSummary = nestedItem.payload.rc_split_summary|default([]) %}
                        {% set mpPieces = nestedItem.payload.rc_billed_pieces|default([])|length %}
                        {% if mpLength %}
                            <div style="font-size:11px;color:#666;margin-top:4px;">
                                {% if mpPieces > 1 %}
                                    {% set mpParts = [] %}
                                    {% for mpGroup in mpSummary %}
                                        {% set mpParts = mpParts|merge(['rc-dynamic-price.cartSplitPiece'|trans({'%count%': mpGroup.count, '%length%': mpGroup.length|number_format(0, ',', '.')})]) %}
                                    {% endfor %}
                                    {{ 'rc-dynamic-price.cartSplitLabel'|trans({'%length%': mpLength|number_format(0, ',', '.'), '%pieces%': mpParts|join(' + ')}) }}
                                {% else %}
                                    {{ 'rc-dynamic-price.cartLengthLabel'|trans({'%length%': mpLength|number_format(0, ',', '.')}) }}
                                {% endif %}
                                {% if mpBilled != mpLength %}
                                    {{ 'rc-dynamic-price.cartBilledLabel'|trans({'%billed%': mpBilled|number_format(0, ',', '.')}) }}
                                {% endif %}
                            </div>
                        {% endif %}

TWIG;
    }

    private function plainLengthBlock(): string
    {
        // Twig verschluckt den Zeilenumbruch direkt nach einem `{% … %}`-Tag. Ohne das explizite
        // `{{ "\n" }}` klebt die Längenangabe im Plaintext am Artikelnamen.
        return <<<TWIG
{$this->markerLine()}
{% set mpLength = lineItem.payload.meterLengthMm|default(0) %}
{% set mpBilled = lineItem.payload.rc_billed_length_mm|default(mpLength) %}
{% set mpSummary = lineItem.payload.rc_split_summary|default([]) %}
{% set mpPieces = lineItem.payload.rc_billed_pieces|default([])|length %}
{% if mpLength %}
{{ "\\n" }}{% if mpPieces > 1 %}{% set mpParts = [] %}{% for mpGroup in mpSummary %}{% set mpParts = mpParts|merge(['rc-dynamic-price.cartSplitPiece'|trans({'%count%': mpGroup.count, '%length%': mpGroup.length|number_format(0, ',', '.')})]) %}{% endfor %}{{ 'rc-dynamic-price.cartSplitLabel'|trans({'%length%': mpLength|number_format(0, ',', '.'), '%pieces%': mpParts|join(' + ')}) }}{% else %}{{ 'rc-dynamic-price.cartLengthLabel'|trans({'%length%': mpLength|number_format(0, ',', '.')}) }}{% endif %}{% if mpBilled != mpLength %} {{ 'rc-dynamic-price.cartBilledLabel'|trans({'%billed%': mpBilled|number_format(0, ',', '.')}) }}{% endif %}
{% endif %}

TWIG;
    }

    private function markerLine(): string
    {
        return self::MARKER;
    }
}
