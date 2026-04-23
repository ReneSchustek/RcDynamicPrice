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

    /** Kategorie-Ebene: Mindestlaenge-Fallback fuer Produkte dieser Kategorie */
    public const CAT_FIELD_MIN_LENGTH = 'rc_meter_price_cat_min_length';

    /** Kategorie-Ebene: Maximallaenge-Fallback */
    public const CAT_FIELD_MAX_LENGTH = 'rc_meter_price_cat_max_length';

    /** Kategorie-Ebene: Rundungsmodus-Fallback */
    public const CAT_FIELD_ROUNDING = 'rc_meter_price_cat_rounding';

    /** Kategorie-Ebene: Split-Modus-Fallback */
    public const CAT_FIELD_SPLIT_MODE = 'rc_meter_price_cat_split_mode';

    /** Kategorie-Ebene: Maximale Teilstuecklaenge-Fallback */
    public const CAT_FIELD_MAX_PIECE_LENGTH = 'rc_meter_price_cat_max_piece_length';

    /** Kategorie-Ebene: Split-Hint-Template-Fallback */
    public const CAT_FIELD_SPLIT_HINT = 'rc_meter_price_cat_split_hint';

    // --- Payload-Schlüssel (LineItem) ---

    /** Validierte Länge in Millimetern */
    public const PAYLOAD_LENGTH_MM = 'meterLengthMm';

    /** Flag das der Subscriber gesetzt hat — zweite Absicherung im Processor */
    public const PAYLOAD_METER_ACTIVE = 'rc_meter_price_active';

    /** Rundungsmodus, vom Subscriber aus dem Produkt gelesen */
    public const PAYLOAD_ROUNDING = 'rc_rounding_mode';

    /** Produktspezifische Mindestlänge, vom Subscriber gesetzt */
    public const PAYLOAD_MIN_LENGTH = 'rc_min_length_mm';

    /** Produktspezifische Maximallänge, vom Subscriber gesetzt */
    public const PAYLOAD_MAX_LENGTH = 'rc_max_length_mm';

    /** Berechnete (ggf. aufgerundete) Länge in mm */
    public const PAYLOAD_BILLED_LENGTH_MM = 'rc_billed_length_mm';

    // --- Rundungsmodi ---

    public const ROUNDING_NONE = 'none';
    public const ROUNDING_CM = 'cm';
    public const ROUNDING_QUARTER_M = 'quarter_m';
    public const ROUNDING_HALF_M = 'half_m';
    public const ROUNDING_FULL_M = 'full_m';
}
