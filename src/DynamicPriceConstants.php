<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice;

final class DynamicPriceConstants
{
    /** Custom-Field-Name am Produkt — steuert ob das Meterartikel-Widget angezeigt wird */
    public const FIELD_METER_ACTIVE = 'rc_meter_price_active';

    /** Payload-Schlüssel: validierte Länge in Millimetern */
    public const PAYLOAD_LENGTH_MM = 'meterLengthMm';

    /** Payload-Schlüssel: Flag das der Subscriber gesetzt hat — zweite Absicherung im Processor */
    public const PAYLOAD_METER_ACTIVE = 'rc_meter_price_active';

    /** Custom-Field-Name am Produkt — produktspezifische Mindestlänge in mm */
    public const FIELD_MIN_LENGTH = 'rc_meter_price_min_length';

    /** Custom-Field-Name am Produkt — produktspezifische Maximallänge in mm */
    public const FIELD_MAX_LENGTH = 'rc_meter_price_max_length';

    /** Custom-Field-Name am Produkt — Rundungsmodus (none, cm, quarter_m, half_m, full_m) */
    public const FIELD_ROUNDING = 'rc_meter_price_rounding';

    /** Payload-Schlüssel: Rundungsmodus, vom Subscriber aus dem Produkt gelesen */
    public const PAYLOAD_ROUNDING = 'rc_rounding_mode';

    /** Rundungsmodus: keine Rundung */
    public const ROUNDING_NONE = 'none';
    public const ROUNDING_CM = 'cm';
    public const ROUNDING_QUARTER_M = 'quarter_m';
    public const ROUNDING_HALF_M = 'half_m';
    public const ROUNDING_FULL_M = 'full_m';

    /** Payload-Schlüssel: produktspezifische Mindestlänge, vom Subscriber gesetzt */
    public const PAYLOAD_MIN_LENGTH = 'rc_min_length_mm';

    /** Payload-Schlüssel: produktspezifische Maximallänge, vom Subscriber gesetzt */
    public const PAYLOAD_MAX_LENGTH = 'rc_max_length_mm';

    /** Payload-Schlüssel: berechnete (ggf. aufgerundete) Länge in mm */
    public const PAYLOAD_BILLED_LENGTH_MM = 'rc_billed_length_mm';
}
