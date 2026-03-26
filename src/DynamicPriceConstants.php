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

    /** Payload-Schlüssel: lesbare Längenbezeichnung für Warenkorb- und Bestellübersicht */
    public const PAYLOAD_LENGTH_LABEL = 'rc_length_label';

    /** Custom-Field-Name am Produkt — produktspezifische Mindestlänge in mm */
    public const FIELD_MIN_LENGTH = 'rc_meter_price_min_length';

    /** Custom-Field-Name am Produkt — produktspezifische Maximallänge in mm */
    public const FIELD_MAX_LENGTH = 'rc_meter_price_max_length';

    /** Custom-Field-Name am Produkt — Eingabe auf nächsten vollen Meter aufrunden */
    public const FIELD_ROUND_UP_METER = 'rc_meter_price_round_up_meter';
}
