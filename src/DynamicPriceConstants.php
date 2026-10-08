<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice;

/**
 * Die Namen, die Erweiterung, Datenbank, Vorlagen und Skripte teilen: Zusatzfelder, Einstellungen,
 * Payload-Schlüssel, Cache-Tags und Rundungsmodi. An einer Stelle, damit ein Tippfehler nicht still
 * eine Funktion abschaltet.
 */
final class DynamicPriceConstants
{
    // Feldsätze

    /** Feldsatz am Produkt */
    public const SET_PRODUCT = 'rc_dynamic_price';

    /** Feldsatz an der Kategorie, deren Werte die Produkte darunter erben */
    public const SET_CATEGORY = 'rc_dynamic_price_category';

    // Zusatzfelder am Produkt

    /** Schaltet den Meterpreis: erben, an oder aus (siehe ActiveState) */
    public const FIELD_METER_ACTIVE = 'rc_meter_price_active';

    /**
     * Steuert, ob die eingegebene Länge die Variante wählt.
     *
     * Der Name bricht mit dem Vorsatz `rc_meter_price_` der übrigen Felder, weil das Feld mit dem
     * Preis nichts zu tun hat: Es schaltet ein Verhalten der Produktseite, und wer es sucht, sucht
     * nach „Länge". Im selben Feldsatz liegt es trotzdem, als dieselbe Sache und dieselbe Karte im
     * Verwaltungsbereich.
     */
    public const FIELD_LENGTH_VARIANT_SWITCH = 'rc_length_variant_switch';

    /**
     * Geführte Variantenauswahl: erst die Länge, dann nur die dazu kaufbaren Optionen der übrigen
     * Gruppen. Ein Haken, kein Dreifachwert: Gesetzt am Produkt oder an einer Kategorie seiner
     * Kette schaltet er ein; abwählen lässt er sich je Produkt nicht.
     */
    public const FIELD_GUIDED_SELECTION = 'rc_guided_selection';

    /**
     * Die Eigenschaftsgruppen, deren Werte Längen sind („Maße", „Länge"), als Mehrfachauswahl. Ein
     * Artikel nimmt die Gruppe, die er hat; so passt eine Einstellung an der Kategorie auch dort, wo
     * Artikel ihre Längen unterschiedlich benennen. Geführte Auswahl und Längenschalter lesen beide
     * dieselben Gruppen.
     */
    public const FIELD_LENGTH_GROUPS = 'rc_length_groups';

    /** Kategorie-Ebene der Längengruppen. */
    public const CAT_FIELD_LENGTH_GROUPS = 'rc_length_groups_cat';

    /** Kategorie-Ebene der geführten Auswahl, vererbt wie die übrigen Kategoriefelder. */
    public const CAT_FIELD_GUIDED_SELECTION = 'rc_guided_selection_cat';

    /** Kategorie-Ebene des Längenschalters. */
    public const CAT_FIELD_LENGTH_VARIANT_SWITCH = 'rc_length_variant_switch_cat';

    /**
     * Die Größen nur über die Länge wählen: Die Knöpfe der Längengruppe verschwinden, der Kunde gibt
     * nur seine Länge ein, und die nächstgrößere Größe wird berechnet. Gedacht für Stangenmaterial.
     */
    public const FIELD_LENGTH_ONLY = 'rc_length_only';

    /** Kategorie-Ebene von „Größen nur über die Länge". */
    public const CAT_FIELD_LENGTH_ONLY = 'rc_length_only_cat';

    /**
     * Die Einzelfelder der Längengruppe aus 1.23.0 und 1.23.1. Sie kamen nie auf Staging oder Live;
     * `Migration1788400000ReplaceLengthGroupWithLengthGroups` übernimmt ihre Werte und entfernt sie.
     */
    public const LEGACY_FIELD_GUIDED_LENGTH_GROUP = 'rc_guided_length_group';

    public const LEGACY_CAT_FIELD_GUIDED_LENGTH_GROUP = 'rc_guided_length_group_cat';

    // Zustände des Meterpreis-Schalters

    public const ACTIVE_INHERIT = 'inherit';
    public const ACTIVE_ON = 'on';
    public const ACTIVE_OFF = 'off';

    // Schlüssel der Grundeinstellung

