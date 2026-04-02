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
- Kompatibel mit RcCartSplitter und TmmsProductCustomerInputs
- Theme-kompatibel (BEM-Klassen, SCSS in `base.scss`)

## Voraussetzungen

- Shopware 6.7 oder 6.8
- PHP 8.2+

## Installation

```bash
php bin/console plugin:refresh
php bin/console plugin:install --activate RcDynamicPrice
bin/build-storefront.sh
php bin/console cache:clear
```

## Konfiguration

### Globale Plugin-Konfiguration

Im Admin unter **Einstellungen → Plugins → Dynamischer Meterpreis**:

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
| Rundungsmodus | Select | Legt fest, auf welche Einheit die Eingabe aufgerundet wird. Optionen: Keine Rundung, Volle Zentimeter (10 mm), Viertel Meter (250 mm), Halber Meter (500 mm), Voller Meter (1000 mm). Die tatsächliche Schnittlänge bleibt erhalten. |

## Deployment

| Änderung | Befehl |
|----------|--------|
| Nur PHP / Twig | `php bin/console cache:clear` |
| SCSS geändert | `php bin/console theme:compile` |
| JS / main.js geändert | `bin/build-storefront.sh` |

Siehe CHANGELOG.md für den Deployment-Hinweis pro Version.

## Entwicklung

```bash
composer install
composer quality   # cs-check + phpstan + test
```
