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
- **Längen-Splitting:** Eingaben über einer konfigurierbaren Teilstücklänge werden entweder automatisch in gleichmäßige oder „max + Rest"-Teilstücke aufgeteilt, oder der Kunde erhält einen konfigurierbaren Hinweis zum manuellen Aufteilen
- Berechneter Preis wird verbindlich in den Warenkorb übernommen
- Verschiedene Längen erzeugen separate Warenkorbpositionen
- Länge wird im Warenkorb, im Checkout und in Bestellungen angezeigt
- Aktivierbar pro Produkt, pro Kategorie (inklusive Tree-Walk zur Wurzel) oder global für alle Produkte
- Tri-State pro Produkt: **Vererben** / **Aktiv** / **Inaktiv** (Custom Field `rc_meter_price_active`)
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
| Standard-Split-Modus | Fallback, wenn am Produkt kein eigener Modus gesetzt ist (`equal`, `max_rest`, `hint` oder `none`) | none |
| Max. Teilstücklänge (mm) | Schwellwert für das Splitting (Fallback) | 0 (kein Splitting) |
| Hinweistext-Vorlage | Template mit Platzhaltern `{length}`, `{maxPiece}`, `{pieces}`, `{pieceLength}`, `{remainder}` | leer (Snippet-Default) |
| Meterpreis global fuer alle Produkte aktivieren | Aktiviert den Meterpreis für alle Produkte, die am Produkt auf **Vererben** stehen und deren Kategorie-Kette keinen Override setzt. Produkte mit **Inaktiv** bleiben immer deaktiviert. | aus |

## Konfigurations-Scope

Der Meterpreis kann auf drei Ebenen konfiguriert werden; die Prioritäten werden strikt eingehalten:

| Priorität | Ebene | Wirkung |
|-----------|-------|---------|
| 1 (höchste) | Produkt | Produktfelder überschreiben alles. `Aktiv` erzwingt, `Inaktiv` schaltet ab (Kurzschluss), `Vererben` reicht die Entscheidung weiter. |
| 2 | Kategorie (Primärkategorie → Wurzel, Tree-Walk) | Erster Treffer mit `Aktiv`/`Inaktiv` in der Ahnenkette entscheidet. Numerische Felder werden pro Feld aus der nächstgelegenen Kategorie gezogen, die das Feld gesetzt hat. |
| 3 | Plugin-Global (`applyToAllProducts`) | Aktiviert alle Produkte, die auf `Vererben` stehen und keinen Kategorie-Override haben. Numerische Fallbacks kommen aus der Plugin-Konfiguration. |
| 4 (niedrigste) | Default | `min = 1`, `max = 10000`, `rounding = none`, `splitMode = null`. Greift nur, wenn keine höhere Ebene einen Wert liefert. |

**Beispiele:**

- Produkt `Inaktiv` → Meterpreis immer aus, auch bei Kategorie `Aktiv` und Global `Aktiv`.
- Produkt `Vererben`, Kategorie `Aktiv` → Meterpreis an, Werte aus Kategorie/Global-Fallback.
- Produkt `Vererben`, Kategorie-Kette alle `Vererben`, Global `applyToAllProducts = true` → Meterpreis an, Werte aus Global.
- Produkt `Vererben`, Kategorie `Inaktiv`, Global `Aktiv` → Meterpreis aus (Kategorie gewinnt gegen Global).

### Kategorie-Custom-Fields

Im Admin am Kategorie-Eintrag → **Individuelle Felder** → **Dynamischer Meterpreis (Kategorie)**. Dieselben Felder wie am Produkt, jeweils leer = „vererben / nicht setzen". Untergeordnete Kategorien erben von der Elternkette.

### Produktspezifische Custom Fields

Im Admin unter dem jeweiligen Produkt → **Individuelle Felder** → **Dynamischer Meterpreis**:

| Feld | Typ | Beschreibung |
|------|-----|-------------|
| Meterpreis | Select (Vererben / Aktiv / Inaktiv) | Steuert die Aktivierung. **Vererben** reicht an Kategorie und Plugin-Global weiter, **Inaktiv** deaktiviert das Produkt unter allen Umständen. |
| Mindestlänge (mm) | Zahl | Produktspezifisches Minimum (leer = globaler Wert) |
| Maximallänge (mm) | Zahl | Produktspezifisches Maximum (leer = globaler Wert) |
| Rundungsmodus | Select | Legt fest, auf welche Einheit die Eingabe aufgerundet wird. Optionen: Keine Rundung, Volle Zentimeter (10 mm), Viertel Meter (250 mm), Halber Meter (500 mm), Voller Meter (1000 mm). Die tatsächliche Schnittlänge bleibt erhalten. |
| Split-Modus | Select | `Gleichmäßig aufteilen`, `Volle Stücke plus Rest`, `Nur Hinweis`. Leer = globaler Fallback. |
| Max. Teilstücklänge (mm) | Zahl | Ab dieser Länge wird aufgeteilt oder der Hinweis angezeigt. Leer = kein Splitting. |
| Hinweistext für Splitting | Text | Kundenspezifische Vorlage mit Platzhaltern `{length}`, `{maxPiece}`, `{pieces}`, `{pieceLength}`, `{remainder}`. |

### Splitting-Verhalten

| Modus | Eingabe 8 000 mm bei Max-Teilstück 5 000 | Min 2 000 |
|-------|------------------------------------------|-----------|
| Gleichmäßig (`equal`) | 2 × 4 000 mm | — |
| Volle Stücke + Rest (`max_rest`) | 5 000 + 3 000 mm | Rest < Min → Min wird verwendet (z. B. 6 000 mm → 5 000 + 2 000) |
| Nur Hinweis (`hint`) | Kein Auto-Split, Hinweistext wird gerendert, Submit blockiert | — |

Die Rundungsstufe wirkt **pro Teilstück**. Beispiel: 3 × 4 750 mm im Modus `Voller Meter` wird als 3 × 5 000 mm berechnet.

## Backend-Sprache

Die Plugin-Konfiguration im Admin und die Custom-Fields am Produkt folgen beide der Admin-User-Locale:

1. Admin-User-Locale (z. B. `de-DE`)
2. System-Default-Locale
3. `en-GB` (Shopware-Fallback)

Wer die Ausgabesprache umschalten möchte, ändert die eigene Admin-User-Sprache (Rechts oben → Nutzerprofil → Sprache). Das Plugin pflegt aktuell `de-DE` und `en-GB`. Weitere Sprachen müssen im Schema und in allen Migrations gleichzeitig ergänzt werden, sonst fällt das Backend still auf einen der gepflegten Locales zurück.

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