    public const CONFIG_APPLY_TO_ALL_PRODUCTS = 'RcDynamicPrice.config.applyToAllProducts';
    public const CONFIG_MIN_LENGTH = 'RcDynamicPrice.config.minLength';
    public const CONFIG_MAX_LENGTH = 'RcDynamicPrice.config.maxLength';
    public const CONFIG_SPLIT_MODE = 'RcDynamicPrice.config.splitMode';
    public const CONFIG_MAX_PIECE_LENGTH = 'RcDynamicPrice.config.maxPieceLength';
    public const CONFIG_SPLIT_HINT_TEMPLATE = 'RcDynamicPrice.config.splitHintTemplate';
    public const CONFIG_HINT_TEXT = 'RcDynamicPrice.config.hintText';
    public const CONFIG_EQUAL_BILLING = 'RcDynamicPrice.config.equalSplitBilling';
    public const CONFIG_EQUAL_ENFORCE_MIN = 'RcDynamicPrice.config.equalSplitEnforceMin';

    /** Schaltet das Protokollieren von Kennzahlen im LoggingMetricsRecorder (Vorgabe aus) */
    public const CONFIG_ENABLE_METRICS = 'RcDynamicPrice.config.enableMetrics';

    // Abrechnung der Teilstücke im equal-Modus

    /**
     * Schnittlänge, die Vorgabe: Jedes Teilstück bekommt dieselbe aufgerundete Länge. Die Summe kann
     * die Eingabe übersteigen; der Kunde zahlt die tatsächlich geschnittene Länge.
     */
    public const EQUAL_BILLING_CUT_LENGTH = 'cut_length';

    /**
     * Exakte Länge: Die Teilstücke ergeben zusammen genau die Eingabe. Der Kunde zahlt die bestellte
     * Länge, den Verschnitt trägt der Shop.
     */
    public const EQUAL_BILLING_EXACT = 'exact';

    // Schlüssel der optionalen Kennzahlen

    /** Zähler: im Warenkorb berechnete Meterposition */
    public const METRIC_CART_ITEM_PROCESSED = 'cart.meter_item.processed';

    /** Zähler: Meterposition ohne ermittelbaren Preis, die die Bestellung sperrt */
    public const METRIC_CART_ITEM_REJECTED = 'cart.meter_item.rejected';

    /** Dauer: Rundung in Millisekunden */
    public const METRIC_ROUNDING_DURATION = 'rounding.duration_ms';

    /** Zähler: auf der Produktseite gezeigtes Meterpreis-Widget */
    public const METRIC_PRODUCT_PAGE_WIDGET_SHOWN = 'product_page.meter_widget.shown';

    // Cache-Tags der Produktseiten, verworfen vom CacheInvalidationSubscriber

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

    /** Höchstlänge je Teilstück in mm; darüber wird geteilt */
    public const FIELD_MAX_PIECE_LENGTH = 'rc_meter_price_max_piece_length';

    /** Eigener Hinweistext mit Platzhaltern für Eingaben über der Höchstlänge je Teilstück */
    public const FIELD_SPLIT_HINT = 'rc_meter_price_split_hint';

    // Zusatzfelder an der Kategorie. `custom_field.name` ist in Shopware über alle Feldsätze
    // eindeutig; die Kategoriefelder brauchen deshalb eigene Namen, der Zusatz `_cat` zeigt das
    // Gegenstück am Produkt.

    /** Kategorie-Ebene: erben, an oder aus wie am Produkt */
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

    // Schlüssel im Payload der Warenkorbposition

    /**
     * Die vom Kunden eingegebene und geprüfte Gesamtlänge des Zuschnitt-Auftrags in Millimetern. Eine
     * Position ist ein Auftrag, kein Teilstück; die Aufteilung steht in PAYLOAD_SPLIT_PIECES.
     */
    public const PAYLOAD_LENGTH_MM = 'meterLengthMm';

    /** Kennzeichen des Subscribers, dass die Position ein Meterartikel ist; nur dann rechnet der Processor */
    public const PAYLOAD_METER_ACTIVE = 'rc_meter_price_active';

    /**
     * Die Nummer des TMMS-Längenfelds bei Artikeln mit Längenschalter. Der Warenkorb zeigt für genau
     * dieses Feld „Gewünschte Länge" statt der Anleitung; ob der Schalter vom Produkt oder von der
     * Kategorie kommt, sieht die Position sonst nicht.
     */
    public const PAYLOAD_LENGTH_SWITCH = 'rc_length_switch';

