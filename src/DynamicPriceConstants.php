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
}
