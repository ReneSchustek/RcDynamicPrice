<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice;

final class DynamicPriceConstants
{
    // --- Custom-Field-Namen am Produkt ---

    /** Steuert ob das Meterartikel-Widget angezeigt wird */
    public const FIELD_METER_ACTIVE = 'rc_meter_price_active';

    /** Produktspezifische Mindestlänge in mm */
    public const FIELD_MIN_LENGTH = 'rc_meter_price_min_length';

    /** Produktspezifische Maximallänge in mm */
    public const FIELD_MAX_LENGTH = 'rc_meter_price_max_length';

    /** Rundungsmodus (none, cm, quarter_m, half_m, full_m) */
    public const FIELD_ROUNDING = 'rc_meter_price_rounding';

    /** Split-Modus fuer Langstuecke (equal, max_rest, hint; leer = kein Split) */
    public const FIELD_SPLIT_MODE = 'rc_meter_price_split_mode';

    /** Maximallaenge pro Teilstueck in mm — Schwelle fuer Splitting */
    public const FIELD_MAX_PIECE_LENGTH = 'rc_meter_price_max_piece_length';

    /** Kundenspezifischer Hinweistext mit Platzhaltern, wenn mehr als maxPieceLength eingegeben wurde */
    public const FIELD_SPLIT_HINT = 'rc_meter_price_split_hint';

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