    /** Rundungsmodus aus den aufgelösten Einstellungen */
    public const PAYLOAD_ROUNDING = 'rc_rounding_mode';

    /** Produktspezifische Mindestlänge, vom Subscriber gesetzt */
    public const PAYLOAD_MIN_LENGTH = 'rc_min_length_mm';

    /** Produktspezifische Maximallänge, vom Subscriber gesetzt */
    public const PAYLOAD_MAX_LENGTH = 'rc_max_length_mm';

    /**
     * Schnittlängen der Teilstücke in mm, vom Assembler gesetzt, also das, was die Fertigung schneidet.
     * Ohne Teilung genau ein Eintrag.
     *
     * Ein Teilstück unter der Mindestlänge behält hier seine tatsächliche Länge: Wer 5.100 mm
     * bestellt, bekommt 5.000 + 100 mm. Die Mindestlänge ist eine Abrechnungsregel und wirkt erst
     * in PAYLOAD_BILLED_PIECES.
     */
    public const PAYLOAD_SPLIT_PIECES = 'rc_split_pieces';

    /**
     * Werden Teilstücke unter der Mindestlänge mit der Mindestlänge abgerechnet?
     *
     * `max_rest` immer, `equal` nach der Händler-Option `equalSplitEnforceMin`. Fehlt der Schlüssel,
     * wird nicht angehoben: Positionen aus der Zeit vor der Trennung von Schnitt- und Abrechnungslänge
     * tragen die Anhebung schon in ihren Schnittlängen, ihr Preis bleibt so unverändert.
     */
    public const PAYLOAD_MIN_BILLING = 'rc_min_billing';

    /** Abgerechnete (je Teilstück aufgerundete) Längen in mm, vom Processor gesetzt */
    public const PAYLOAD_BILLED_PIECES = 'rc_billed_pieces';

    /**
     * Abgerechnete Gesamtlänge des Auftrags in mm, die Summe der abgerechneten Teilstücke.
     *
     * Positionen aus der Zeit, als jedes Teilstück eine eigene Position war, tragen hier noch die
     * Länge eines einzelnen Teilstücks.
     */
    public const PAYLOAD_BILLED_LENGTH_MM = 'rc_billed_length_mm';

    /**
     * Längste abgerechnete Einzellänge in mm. Bestimmt die DeliveryInformation und damit die
     * längenbasierten Versandregeln (`cartLineItemDimensionLength`).
     */
    public const PAYLOAD_MAX_PIECE_LENGTH_MM = 'rc_max_piece_length_mm';

    /**
     * Gruppierte Aufteilung für die Anzeige: Liste aus `['length' => int, 'count' => int]`,
     * etwa `[['length' => 5000, 'count' => 1], ['length' => 1000, 'count' => 1]]` für „1× 5.000 mm
     * + 1× 1.000 mm". Einmal berechnet, weil Warenkorb, Bestellbestätigung, Rechnung und Lieferschein
     * sie brauchen; die Mail-Vorlage liegt in der Datenbank und soll keine Gruppierung enthalten.
     */
    public const PAYLOAD_SPLIT_SUMMARY = 'rc_split_summary';

    /**
     * Der unveränderte Produktname der Position, bevor der Processor Länge und Aufteilung anhängt.
     *
     * Der Positionsname ist die einzige Angabe, die Shopware überallhin mitführt: in die Verwaltung,
     * die Bestellbestätigung, die Belege und über die Bestellung in jede Warenwirtschaft. Er trägt
     * deshalb die Längenangabe. Damit der Zusatz bei jedem Neuberechnen gleich bleibt und sich nicht
     * anhäuft, wird der Name immer aus diesem Grundnamen neu gebildet und nie an den Bestand angehängt.
     */
    public const PAYLOAD_BASE_LABEL = 'rc_base_label';

    // Rundungsmodi; die Schrittweiten stehen in MeterProductHelper::ROUNDING_STEPS

    public const ROUNDING_NONE = 'none';
    public const ROUNDING_CM = 'cm';
    public const ROUNDING_QUARTER_M = 'quarter_m';
    public const ROUNDING_HALF_M = 'half_m';
    public const ROUNDING_FULL_M = 'full_m';
}
