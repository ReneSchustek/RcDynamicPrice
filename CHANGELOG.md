# Changelog

Alle nennenswerten Änderungen werden in dieser Datei dokumentiert.

## [1.6.4] - 2026-04-23

> **Deployment:** `php bin/console cache:clear` + `bin/build-storefront.sh` (JS geändert). Keine Datenbank-Migration. Bestehende Warenkörbe heilen sich beim nächsten Cart-Zugriff durch den regulären Enrichment-Pass — kein Backfill nötig.

### Behoben
- **Kritisch: Gesplittete Warenkorbpositionen konnten vom Kunden nicht entfernt werden.** `CartItemSplitAssembler::appendSiblingPieces` erzeugte Siblings per `new LineItem(...)` ohne `setRemovable(true)`/`setStackable(true)`. Shopware-Default ist `false`; der reguläre Add-Pfad setzt die Flags im Product-Enrichment, Siblings, die mitten im `BeforeLineItemAddedEvent` per `$cart->add()` eingeschleust werden, erreichen diesen Enrichment-Pass aber nicht zuverlässig — in der Storefront fehlten deshalb X-Button und Mengen-Minus an den Teilstücken, eine manuelle Mengenänderung auf 0 wurde per HTML5-`min="1"` abgelehnt. Der Kunde hatte keinen Weg, Sibling-Positionen zu entfernen. Fix: Flags werden jetzt explizit auf `true` gesetzt. Regression durch zwei Unit-Tests (`Equal`-Split + `MaxRest`-Split) und einen Integrationstest abgesichert.
- **Handshake-Bug im Plugin-Interaktionsprotokoll.** Die ID-Controller-Erkennung funktionierte in keiner Richtung zuverlässig:
    - **Frontend:** `dynamic-price.plugin.js` prüfte per `this._form.querySelector('[data-rc-id-controller]')`. `querySelector` durchsucht nur Nachkommen, nicht das Element selbst — RcCartSplitter setzt den Marker aber auf das Form-Element direkt (`this._form.dataset.rcIdController = 'true'`). RcDynamicPrice erkannte die fremde ID-Hoheit deshalb nicht und überschrieb die Hash-basierte LineItem-ID. Fix: zusätzliche `dataset.rcIdController`-Prüfung vor dem DOM-Query.
    - **Backend:** `LineItemSubscriber::effectiveSplitMode` suchte die Marker-Keys `rcTmmsActive`/`rcCustomFieldsActive` top-level im Request. Die Plugins injizieren die Marker aber genested als `lineItems[{productId}][payload][rcTmmsActive]`. Der Split-Modus-Downgrade auf `Hint` (bei fremder ID-Hoheit) griff deshalb nie. Fix: neue Helper-Methode `hasForeignIdControllerMarker` iteriert `$request->request->all('lineItems')` und prüft die Payload-Ebene; Top-Level-Check bleibt als Legacy-Pfad. Abgesichert durch drei neue Unit-Tests (nested TMMS, nested CustomFields, Negativ-Fall ohne Marker).
- `.ai/rules/plugin-interaction.md` dokumentiert die beiden Prüfebenen (Element-Dataset + Nachkommen-Selector; nested Payload-Lookup) explizit, damit zukünftige Plugins nicht in dieselbe Falle laufen.

### Geändert
- Test-Suite: +2 Unit-Tests in `CartItemSplitAssemblerTest`, +3 Unit-Tests in `LineItemSubscriberTest`, +1 Integrationstest in `LineItemSubscriberIntegrationTest`. Die Regression „removable/stackable am Sibling" ist jetzt ab dem Unit-Test bis hoch zum End-to-End-Pfad gegen Re-Einschleichung abgesichert.

## [1.6.3] - 2026-04-23

> **Deployment:** `php bin/console plugin:update RcDynamicPrice` (konvertiert `rc_meter_price_active` in `product_translation` auf Tri-State) + `php bin/console cache:clear`.

### Behoben
- `Migration1745600000ConvertActiveFieldToTriState` griff auf die Tabelle `product` zu, dort existiert die Spalte `custom_fields` aber nicht. Shopware hält Product-Custom-Fields in `product_translation` vor. In 1.6.2 lief die Migration deshalb ins `Unknown column 'custom_fields'`-Exception und blieb auf jeder Instanz hängen.
- Migration ist jetzt auf `product_translation` umgestellt und macht den Bool→Tri-State-Backfill als zwei Single-Statement-UPDATEs (true/1/"1" → "on", false/0/"0" → "inherit"). PHP-seitige Batch- und Pagination-Logik entfällt, weil der DB-Server das atomar erledigt.

