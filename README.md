# RcDynamicPrice

Shopware 6 Plugin zur längenbasierten Preisberechnung.

Produkte (z. B. Kabel, Stoffe, Profile) werden nach Meterlänge verkauft. Der Grundpreis im System entspricht dem Preis für 1 m (= 1.000 mm). Der Kunde gibt seine Wunschlänge selbst ein, sieht sofort den berechneten Preis und legt diesen Preis verbindlich in den Warenkorb.

## Funktionen

- Längeneingabe in Millimetern auf der Produktdetailseite
- Popup mit konfigurierbarem Hinweistext beim ersten Fokus auf das Eingabefeld
- Live-Preisberechnung: `Grundpreis ÷ 1000 × eingegebene mm`
- Validierung: nur positive Ganzzahlen, Mindest- und Maximalwert konfigurierbar
- Produktspezifische Min/Max-Länge (mit Fallback auf globale Konfiguration)
- Optional: Eingabe auf nächsten vollen Meter aufrunden (pro Produkt konfigurierbar)
- Berechneter Preis wird verbindlich in den Warenkorb übernommen
- Verschiedene Längen erzeugen separate Warenkorbpositionen
- Länge wird im Warenkorb, im Checkout und in Bestellungen angezeigt
- Per Produkt aktivierbar über das Custom Field `rc_meter_price_active`
- Kompatibel mit RcCustomFields (siehe Abschnitt Plugin-Interaktion)
- Theme-kompatibel (BEM-Klassen, kein Inline-CSS)

## Voraussetzungen

- Shopware 6.7 oder 6.8
- PHP 8.2+

## Installation

```bash
php bin/console plugin:refresh
php bin/console plugin:install --activate RcDynamicPrice
php bin/console cache:clear
```

## Konfiguration

### Globale Plugin-Konfiguration

Im Admin unter **Einstellungen → Plugins → Ruhrcoder - Dynamischer Meterpreis**:

| Feld | Beschreibung | Standard |
|------|-------------|---------|
| Hinweistext | Text im Popup beim ersten Fokus auf das Eingabefeld | „Bitte Länge in Millimetern eingeben – z. B. 1500 für 1,5 m" |
| Mindestlänge (mm) | Kleinste erlaubte Eingabe (Fallback) | 1 |
| Maximallänge (mm) | Größte erlaubte Eingabe (Fallback) | 10000 |

### Produktspezifische Custom Fields

Im Admin unter dem jeweiligen Produkt → **Individuelle Felder** → **Dynamischer Meterpreis**:

| Feld | Typ | Beschreibung |
|------|-----|-------------|
| Meterpreis aktiv | Checkbox | Aktiviert die Längeneingabe für dieses Produkt |
| Mindestlänge (mm) | Zahl | Produktspezifisches Minimum (leer = globaler Wert) |
| Maximallänge (mm) | Zahl | Produktspezifisches Maximum (leer = globaler Wert) |
| Auf vollen Meter aufrunden | Checkbox | Eingabe wird für die Preisberechnung auf den nächsten vollen Meter aufgerundet (z. B. 4050 → 5000). Die tatsächliche Schnittlänge bleibt erhalten. |

## Plugin-Interaktion mit RcCustomFields

RcDynamicPrice und RcCustomFields können auf demselben Produkt eingesetzt werden. Die Koordination funktioniert über ein Event-basiertes Protokoll:

1. RcDynamicPrice setzt `data-rc-meter-suffix` auf dem Buy-Formular und feuert ein `rcMeterLengthChanged`-Event
2. RcCustomFields hört auf dieses Event und bezieht den Meter-Suffix in seinen ID-Hash ein
3. Ergebnis: Verschiedene Längen UND verschiedene Custom-Field-Werte erzeugen separate Warenkorbpositionen

Siehe auch: [Plugin-Interaktionsprotokoll](.ai/rules/plugin-interaction.md) (Entwicklerdokumentation)

## Entwicklung

```bash
composer install
composer quality   # cs-check + phpstan + test
```
