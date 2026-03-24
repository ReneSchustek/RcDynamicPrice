# RcDynamicPrice

Shopware 6 Plugin zur längenbasierten Preisberechnung.

Produkte (z. B. Kabel, Stoffe, Profile) werden nach Meterlänge verkauft. Der Grundpreis im System entspricht dem Preis für 1 m (= 1.000 mm). Der Kunde gibt seine Wunschlänge selbst ein, sieht sofort den berechneten Preis und legt diesen Preis verbindlich in den Warenkorb.

## Funktionen

- Längeneingabe in Millimetern auf der Produktdetailseite
- Modal mit konfigurierbarem Hinweistext beim Fokussieren des Eingabefelds
- Live-Preisberechnung: `Grundpreis ÷ 1000 × eingegebene mm`
- Validierung: nur positive Ganzzahlen, Mindest- und Maximalwert konfigurierbar
- Berechneter Preis wird verbindlich in den Warenkorb übernommen
- Länge wird im Warenkorb und in Bestellungen angezeigt (z. B. „Länge: 1.500 mm")
- Per Produkt aktivierbar über das Custom Field `rc_meter_price_active`
- Theme-kompatibel (BEM-Klassen, kein Inline-CSS)

## Voraussetzungen

- Shopware 6.7 oder 6.8
- PHP 8.2+

## Installation

```bash
# Plugin installieren
php bin/console plugin:refresh
php bin/console plugin:install --activate RcDynamicPrice
php bin/console cache:clear
```

## Konfiguration

Im Admin unter **Einstellungen → Plugins → Ruhrcoder - Dynamischer Meterpreis**:

| Feld | Beschreibung | Standard |
|------|-------------|---------|
| Hinweistext | Text im Modal beim Fokus auf das Eingabefeld | „Bitte Länge in Millimetern eingeben – z. B. 1500 für 1,5 m" |
| Mindestlänge (mm) | Kleinste erlaubte Eingabe | 1 |
| Maximallänge (mm) | Größte erlaubte Eingabe | 10000 |

## Produkt aktivieren

Im Admin unter dem jeweiligen Produkt → **Individuelle Felder** → **Meterpreis aktiv** aktivieren.

## Entwicklung

```bash
composer install
composer quality   # cs-check + phpstan + test
```