## [1.6.2] - 2026-04-23

> **Deployment:** `php bin/console plugin:update RcDynamicPrice` (registriert das Kategorie-CustomFieldSet) + `php bin/console cache:clear`. Keine Datenmigration notwendig — es gab bisher keine gespeicherten Kategorie-Werte, weil die Migration auf keiner Instanz erfolgreich gelaufen war.

### Behoben
- `Migration1745500000AddCategoryCustomFieldSet` scheiterte mit `UniqueConstraintViolationException` auf `custom_field.name`, weil die Kategorie-Felder dieselben Namen wie die Produktfelder trugen. Shopware erzwingt `custom_field.name` global unique — Set-Zugehörigkeit rettet nicht. Die Migration ist auf keiner bekannten Instanz erfolgreich durchgelaufen, die Kategorie-Konfiguration aus 1.5.0/1.6.x war deshalb de facto ungenutzt.

### Geändert
- Kategorie-Custom-Fields bekommen einen eigenen Namensraum: `rc_meter_price_cat_active`, `rc_meter_price_cat_min_length`, `rc_meter_price_cat_max_length`, `rc_meter_price_cat_rounding`, `rc_meter_price_cat_split_mode`, `rc_meter_price_cat_max_piece_length`, `rc_meter_price_cat_split_hint`.
- `MeterConfigResolver` liest bei Kategorie-Lookups die neuen `CAT_FIELD_*`-Konstanten. Produkt-Feldnamen bleiben stabil (`rc_meter_price_active` etc.).
- `DynamicPriceConstants` trägt die sieben neuen `CAT_FIELD_*`-Konstanten.

### Breaking für Integrationen
- Integrationen, die Kategorie-Custom-Fields schreiben oder lesen, müssen auf die `rc_meter_price_cat_*`-Namen umstellen. Da die Migration vor 1.6.2 nirgends erfolgreich war, gibt es real keinen bestehenden Daten-Bestand — Umstellung ist reine Code-Änderung.

## [1.6.1] - 2026-04-23

> **Deployment:** `php bin/console cache:clear`. Keine Migration.

### Behoben
- `ServiceNotFoundException` beim Container-Build: der Monolog-Channel `rc_dynamic_price` war nur über `src/Resources/config/packages/monolog.yaml` deklariert, was in Shopware-Plugins nicht ausgewertet wird. Registrierung jetzt per `RcDynamicPrice::build(ContainerBuilder)` via `prependExtensionConfig('monolog', ['channels' => ['rc_dynamic_price']])`. `services.xml`-Verweise auf `monolog.logger.rc_dynamic_price` werden damit auflösbar.

## [1.6.0] - 2026-04-23

> **Deployment:** `php bin/console cache:clear` **zwingend** (ohne Container-Rebuild bleibt der neue Monolog-Channel unaufgelöst → `ServiceNotFoundException` beim ersten Request), `bin/build-storefront.sh` (JS und Twig geändert). Keine Datenbank-Migration.
>
> **Breaking für externe Integrationen:** Die neue Plugin-Exception-Klasse erbt von `\RuntimeException`, nicht von `\LogicException`. Bestehende `catch (\InvalidArgumentException)`-Blöcke auf `LengthSplitter::split` oder den `RcDynamicPriceConfigStruct`-Konstruktor fangen die Exception nicht mehr — auf `catch (DynamicPriceException)` oder `catch (\RuntimeException)` umstellen.
>
> **Hinweis für 1.6.0:** In dieser Version war die Channel-Registrierung nur über `packages/monolog.yaml` deklariert, was in Shopware-Plugins nicht ausgewertet wird. 1.6.1 behebt das — direkt auf 1.6.1 aktualisieren.

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
- `Migration1745600000ConvertActiveFieldToTriState` wrappt den Backfill-Batch jetzt in `Connection::transactional(...)`. Der Pagination-Zeiger (`$lastId`) rückt erst nach erfolgreichem Commit vor — bricht ein Batch mit transientem DB-Fehler ab, startet der Re-Run an derselben Position und überspringt keine Rows mehr.
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
