<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice;

final class DynamicPriceConstants
{
    // --- Custom-Field-Set-Namen ---

    /** Custom-Field-Set am Produkt */
    public const SET_PRODUCT = 'rc_dynamic_price';

    /** Custom-Field-Set an der Kategorie (Scope-Override auf Kategorie-Ebene) */
    public const SET_CATEGORY = 'rc_dynamic_price_category';

    // --- Custom-Field-Namen am Produkt ---

    /** Steuert ob das Meterartikel-Widget angezeigt wird (Tri-State: inherit/on/off) */
    public const FIELD_METER_ACTIVE = 'rc_meter_price_active';

    // --- Active-Zustände (Tri-State) ---

    public const ACTIVE_INHERIT = 'inherit';
    public const ACTIVE_ON = 'on';
    public const ACTIVE_OFF = 'off';

    // --- Plugin-Config-Schlüssel ---

    public const CONFIG_APPLY_TO_ALL_PRODUCTS = 'RcDynamicPrice.config.applyToAllProducts';
    public const CONFIG_MIN_LENGTH = 'RcDynamicPrice.config.minLength';
    public const CONFIG_MAX_LENGTH = 'RcDynamicPrice.config.maxLength';
    public const CONFIG_SPLIT_MODE = 'RcDynamicPrice.config.splitMode';
    public const CONFIG_MAX_PIECE_LENGTH = 'RcDynamicPrice.config.maxPieceLength';
    public const CONFIG_SPLIT_HINT_TEMPLATE = 'RcDynamicPrice.config.splitHintTemplate';
    public const CONFIG_HINT_TEXT = 'RcDynamicPrice.config.hintText';
    public const CONFIG_EQUAL_BILLING = 'RcDynamicPrice.config.equalSplitBilling';
    public const CONFIG_EQUAL_ENFORCE_MIN = 'RcDynamicPrice.config.equalSplitEnforceMin';

    /** Optionale Observability: aktiviert den LoggingMetricsRecorder-Decorator (Default aus) */
    public const CONFIG_ENABLE_METRICS = 'RcDynamicPrice.config.enableMetrics';

    // --- equal-Modus: Abrechnungslänge der Teilstücke ---

    /**
     * Schnittlänge (Verkäufer-Default): jedes Teilstück wird auf dieselbe
     * aufgerundete Länge gebracht; die Stück-Summe kann die Eingabe übersteigen,
     * der Kunde zahlt die tatsächlich geschnittene Länge.
     */
    public const EQUAL_BILLING_CUT_LENGTH = 'cut_length';

    /**
     * Exakte Länge: die Teilstücke summieren sich genau zur Eingabe; der Kunde
     * zahlt die bestellte Länge (der Shop trägt den Verschnitt).
     */
    public const EQUAL_BILLING_EXACT = 'exact';

    // --- Metrik-Schlüssel (optionale Observability) ---

    /** Counter: erfolgreich im Cart verarbeitete Meterposition */
    public const METRIC_CART_ITEM_PROCESSED = 'cart.meter_item.processed';

    /** Counter: Meterposition, für die kein Preis ermittelt werden konnte (blockiert die Bestellung) */
    public const METRIC_CART_ITEM_REJECTED = 'cart.meter_item.rejected';

    /** Timing: Dauer der Rundungs-Arithmetik in Millisekunden */
    public const METRIC_ROUNDING_DURATION = 'rounding.duration_ms';

    /** Counter: auf der Produktseite eingeblendetes Meter-Widget (Vorschau) */
    public const METRIC_PRODUCT_PAGE_WIDGET_SHOWN = 'product_page.meter_widget.shown';

    // --- Cache-Tags ---

    public const CACHE_TAG_GLOBAL = 'rc-dynamic-price-global';
    public const CACHE_TAG_CATEGORY_PREFIX = 'rc-dynamic-price-category-';

    /** Produktspezifische Mindestlänge in mm */
    public const FIELD_MIN_LENGTH = 'rc_meter_price_min_length';

    /** Produktspezifische Maximallänge in mm */
    public const FIELD_MAX_LENGTH = 'rc_meter_price_max_length';

    /** Rundungsmodus (none, cm, quarter_m, half_m, full_m) */
    public const FIELD_ROUNDING = 'rc_meter_price_rounding';

    /** Split-Modus für Langstücke (equal, max_rest, hint; leer = kein Split) */
    public const FIELD_SPLIT_MODE = 'rc_meter_price_split_mode';

    /** Maximallänge pro Teilstück in mm — Schwelle für Splitting */
    public const FIELD_MAX_PIECE_LENGTH = 'rc_meter_price_max_piece_length';

    /** Kundenspezifischer Hinweistext mit Platzhaltern, wenn mehr als maxPieceLength eingegeben wurde */
    public const FIELD_SPLIT_HINT = 'rc_meter_price_split_hint';

    // --- Kategorie-Custom-Field-Namen ---
    // Shopware erzwingt globales UNIQUE auf `custom_field.name` — Kategorie-Felder brauchen
    // einen eigenen Namespace. `_cat`-Suffix hält den Zusammenhang zum Produktpendant sichtbar.

    /** Kategorie-Ebene: Tri-State analog zum Produktfeld */
    public const CAT_FIELD_METER_ACTIVE = 'rc_meter_price_cat_active';

    /** Kategorie-Ebene: Mindestlänge-Fallback für Produkte dieser Kategorie */
    public const CAT_FIELD_MIN_LENGTH = 'rc_meter_price_cat_min_length';

    /** Kategorie-Ebene: Maximallänge-Fallback */
    public const CAT_FIELD_MAX_LENGTH = 'rc_meter_price_cat_max_length';

