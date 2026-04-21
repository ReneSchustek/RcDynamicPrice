# Changelog

Alle nennenswerten Änderungen werden in dieser Datei dokumentiert.

## [1.4.2] - 2026-04-21

> **Deployment:** kein Shop-Deployment nötig (nur CI-Infrastruktur)

### Hinzugefügt
- GitHub-Actions-CI-Pipeline `.github/workflows/ci.yml`: Security-Audit (nur Prod-Deps), PHP CS Fixer, PHPStan Level 8 und PHPUnit laufen bei jedem Push auf `main` und bei Pull Requests

## [1.4.1] - 2026-04-21

> **Deployment:** `php bin/console plugin:update RcDynamicPrice` (neue Repair-Migration) + `bin/build-storefront.sh` (JS-Kommentar geändert) + `cache:clear`

### Geändert
- Architektur: Split-Orchestrierung in neuen Service `CartItemSplitAssembler` ausgelagert, Subscriber enthält nur noch Event-Handling und Delegation
- Neues Value Object `MeterSplittingConfig` kapselt die Produkt-/Channel-spezifischen Split-Parameter
- `LineItemSubscriber` injiziert jetzt einen Logger und protokolliert Skip-Pfade (info) bzw. Bounds-Verletzungen (warning)
- `mmLength`-Eingabe wird streng mit `ctype_digit` validiert, blockiert Eingaben wie `5000abc` oder `500.5`
- `LengthSplitter` dokumentiert die Obergrenze `MAX_TOTAL_MM = 1.000.000` und wirft bei Überschreitung eine Exception

### Hinzugefügt
- Repair-Migration `1745400000EnsureSplittingFieldsExist`: wirft eine `RuntimeException`, wenn das CustomFieldSet fehlt, und legt ansonsten fehlende Splitting-Felder idempotent an
- Gemeinsame JSON-Fixture `tests/Fixtures/split-cases.json` für PHP-/JS-Parität der Split-Mathematik
- Matrix-Test `testEqualPiecesNeverExceedMax` stellt sicher, dass kein Teilstück `maxPiece` überschreitet
- Tests für PHP_INT_MAX-Obergrenze, `referencedId === null`, paradox `minLength > maxPieceLength`, ID-Controller-Fallback

### Dokumentation
- PHPDoc-Ergänzungen: `getCustomFieldInt`-Semantik, `splitMode`-String-Konvention im Struct, `_collectAllSuffixes`-Sortierregel
- `config.xml`: Kommentar zum Magic-String `"none"`-Platzhalter

## [1.4.0] - 2026-04-21

> **Deployment:** `bin/build-storefront.sh` erforderlich (JS geändert) + `php bin/console plugin:update RcDynamicPrice` (neue Migration) + `cache:clear`

### Hinzugefügt
- Längen-Splitting: Eingaben oberhalb einer konfigurierbaren Teilstücklänge werden entweder gleichmäßig, als volle Stücke plus Rest, oder nur mit Hinweistext behandelt
- Drei neue produktspezifische Custom Fields: `rc_meter_price_split_mode`, `rc_meter_price_max_piece_length`, `rc_meter_price_split_hint`
- Drei neue globale Plugin-Config-Felder als Fallback (Standard-Modus, Max-Teilstücklänge, Hinweistext-Vorlage)
- Neuer Service `LengthSplitter` mit rein funktionaler Split-Mathematik
- `SplitMode`-Enum mit toleranter `tryFromString`-Konvertierung
- Backend-Split im `LineItemSubscriber`: eingehendes LineItem wird auf erstes Teilstück reduziert, weitere Stücke als Sibling-LineItems an den Cart angehängt
- Frontend-Vorschau: JS rendert pro Eingabe die zu erwartende Aufteilung mit Platzhalter-Ersetzung
- Neue Snippets für Default-Hinweistexte in allen drei Modi (de-DE + en-GB)

### Geändert
- `MeterProductHelper` um drei Getter für Split-Konfiguration erweitert
- `LineItemSubscriber` refaktoriert: Payload-Schreiblogik in private Methode extrahiert
- `RcDynamicPriceConfigStruct` enthält jetzt Split-Konfiguration mit Validierung
- `plugin-interaction.md` ergänzt um Hinweis zu Multi-LineItem-Requests bei Auto-Split

