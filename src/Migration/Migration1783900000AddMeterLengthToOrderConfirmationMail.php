<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Erweitert die Mail-Templates vom Typ order_confirmation_mail um Länge und Zuschnitt-Aufteilung
 * pro Position (HTML- und Plaintext-Variante).
 *
 * Ohne diesen Block nennt die Bestellbestätigung nur Bezeichnung, Menge und Preis — der Kunde
 * erfährt nicht, welche Länge er bestellt hat, und die Fertigung nicht, welche Stücke zu schneiden
 * sind.
 *
 * Zwei Schutzschichten gegen unbeabsichtigtes Überschreiben:
 * - Marker-Detection: enthält der Inhalt bereits den Marker, wird nichts verändert (idempotent).
 * - Anchor-Detection: ohne den exakten Default-Anchor (Shopware-Default-Label-Ausgabe) wird nicht
 *   gepatcht. Damit bleiben shop-spezifisch angepasste Vorlagen unangetastet — die manuelle
 *   Ergänzung ist in der README dokumentiert.
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
        // Forward-only, keine destruktive Phase.
    }

    /**
     * Patcht den HTML-Inhalt: fügt den Längen-Block direkt nach dem `</div>`-Tag ein, das auf den
     * Label-Anchor folgt. Skip-Bedingungen: Inhalt leer, Marker vorhanden (idempotent), Anchor
     * nicht gefunden (Template wurde vom Shop angepasst).
     *
     * Nur für interne Verwendung und Tests public.
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
     * Patcht den Plaintext-Inhalt: fügt den Längen-Block direkt nach der Zeile mit dem Label-Anchor
     * ein. Skip-Bedingungen analog HTML.
     *
     * Nur für interne Verwendung und Tests public.
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
