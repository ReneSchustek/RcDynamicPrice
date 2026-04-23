# Changelog

Alle nennenswerten Änderungen werden in dieser Datei dokumentiert.

## [1.6.0] - 2026-04-23

> **Deployment:** `bin/build-storefront.sh` (JS + Twig geändert) und `php bin/console cache:clear`. Keine Datenbank-Migration.

### Hinzugefügt
- **Accessibility (BFSG-Compliance):** Buy-Widget-Form trägt jetzt `aria-describedby`, `aria-invalid`, `role="alert"`/`aria-live="assertive"` am Fehler-Container, `aria-live="polite"` an Split-Info und Ergebnis. Der Hinweis-Modal nutzt `role="dialog"`, `aria-modal="true"`, `aria-labelledby` sowie Focus-Trap und Focus-Restauration beim Schließen.
- **Dedizierter Monolog-Channel `rc_dynamic_price`:** `LineItemSubscriber`, `DynamicPriceProcessor` und `CartItemSplitAssembler` loggen über einen eigenen Channel. Ops können Plugin-Logs gezielt filtern, ohne auf Message-Prefix-Matching angewiesen zu sein.
- **ConfigScope-Observability-Log:** Beim Add-to-Cart schreibt der Subscriber ein `info`-Event mit der aufgelösten Scope-Herkunft pro Feld (`productId`, `active`, `activeScope`, `minLengthScope`, …). Support-Fälle "warum ist Preis X?" sind damit ohne Code-Durchgang nachvollziehbar.
- **Plugin-eigene Exception-Klasse `DynamicPriceException`** (`src/Exception/`) mit stabilen `errorCode`-Konstanten (`CODE_INVALID_TOTAL_LENGTH`, `CODE_BACKFILL_INCOMPLETE` etc.). Alle plugin-seitig geworfenen Fehler sind jetzt Instanzen dieser Klasse; `getErrorCode()` liefert den maschinenlesbaren Identifier.
- **Integration-Test-Suite** in `tests/Integration/`: `DynamicPriceProcessorIntegrationTest` fährt gegen einen echten Shopware-Core `QuantityPriceCalculator`; `LineItemSubscriberIntegrationTest` wired Subscriber → Resolver → Assembler → Splitter mit echten Instanzen. Getrennte PHPUnit-Suite `Integration`.
- **Rollback-Abschnitt im README:** konkrete Schritte und SQL-Queries für den Downgrade-Pfad 1.5.x → 1.4.x, inkl. Tri-State-zu-Bool-Rückkonvertierung und Cache-Invalidierung.

### Geändert
- `LengthSplitter`, `RcDynamicPriceConfigStruct` und beide Migrations werfen jetzt `DynamicPriceException` statt generische `\RuntimeException`/`\InvalidArgumentException`. Externe Integrationen, die zuvor `catch (\InvalidArgumentException)` auf Plugin-Aufrufen gemacht haben, müssen auf `catch (DynamicPriceException)` oder `catch (\RuntimeException)` umstellen (die neue Klasse erbt von `\RuntimeException`).
- `.gitattributes` zwingt LF-Line-Endings auf allen Text-Dateien, damit Windows-Clients (`core.autocrlf=true`) und DevBox/CI nicht mehr auseinanderlaufen.

## [1.5.3] - 2026-04-23

> **Deployment:** `php bin/console cache:clear` reicht. Die Migration-Änderung wirkt nur bei Erst-Durchlauf oder erzwungenem Re-Run, nicht auf bereits migrierte Shops.

### Behoben
- `CacheInvalidationSubscriber` invalidiert `rc-dynamic-price-category-{id}` jetzt auch bei Kategorie-Löschung. Bisher hörte der Subscriber nur auf `EntityWrittenContainerEvent`, das Delete-Events nicht zuverlässig abdeckt — Folge: stale HTTP-Cache-Einträge bis TTL-Ablauf. Neu: separate Subscriptions auf `CategoryEvents::CATEGORY_WRITTEN_EVENT` und `CATEGORY_DELETED_EVENT` mit gemeinsamem Handler.

### Geändert
- `Migration1745600000ConvertActiveFieldToTriState` wrappt den Backfill-Batch jetzt in `Connection::transactional(...)`. Cursor (`$lastId`) rückt erst nach erfolgreichem Commit vor — bricht ein Batch mit transientem DB-Fehler ab, startet der Re-Run an derselben Position und überspringt keine Rows mehr.
- Deutsche Umlaute (ä/ö/ü/ß) in Kommentaren, Log-Messages, Exception-Messages, Admin-Labels und Help-Texts konsistent wiederhergestellt. Keine Identifier-Änderungen, keine Daten-Migration.

## [1.5.2] - 2026-04-22

> **Deployment:** kein Shop-Deployment nötig (Dev-Tooling).