## [1.3.0] - 2026-04-02

> **Deployment:** `bin/build-storefront.sh` erforderlich (JS geändert)

### Hinzugefügt
- Konfigurierbare Rundungsstufen: Volle cm, Viertel Meter, Halber Meter, Voller Meter (Select statt Checkbox)
- Migration ersetzt Bool-Feld `rc_meter_price_round_up_meter` durch Select-Feld `rc_meter_price_rounding`
- Bestehende Produkte mit Aufrundung werden automatisch auf „Voller Meter" migriert
- Generisches Suffix-Protokoll: Verschiedene Plugin-Suffixe werden automatisch in die LineItem-ID einbezogen
- Escape-Taste schließt das Hinweis-Modal (Barrierefreiheit)
- Tests für RcDynamicPriceConfigStruct

### Geändert
- JS-Plugin: Duplizierter Reset-Code in `_resetInput()` extrahiert (DRY)
- JS-Plugin: `_clearError()` entfernt jetzt beide CSS-Klassen korrekt
- MeterProductHelper: Redundante Konstante `VALID_ROUNDING_MODES` entfernt (DRY)
- LineItemSubscriberTest: Repetitiver Mock-Setup in Hilfsmethoden gebündelt
- Sync-Kommentare zwischen PHP und JS auf Deutsch vereinheitlicht

### Behoben
- Vollständige i18n-Prüfung: Alle Snippets, Labels und Config-Texte in de-DE und en-GB

## [1.2.1] - 2026-03-26

> **Deployment:** `bin/build-storefront.sh` erforderlich (JS + SCSS geändert)

### Behoben
- Popup-CSS wurde nicht geladen — SCSS-Datei zu `base.scss` umbenannt (Shopware-Konvention)
- JS-Plugin wird nach Variantenwechsel nicht re-initialisiert — `initializePlugins()` bei `onVariantChange`
- Plugin-Label auf Kurzform vereinheitlicht

## [1.2.0] - 2026-03-26

> **Deployment:** `bin/build-storefront.sh` erforderlich (JS geändert)

### Hinzugefügt
- Produktspezifische Min/Max-Länge (Custom Fields `rc_meter_price_min_length`, `rc_meter_price_max_length`)
- Aufrunden auf vollen Meter (Custom Field `rc_meter_price_round_up_meter`, pro Produkt konfigurierbar)
- Verschiedene Längen erzeugen separate Warenkorbpositionen
- Längenanzeige im Warenkorb und Checkout (inkl. Rundungshinweis)
- Popup-Hinweistext beim ersten Fokus auf das Eingabefeld
- Live-Aktualisierung des Hauptpreises auf der Produktseite
- Shopware-Snippets (de-DE + en-GB) für alle Frontend-Texte
- Kompatibilität mit RcCustomFields und RcCartSplitter (Event-basiertes Interaktionsprotokoll)

### Geändert
- MeterProductHelper: Fallback-Logik (Produkt → globale Config → Standardwert)
- MeterProductHelperInterface extrahiert (final class nicht mockbar in PHPUnit 11)
- DynamicPriceProcessor: Min/Max-Validierung, Round-Up via Payload statt customFields
- services.xml: Interface-IDs statt konkrete Klassen

### Behoben
- `InputBag::getInt()` wirft Exception bei leerem String (Symfony 6.x)
- Payload-Verlust bei bereits existierenden Warenkorbartikeln
- `innerHTML` → `textContent` im JS-Plugin (XSS-Prävention)

## [1.0.0] - 2026-03-25

> **Deployment:** `bin/build-storefront.sh` erforderlich (Erstinstallation)

### Hinzugefügt
- Plugin-Grundstruktur
- Admin-Konfiguration (Hinweistext, Mindest-/Maximallänge)
- Custom Field `rc_meter_price_active` zur Produkt-Aktivierung
- Frontend: Längeneingabe mit Live-Preisberechnung
- Cart-Integration: Subscriber + Processor
- PHPStan Level 8, PHP CS Fixer (PSR-12)

---

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).