    /** Kategorie-Ebene: Rundungsmodus-Fallback */
    public const CAT_FIELD_ROUNDING = 'rc_meter_price_cat_rounding';

    /** Kategorie-Ebene: Split-Modus-Fallback */
    public const CAT_FIELD_SPLIT_MODE = 'rc_meter_price_cat_split_mode';

    /** Kategorie-Ebene: Maximale Teilstücklänge-Fallback */
    public const CAT_FIELD_MAX_PIECE_LENGTH = 'rc_meter_price_cat_max_piece_length';

    /** Kategorie-Ebene: Split-Hint-Template-Fallback */
    public const CAT_FIELD_SPLIT_HINT = 'rc_meter_price_cat_split_hint';

    // --- Payload-Schlüssel (LineItem) ---

    /**
     * Vom Kunden eingegebene und validierte Gesamtlänge des Zuschnitt-Auftrags in Millimetern.
     * Eine Position bildet einen Auftrag ab, nicht ein Teilstück — die Aufteilung steht in
     * PAYLOAD_SPLIT_PIECES.
     */
    public const PAYLOAD_LENGTH_MM = 'meterLengthMm';

    /** Flag das der Subscriber gesetzt hat — zweite Absicherung im Processor */
    public const PAYLOAD_METER_ACTIVE = 'rc_meter_price_active';

    /** Rundungsmodus, vom Subscriber aus dem Produkt gelesen */
    public const PAYLOAD_ROUNDING = 'rc_rounding_mode';

    /** Produktspezifische Mindestlänge, vom Subscriber gesetzt */
    public const PAYLOAD_MIN_LENGTH = 'rc_min_length_mm';

    /** Produktspezifische Maximallänge, vom Subscriber gesetzt */
    public const PAYLOAD_MAX_LENGTH = 'rc_max_length_mm';

    /**
     * Schnittlängen der Teilstücke in mm, vom Assembler gesetzt — das, was die Fertigung schneidet.
     * Ohne Split genau ein Eintrag.
     *
     * Ein Teilstück unter der Mindestlänge behält hier seine tatsächliche Länge: Wer 5.100 mm
     * bestellt, bekommt 5.000 + 100 mm. Die Mindestlänge ist eine Abrechnungsregel und wirkt erst
     * in PAYLOAD_BILLED_PIECES.
     */
    public const PAYLOAD_SPLIT_PIECES = 'rc_split_pieces';

    /**
     * Werden Teilstücke unter der Mindestlänge mit der Mindestlänge abgerechnet?
     *
     * `max_rest` immer, `equal` nach Händler-Option `equalSplitEnforceMin`. Fehlt der Schlüssel,
     * wird nicht angehoben — Bestandspositionen aus der Zeit vor der Trennung von Schnitt- und
     * Abrechnungslänge tragen die Anhebung bereits in ihren Schnittlängen, ihr Preis bleibt damit
     * unverändert.
     */
    public const PAYLOAD_MIN_BILLING = 'rc_min_billing';

    /** Abgerechnete (je Teilstück aufgerundete) Längen in mm, vom Processor gesetzt */
    public const PAYLOAD_BILLED_PIECES = 'rc_billed_pieces';

    /**
     * Gesamt-Abrechnungslänge des Auftrags in mm = Summe der abgerechneten Teilstücke.
     *
     * Vor der Umstellung auf Auftrags-Positionen bezeichnete der Schlüssel die Länge eines
     * einzelnen Teilstücks. Bestandspositionen tragen noch diese alte Bedeutung.
     */
    public const PAYLOAD_BILLED_LENGTH_MM = 'rc_billed_length_mm';

    /**
     * Längste abgerechnete Einzellänge in mm. Bestimmt die DeliveryInformation und damit die
     * längenbasierten Versandregeln (`cartLineItemDimensionLength`).
     */
    public const PAYLOAD_MAX_PIECE_LENGTH_MM = 'rc_max_piece_length_mm';

    /**
     * Gruppierte Aufteilung für die Anzeige: Liste aus `['length' => int, 'count' => int]`,
     * z. B. `[['length' => 5000, 'count' => 1], ['length' => 1000, 'count' => 1]]` für „1× 5.000 mm
     * + 1× 1.000 mm". Einmal berechnet, weil sie in Warenkorb, Bestellbestätigungs-Mail, Rechnung
     * und Lieferschein gebraucht wird — die Mail-Vorlage liegt in der Datenbank und soll keine
     * Gruppierungslogik enthalten.
     */
    public const PAYLOAD_SPLIT_SUMMARY = 'rc_split_summary';

    /**
     * Der unveränderte Produktname der Position, bevor der Processor Länge und Aufteilung anhängt.
     *
     * Der Positionsname ist die einzige Stelle, die Shopware überallhin mitführt — in den Admin, in
     * die Bestellbestätigung, auf die Belege und über die Bestellung in jede Warenwirtschaft. Er
     * trägt deshalb die Längenangabe. Damit der Zusatz bei jedem Neuberechnen des Warenkorbs
     * identisch bleibt und sich nicht anhäuft, wird das Label immer aus diesem Basisnamen **neu
     * gebildet** — niemals an den Bestand angehängt.
     */
    public const PAYLOAD_BASE_LABEL = 'rc_base_label';

    // --- Rundungsmodi ---

    public const ROUNDING_NONE = 'none';
    public const ROUNDING_CM = 'cm';
    public const ROUNDING_QUARTER_M = 'quarter_m';
    public const ROUNDING_HALF_M = 'half_m';
    public const ROUNDING_FULL_M = 'full_m';
}