### Geändert
- Dev-Dependencies aktualisiert: `composer/composer` auf 2.9.7, `phpseclib/phpseclib` auf 3.0.51, `friendsofphp/php-cs-fixer` auf 3.95.1. Alle bisher gemeldeten HIGH/LOW-CVEs in Dev-Deps sind damit behoben.
- Lokales Gate-Script `.ai/checker/brief-done-gate.sh` zieht `composer audit` auf `--no-dev` (analog CI) und führt den Vollaudit separat und informativ aus — BRIEF-Abschluss hängt nicht mehr am Dev-Dep-Audit.
- `composer.lock` wird wieder committed, damit Lock-State zwischen lokal und CI/DevBox eindeutig ist.

## [1.5.1] - 2026-04-22

> **Deployment:** kein Shop-Deployment nötig (nur Tests + Regel-Doku).

### Hinzugefügt
- Regression-Guard `AdminLabelCleanlinessTest`: prüft `config.xml` (Card-Titles, Labels, HelpTexts, Placeholders, Option-Names) und alle Migration-Label-Maps gegen technische Strings (`Rc `-Prefix, `rc_`-Prefix, `Custom Field`/`Custom Fields`-Platzhalter)
- Regel `.ai/rules/shopware.md` -> neuer Abschnitt "Admin-Sichtbarkeit": sprachlich passende Bezeichnungen sind Pflicht, technische Feldnamen bleiben stabil

## [1.5.0] - 2026-04-22

> **Deployment:** `php bin/console plugin:update RcDynamicPrice` (zwei neue Migrations) + `php bin/console cache:clear`. Nach Update alle HTTP-Caches verwerfen, da sich der Cache-Tag-Schema verändert hat.

### Hinzugefügt
- Konfigurations-Scope auf drei Ebenen: Produkt > Kategorie (Tree-Walk über Primärkategorie bis zur Wurzel) > Plugin-Global (neues Feld `applyToAllProducts`) > Default
- Neues Custom-Field-Set `rc_dynamic_price_category` an der `category`-Entity mit identischen Feldern wie am Produkt
- Service `MeterConfigResolver` (plus Interface) lösen die finale Config zentral auf und liefern pro Feld die Herkunft (`ConfigScope::Product|Category|Global|Default`)
- `CategoryChainLoader` lädt die Primärkategorie samt Ahnenkette ohne N+1 (ein DAL-Call über `category.path`)
- `CacheInvalidationSubscriber` invalidiert gezielt `rc-dynamic-price-category-{id}` bei Kategorie-Writes und `rc-dynamic-price-global` bei Änderungen an einer Plugin-Config, die in den Resolver einfließt
- `StorefrontResponseSubscriber` hängt die Meterpreis-Cache-Tags als `sw-cache-tags`-Header an Produktseiten

### Geändert
- Produkt-Feld `rc_meter_price_active` wurde von `bool` (Checkbox) auf `select` mit den Werten `inherit` / `on` / `off` (Default `inherit`) umgebaut. Daten-Backfill: `true -> on`, `false -> inherit`. Eine Verifikations-Query bricht die Migration ab, falls nach dem Backfill noch bool-/int-Werte auftauchen.
- `MeterProductHelper` ist auf die zwei Utility-Methoden `loadProduct` (inkl. Kategorie-Assoziation) und `roundUp` geschrumpft. Scope-sensitive Config-Leser liegen komplett im neuen Resolver.
- `LineItemSubscriber`, `DynamicPriceProcessor`-Kette und `ProductPageSubscriber` greifen nicht mehr direkt auf `product.customFields` zu, sondern konsumieren `ResolvedMeterConfig`.

### Hinweis für Integrationen (Breaking)
- Fremde Integrationen, die `customFields.rc_meter_price_active === true` direkt prüfen, **brechen**. Ersatz: `MeterConfigResolverInterface::resolveForProduct(...)` oder `=== 'on'`-Check.

## [1.4.3] - 2026-04-22

> **Deployment:** `php bin/console cache:clear` (nur `config.xml` geändert, keine Migration nötig)

### Behoben
- Plugin-Konfiguration im Admin-Backend erschien für deutsche Admin-User komplett in Englisch. Ursache: `config.xml`-Elemente ohne `lang`-Attribut werden von Shopware als `en-GB`-Default interpretiert und von nachgezogenen `lang="en-GB"`-Einträgen überschrieben — es existierte kein `lang="de-DE"`-Eintrag. Alle `<title>`, `<label>`, `<helpText>`, `<placeholder>` und `<option><name>` führen jetzt beide Locales explizit.

### Hinzugefügt
- Regressionstest `LocalizationCompletenessTest` parst `config.xml` und alle Migration-JSON-Payloads und erzwingt, dass jedes übersetzbare Label sowohl `de-DE` als auch `en-GB` pflegt
- README-Abschnitt „Backend-Sprache" dokumentiert Fallback-Ordnung und Admin-Locale-Bindung

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
