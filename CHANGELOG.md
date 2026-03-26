# Changelog

Alle nennenswerten Änderungen werden in dieser Datei dokumentiert.

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
