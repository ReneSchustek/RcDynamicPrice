# 1.20.2

- **Vorbereitung auf die nächste Shopware-Hauptversion.** Der Zugriff auf Suchergebnisse folgt der Schreibweise, die Shopware 6.8 verlangt. Am Verhalten ändert sich nichts.

# 1.20.1

- **Behoben (kritisch): Gutscheincodes konnten den Warenkorb-Zugang zerreißen.** Das Plugin behandelte jede Warenkorb-Position als Produkt. Ein Gutschein trägt an der Stelle, an der sonst die Produktkennung steht, aber den **Code** — die Produktsuche brach damit mit einer Ausnahme ab und riss den gesamten Vorgang mit. Der Kunde sah „Leider ist etwas schiefgelaufen“, im Protokoll stand nichts. Am laufenden Testsystem gegen die echte Datenbankschicht belegt.
- Hinzugefügt: Drei Tests für Pfade, die bisher keinen hatten — der Ausfallschutz beim Aufteilen einer Position (er fängt eine Fehlkonfiguration ab, damit kein Serverfehler entsteht) und die neue Typprüfung.
- Geändert: Nur noch Produktpositionen lösen die Meterpreis-Auflösung aus. Gutscheine, Versandkosten und Zuschläge werden übersprungen — dort ergab die Auflösung ohnehin nichts und schrieb nur eine irreführende Protokollzeile.

# Changelog

Alle nennenswerten Änderungen werden in dieser Datei dokumentiert.
## [1.20.0] - 2026-07-17 — Handshake-Fix + Per-Positions-Länge

> **Deployment:** `php bin/console plugin:update RcDynamicPrice && php bin/console cache:clear` + Theme-Kompilierung. Kein Schema-Break, keine Migration.

### Behoben

- **Angezeigte Aufteilung ≠ berechnete Aufteilung, wenn ein Plugin mit eigener Positionstrennung am
  Kaufformular hängt.** Die Storefront zeigte eine Auto-Split-Vorschau samt Preis an, während der
  Server die Position ungeteilt buchte. Beispiel: Eingabe 4.200 mm bei Teilstückgrenze 2.000 mm —
  Vorschau „3 Teilstücke, 4.500 mm abgerechnet", im Warenkorb landete „Länge 4.200 mm" als **eine**
  Position. Bei Zuschnittware ist die ausbleibende Aufteilung der schwerere Schaden: Es entsteht
  eine Bestellung über 4.200 mm auf einem Produkt, dessen Fertigungsgrenze bei 2.000 mm liegt.

  Ursache: Die Erkennung fremder Positions-Hoheit lief in `_onInput` nur über die Nachkommen des
  Formulars. Ein Plugin ohne eigenes DOM-Element kennzeichnet sich aber am Formular selbst und blieb
  dadurch unsichtbar. `_updateMeterState` prüfte bereits vollständig; die beiden Prüfungen waren
  auseinandergelaufen. Die Erkennung liegt jetzt in **einer** Methode, die beide Kennzeichnungs-Wege
  abdeckt und von beiden Aufrufern genutzt wird.

  **Betrifft nur 1.19.0.** Ältere Stände berechnen den Preis aus der Eingabelänge statt aus den
  Teilstücken; dort waren Vorschau und Server ohnehin einig.

### Hinzugefügt

- **Per-Positions-Länge für Mehrpositions-Adds.** `LineItemSubscriber` liest die
  Meterlänge jetzt aus drei Quellen, erste gültige gewinnt: (1) Per-Positions-Payload
  im Request `lineItems[<lineItemId>][payload][meterLengthMm]`, (2) bereits gesetzter
  LineItem-Payload (deckt Warenkorb-Wiederherstellung wie FroshPlatformShareBasket ab),
  (3) flacher Key `mmLength` — unverändert. Damit kann ein einzelner Request mehrere
  Positionen mit unterschiedlichen Längen anlegen (Vorbedingung für die geplante
  B2B-Schnellbestellung). Das Kaufformular verhält sich exakt wie bisher.
- Bei widersprüchlichem flachem und Per-Positions-Key gewinnt der Per-Positions-Key und
  es wird eine Warnung mit `lineItemId` und beiden Werten geloggt.

  **EN:** Per-line-item length for multi-item adds. `LineItemSubscriber` now reads the
  meter length from three sources (first valid wins): per-line-item request payload,
  already-set line-item payload (covers cart restoration, e.g. ShareBasket), and the flat
  `mmLength` key (unchanged). A single request can now create several positions with
  different lengths. The buy widget behaves exactly as before.

## [1.19.0] - 2026-07-13 — Geschnitten wird die bestellte Länge

> **Deployment:** `bin/build-storefront.sh` erforderlich (JS geändert) + `php bin/console cache:clear`. Keine Migration.
> **Der Preis ändert sich nicht.** Es ändert sich, was geschnitten und geliefert wird.

### Behoben

- **Ein zu kurzes Reststück wurde physisch auf die Mindestlänge verlängert.** Wer 5.100 mm bestellte (maximale Stücklänge 5.000 mm, Mindestlänge 1.000 mm), bekam nicht 5.000 + 100 mm, sondern **5.000 + 1.000 mm** — 900 mm mehr Material als bestellt. Die Teilstück-Liste ist zugleich die Fertigungsanweisung; auf dem Lieferschein stand entsprechend „1× 5.000 mm + 1× 1.000 mm", und genau so wurde geschnitten. Die Mindestlänge ist aber eine **Abrechnungs**regel („ein Zuschnitt kostet mindestens 1.000 mm"), keine Fertigungsregel.

  Geschnitten wird jetzt die bestellte Länge (5.000 + 100 mm), berechnet weiterhin die Mindestlänge (6.000 mm). **Der Positionspreis bleibt unverändert** — es ändert sich nur, wie viel Material die Fertigung zuschneidet und der Kunde erhält. Gleiches gilt im Modus „Gleichmäßig aufteilen" für die Option `equalSplitEnforceMin`.

### Geändert

- Der Positionsname nennt die **Schnittlängen** — er ist die Fertigungsanweisung: „(Zuschnitt 5.100 mm: 1× 5.000 mm + 1× 100 mm, berechnet 6.000 mm)". Die Abrechnungslänge steht als eigene Angabe dahinter.
- Der Hinweis auf der Produktseite sagt nicht mehr, das Reststück werde „angehoben", sondern dass es in seiner Länge geschnitten und mit der Mindestlänge berechnet wird.
- Neuer Payload-Schlüssel `rc_min_billing`. `rc_split_pieces` sind ab jetzt Schnittlängen, `rc_billed_pieces` die abgerechneten Längen. Bestandswarenkörbe rechnen unverändert weiter.

## [1.18.0] - 2026-07-13 — Länge und Zuschnitt stehen im Positionsnamen

> **Deployment:** `php bin/console database:migrate --all RcDynamicPrice` (entfernt den Längen-Block aus der Bestellbestätigungs-Mail) + `php bin/console cache:clear`. Kein Storefront-Build, **kein** Admin-Build nötig.
> **Der Positionsname ändert sich** — in Warenkorb, Mail, Belegen, Kundenkonto, Admin und in jeder angebundenen Warenwirtschaft.

### Neu

- **Länge und Aufteilung stehen jetzt im Positionsnamen** — z. B. „Set 6 Bodenprofil 2.0 aufgesetzt (Zuschnitt 5.100 mm: 1× 5.000 mm + 1× 1.000 mm, berechnet 6.000 mm)". Damit erscheinen sie überall, wo Shopware eine Position ausgibt: **auch in der Admin-Bestellansicht**, die bisher nur „1 × Set 6 Bodenprofil, 1.809,24 €" zeigte — ohne Länge, ohne Aufteilung, ohne Erklärung für den Betrag.
- **Die Warenwirtschaft bekommt die Länge.** Ein ERP übernimmt den Positionsnamen, nicht unsere internen Zusatzdaten. Der orgaMAX-Connector (`DeltraShopConnector6`) etwa überträgt je Position nur Artikelnummer, Menge, Preis und Name; die Längenangabe fehlte dort bislang vollständig — die Fertigung erfuhr aus dem ERP nicht, welche Stücke zu schneiden sind. Das gilt sinngemäß für jede andere Warenwirtschaft.

### Geändert

- **Die separaten Längen-Zeilen entfallen.** Warenkorb, Rechnung, Lieferschein, Gutschrift und Bestellbestätigungs-Mail gaben die Länge bisher als eigene Zeile unter dem Artikelnamen aus. Da sie jetzt im Namen steht, stünde sie sonst doppelt. Die Template-Overrides des Plugins entfallen ersatzlos; eine Migration entfernt den Block wieder aus den Mail-Vorlagen.
- **Handangepasste Mail-Vorlagen bleiben unberührt.** Wurde der eingefügte Block im Shop von Hand verändert, findet die Migration ihn nicht wortgleich und lässt die Vorlage in Ruhe — eine doppelte Längenangabe ist ärgerlich, eine zerschossene Bestellbestätigung wäre schlimmer. **Wer seine Vorlage angepasst hat, prüft die Bestellbestätigung nach dem Update** und entfernt den Block gegebenenfalls von Hand.
- Die Snippets `cartLengthLabel`, `cartBilledLabel`, `cartSplitLabel` und `cartSplitPiece` sind durch `labelWithDetails`, `labelLength`, `labelSplit`, `labelPiece` und `labelBilled` ersetzt.

## [1.17.0] - 2026-07-13 — Der Meterpreis gilt in allen Kanälen

> **Deployment:** `php bin/console cache:clear`. Keine Migration, kein Storefront-Build (kein JS geändert).
> **Preisrelevant für API-Clients:** siehe unten. Über die Storefront bestellte Positionen ändern sich nicht.

### Behoben

- **Eine über die Store-API gesendete Länge wurde stillschweigend verworfen, wenn sie als Zahl kam.** Das Formular der Storefront sendet die Länge als Text (`"5100"`), ein JSON-Client sendet sie naheliegenderweise als Zahl (`5100`). Nur der Textweg wurde akzeptiert. Die Position landete dann **ohne Längenangabe und zum Stückpreis** im Warenkorb — im Testshop 301,54 € statt der korrekten 1.809,24 € — ohne Fehlermeldung, ohne Hinweis in der Bestellung. Beide Schreibweisen sind jetzt gültig. Unverändert abgewiesen werden Eingaben, die keine ganze Länge sind (`"500.5"`, `"5000abc"`, negative Werte).

### Geändert

- **Ein Zuschnitt-Artikel ohne verwertbare Länge blockiert jetzt die Bestellung, statt zum Stückpreis durchzulaufen.** Fehlt die Länge ganz oder liegt sie außerhalb der erlaubten Grenzen, bleibt die Position im Warenkorb sichtbar, ist aber nicht bestellbar (`MeterPriceError`). Bisher fiel sie in beiden Fällen still auf den Grundpreis des Artikels zurück — bei Meterware praktisch immer zu billig, und niemandem fiel es auf, weil in der Bestellung keine Länge stand. Betroffen sind ausschließlich Wege, die die Länge nicht mitschicken (Store-API, Headless-Clients, manipulierte Anfragen); das Kaufformular der Storefront sendet sie immer.

## [1.16.0] - 2026-07-12 — Ein Zuschnitt-Auftrag ist eine Position

> **Deployment:** `php bin/console database:migrate --all RcDynamicPrice` (neue Migration, ergänzt die Bestellbestätigungs-Mail) + `php bin/console cache:clear`. Kein Storefront-Build nötig (kein JS geändert).
> **Preisrelevant:** Der Positionspreis ändert sich nicht. **Die Versandkosten können sich ändern** — siehe unten.

### Behoben

- **Reststücke konnten einzeln aus dem Warenkorb gelöscht werden.** Jedes Teilstück war eine eigene Position mit eigenem Löschen-Knopf. Wer bei einer Eingabe von 5 100 mm das Reststück entfernte, behielt kommentarlos 5 000 mm im Warenkorb — bestellte und bezahlte also eine kürzere Länge als eingegeben, und die Fertigung schnitt entsprechend falsch. Ein Zuschnitt-Auftrag ist jetzt genau **eine** Position; die Teilstücke werden als Fertigungsfolge mitgeführt und sind nicht mehr einzeln entfernbar.
- **Fehlermeldung zeigte einen technischen Schlüssel.** Konnte für eine Position kein Meterpreis ermittelt werden, las der Kunde `checkout.rc-dynamic-price-meter-price-unavailable` statt eines Satzes. Der Text war nur unter `error.` hinterlegt, die Flash-Message sucht ihn aber unter `checkout.`. Beide Wege sind jetzt bedient.

### Neu

- **Länge und Aufteilung laufen bis zur Fertigung durch.** Warenkorb, Bestätigungsseite, Kundenkonto, **Bestellbestätigungs-Mail**, **Rechnung und Lieferschein** nennen jetzt die eingegebene Länge und die Aufteilung („Ihre Länge: 5.100 mm — aufgeteilt in 1× 5.000 mm + 1× 1.000 mm"). Bisher stand auf dem Lieferschein nicht, welche Längen zu schneiden sind. Die Mail-Vorlage wird per idempotenter Migration ergänzt; individuell angepasste Vorlagen ohne den Shopware-Default-Anker werden bewusst nicht gepatcht (Anleitung im README).

### Geändert

- **Versandkosten: die längste Einzellänge entscheidet.** Die `DeliveryInformation` einer Position trägt jetzt die längste Teilstücklänge statt der Länge eines einzelnen Teilstücks. Längenbasierte Versandregeln (`cartLineItemDimensionLength`) sahen vorher jedes Teilstück getrennt — ein kurzes Reststück konnte eine günstigere Versandart freischalten, die ohne Split nie verfügbar gewesen wäre. **Für gesplittete Warenkörbe kann der Versand dadurch teurer werden.**
- **`rc_billed_length_mm` bedeutet jetzt die Gesamt-Abrechnungslänge** des Auftrags (Summe der abgerechneten Teilstücke) statt der Länge eines Teilstücks. Wer den Wert außerhalb des Plugins auswertet, muss das nachziehen. Bestandswarenkörbe rechnen unverändert weiter.

## [1.15.0] - 2026-07-11 — Preis-Transparenz, Mengen-Sperre, sichtbare Fehler

> **Deployment:** `bin/build-storefront.sh` erforderlich (JS geändert) + `php bin/console cache:clear`. Keine Migration.
> **Preisrelevant:** Die Vorschau auf der Produktseite zeigt in bestimmten Konfigurationen einen höheren — und damit korrekten — Preis als bisher. Der im Warenkorb berechnete Preis ändert sich nicht.

### Behoben

- **Preisvorschau rechnete auf der Eingabe statt auf den Teilstücken.** Die Produktseite berechnete den Vorschaupreis aus der gerundeten Eingabelänge, während der Warenkorb jede Teilposition einzeln rundet. Sobald die maximale Teilstücklänge oder die Mindestlänge kein Vielfaches der Rundungsstufe war, zeigte die Vorschau dadurch einen zu niedrigen Preis. Die Vorschau summiert jetzt über die Teilstücke — identisch zur Berechnung im Warenkorb.
- **Menge einer Meterposition wird serverseitig auf 1 gesetzt.** Das Eingabefeld sendet immer die Menge 1; über die Store-API oder einen manipulierten Request konnte eine höhere Menge durchrutschen. Beim Aufteilen behielt die Ausgangsposition diese Menge, die entstehenden Teilpositionen nicht — der Warenkorbpreis passte dann nicht zur bestellten Ware. Ein zweiter Eintrag derselben Länge erhöht die Menge weiterhin regulär.

### Neu

- **Mehrlänge wird vor dem Warenkorb ausgewiesen.** Liegt das Reststück unter der Mindestlänge, wird es darauf angehoben — der Kunde zahlt dann mehr, als er eingegeben hat. Die Produktseite nennt das jetzt vorab mit Grund und Betrag, statt es erst im Warenkorb sichtbar werden zu lassen. Die Anhebung selbst bleibt unverändert.
- **Nicht berechenbare Meterpositionen blockieren die Bestellung.** Bisher fielen sie still auf den Grundpreis des Artikels zurück, sodass zu einem Preis bestellt werden konnte, der die Länge ignoriert. Jetzt erscheint eine Fehlermeldung im Warenkorb und die Bestellung ist gesperrt, bis die Ursache behoben ist. Neuer Metrik-Zähler `cart.meter_item.rejected`.

## [1.14.1] - 2026-07-10 — Code-Pflege

> **Deployment:** `php bin/console cache:clear`. Keine Migration, kein Storefront-Build nötig. **Keine Verhaltens- oder Preisänderung.**

### Geändert

- Kommentare und Docblocks in `src/` und `tests/` sprachlich vereinheitlicht. Keine Logik-Änderung.

### Neu

- `SourceUmlautCleanlinessTest` sichert die Schreibweise in Quelldateien gegen Rückfall ab.

## [1.14.0] - 2026-07-10 — HintModal-Extraktion + optionale Metriken

> **Deployment:** `bin/build-storefront.sh` erforderlich (JS geändert) + `php bin/console cache:clear`. Keine Migration. **Keine Preisänderung** — Metriken sind standardmäßig aus.

### Behoben

- **Rundungs-Hinweis blieb stehen.** Nach einer aufgerundeten Eingabe (1.234 mm → 2.000 mm) blieb der Hinweistext sichtbar, sobald danach eine glatte Länge wie 3.000 mm eingegeben wurde. Der Hinweis liegt in einer `aria-live`-Region und meldete Screenreadern damit eine falsche Länge. Er wird jetzt zurückgesetzt.
- **Hinweis-Dialog gehärtet.** Der Dialog wurde per `innerHTML` aus Strings zusammengesetzt, wobei die `titleId` ungeprüft in den `id`-Attributkontext floss. Der Aufbau läuft jetzt über `createElement`/`textContent`/`setAttribute`; `innerHTML` entfällt.

### Geändert (Architektur-Refactor, kein Funktions-Impact)

- **Modal-Logik in eigene Klasse `HintModal` extrahiert** (`src/Resources/app/storefront/src/util/hint-modal.js`). Das Storefront-Plugin nutzt sie jetzt per Komposition. Methoden: `open()`, `close()`, `setupFocusTrap()`, `applyThemeVariables()`. Verhalten (Focus-Trap, Escape-Schließen, Theme-Variablen, Fokus-Rückgabe) ist identisch zur bisherigen Inline-Implementierung; die Klasse ist isoliert testbar und für andere Plugins wiederverwendbar.
- Neuer Node-Test `tests/Js/hint-modal.test.mjs` (zero-dependency, node:test, eigener Fake-DOM) für Aufbau, ARIA, Schließen-Pfade, Focus-Trap, Theme-Variablen und XSS-Schutz.

### Neu (optionale Observability, default aus)

- **`MetricsRecorderInterface`** (`increment`/`timing`) mit fail-safe Vertrag. Default ist der **`NullMetricsRecorder`** (No-Op).
- **Hook-Punkte** (nicht-invasiv, keine Änderung an der Rechenlogik): Counter in `DynamicPriceProcessor::process()` (verarbeitete Meterposition), Timing in `MeterProductHelper::roundUp()` (Rundungsdauer), Vorschau-Counter im `ProductPageSubscriber` (eingeblendetes Widget).
- **Aktivierbarer `LoggingMetricsRecorder`-Decorator**: transparenter Pass-through, der nur bei aktivem Toggle zusätzlich in den Logkanal `rc_dynamic_price` schreibt. Da er die Interface-Service-ID ersetzt, liegt er auch bei ausgeschaltetem Toggle im Hot-Path; der Toggle wird deshalb **pro Instanz genau einmal** aus der Config gelesen und zwischengespeichert. Nach außen bleibt das Verhalten identisch zum NullRecorder.
- Neue Plugin-Config-Karte „Observability (Metriken)" mit Schalter **`enableMetrics`** (Default `false`). README-Abschnitt „Metriken (optional)" ergänzt.

### Tests und CI

- `phpunit.xml.dist` scheitert jetzt an Warnings, Deprecations, Notices und Risky-Tests statt sie durchzuwinken.
- Die CI führt die JS-Tests aus (`composer test:js`, feste Node-Version). Zuvor liefen sie in keiner Pipeline.
- `composer test:js` erfasst `tests/Js/*.test.mjs` per Glob statt per handgepflegter Dateiliste.
- `tests/Js/hint-modal.test.mjs` lief unter Windows nicht (absoluter Pfad an `import()` statt `file://`-URL).

## [1.13.0] - 2026-06-28 — equal-Modus: Längen-Abrechnung konfigurierbar

> **Deployment:** `php bin/console plugin:update RcDynamicPrice && php bin/console cache:clear` + Storefront-Asset-Build (Deploy-Pipeline).
> **Standardverhalten unverändert:** Die neuen Defaults bilden das bisherige Live-Verhalten ab — ohne Konfigurationsänderung ändert sich nichts an den berechneten Preisen.

### Neu (konfigurierbar, Geld-Policy)

- **equal-Modus: Abrechnungslänge wählbar (#4).** Neue Plugin-Einstellung „Berechnete Länge der Teilstücke":
  - **„Schnittlänge (aufgerundet)" — Standard:** wie bisher; jedes Teilstück wird (gemäß Rundungsmodus) aufgerundet, die Stück-Summe darf die Eingabe übersteigen. Der Shop berechnet die tatsächlich geschnittene Länge (z.B. 10001 mm, max 5000 → 3×3334). Verkäufer-schützend.
  - **„Exakte Bestelllänge":** die Teilstücke summieren sich genau zur Eingabe (10001 mm → 3334+3334+3333); der Kunde zahlt die bestellte Länge, der Shop trägt den Verschnitt.
- **equal-Modus: Mindestlänge erzwingen wählbar (#3).** Neue Einstellung „Teilstücke auf Mindestlänge anheben" (Standard: an). Fällt ein Teilstück unter die Mindestlänge, wird es auf minLength angehoben und so berechnet (der Shop muss ohnehin ein Mindeststück schneiden). Abschaltbar, dann bleiben kürzere Stücke erhalten.
- Beide Einstellungen gelten nur für den Modus „Gleichmäßig aufteilen"; „Volle Stücke plus Rest" ist unberührt. Ausführliche Erklärung mit Rechenbeispiel direkt im Backend-HelpText.

### Behoben

- **Deterministische Primär-Kategorie (#6).** Die Konfigurations-Vererbung nutzt jetzt die händler-gepflegte Hauptkategorie (`mainCategories`) des Sales Channels, sonst die kleinste Kategorie-ID (sortiert) statt einer beliebigen. Mehr-Kategorie-Produkte erben dadurch stabil dieselbe Config — keine schwankenden Preise mehr je nach DAL-Ladereihenfolge.
- **Vorschau/Cart-Parität:** Die Storefront-JS-Vorschau (`_previewSplit`) spiegelt beide neuen Optionen — die angezeigte Stückelung entspricht exakt dem im Warenkorb berechneten Preis (JS-Parity-Test gegen `split-cases.json`, beide Modi + Mindestlänge, grün).

### Tests (Resilienz)

- Neuer `CartProcessorOrderingTest` sichert die geld-kritische `shopware.cart.processor`-Priorität (4950) gegen Drift ab: er liest die `services.xml` und prüft, dass der Processor weiterhin zwischen Produktpreis (5000) und Promotions (4900) läuft. Schützt den Skonto-Fix aus v1.8.1 gegen versehentliche Reihenfolge-Änderungen.

### Offen (noch fachliche Entscheidung nötig)

- #1 Quantity>1 im Split-Pfad (latent), #2 max_rest-Rest-Anhebung auf minLength (Transparenz vor `add`), #5 verworfene Positionen als sichtbarer `CartError`.

## [1.12.1] - 2026-06-27 — Robustheit

> **Deployment:** `php bin/console cache:clear`. Keine Migration.

### Behoben

- **Add-to-Cart bricht nicht mehr mit 500 bei Fehlkonfiguration:** Wirft der Längen-Splitter bei einer ungültigen Konfiguration (z. B. `maxLength` jenseits der Splitter-Obergrenze), wird die Position jetzt ohne Split hinzugefügt und der Fehler strukturiert geloggt — statt den Warenkorb-Vorgang abzubrechen.
- Irreführenden Marker-Kommentar im `LineItemSubscriber` korrigiert (tatsächliche Fremd-Marker sind `rcTmmsActive`/`rcCustomFieldsActive`).

### Hinweis

- Mehrere Punkte zur Splitting- und Preis-Transparenz (Rest-Anhebung über die Eingabelänge, Rundung im equal-Modus, stille Verwerfung) bleiben **bewusst unverändert**: sie betreffen das Geld-Verhalten im Live-Shop und brauchen eine fachliche Entscheidung.

## [1.12.0] - 2026-05-13 (Cleanup-Release)

> **Deployment:** `php bin/console cache:clear`. Kein Storefront-Build, keine Migration.

### Entfernt

- **`VariantTagInheritanceProcessor` wieder entfernt** (war v1.11.0–v1.11.2). Diagnose-Iterationen hatten den Verdacht, dass Varianten Parent-Tags nicht erben — Shopwares `ManyToManyIdField` mit `Inherited()`-Flag erledigt das in Wahrheit korrekt: das `tagIds`-Payload der Variante enthält den Parent-Tag bereits, sobald die Variante über den Sales-Channel-Context geladen wird. Der eigene Processor war redundant.
- **Sämtliche Diagnose-Debug-Logs aus v1.10.1–v1.11.2 entfernt**: keine `file_put_contents`-Traces zu `/tmp/rc-*.log`, keine WARNING-Level-Loop-Logs vor dem Filter. Production-Code wieder schlank.

### Behoben (durch Live-Diagnose, bleibt aus v1.10.0)

- **`DeliveryInformation.length` wird im `DynamicPriceProcessor` auf die berechnete `billedLength` gesetzt.** Damit greifen Shopware-Standard-Rules `LineItemDimensionLengthRule`, `LineItemDimensionVolumeRule` und tag-/längenbasierte Versandkostenregeln auch für die im Storefront eingegebene Länge — sonst lesen sie die Stammdaten-Länge (oft 0 oder null).
- **Logger-Channel auf Standard zurückgestuft** (aus v1.10.2): alle Services nutzen `monolog.logger`. Vorher verwies die Service-XML auf einen `monolog.logger.rc_dynamic_price`-Channel ohne Handler-Routing — Logs verschwanden ins Nichts.

### Lessons aus der Live-Diagnose (Wichtig für künftige Versandkostenregeln)

- Die häufigste Ursache für „Versandregel matcht nicht trotz korrekter Bedingungen": die **Versandart ist nicht dem Sales Channel zugewiesen** (`sales_channel_shipping_method`-Tabelle). Vor jeder Versandregel-Analyse Sales-Channel-Zuweisung prüfen.
- Wenn mehrere Versandarten matchen, gewinnt die mit niedrigster Position bzw. die Default-Versandart vom Sales Channel. Konkurrierende Versandarten brauchen wechselseitige Ausschluss-Bedingungen (`NICHT Tag X`).

## [1.11.1] - 2026-05-13

> **Deployment:** `php bin/console cache:clear`. Kein Storefront-Build.

### Geändert

- **`VariantTagInheritanceProcessor` liest Parent-Tags jetzt per DBAL-Connection direkt aus `product_tag`** statt über die `product.repository`. Hintergrund: Shopwares `ManyToManyIdField` für `tagIds` ist mit `Inherited()` markiert — ein DAL-Read mit `Context::createDefaultContext()` (kein Sales-Channel-Context) lieferte die Tags nicht zuverlässig. Der Direct-DB-Lookup umgeht alle DAL-Quirks und nimmt die `product_tag`-Tabelle als Source of Truth. Pro Parent ein Row-Set, alle Parents eines Carts in EINER Query (`WHERE product_id IN (?)`).
- 7 Unit-Tests an Connection-Mock angepasst.
- Diagnose-WARNING-Log bei Parent-Tag-Lookup (gibt Parent-Anzahl + gefundene Tag-Rows aus) — wird im prod-Log sichtbar. Nach erfolgreicher Verifikation in v1.11.2 reduziert.

## [1.11.0] - 2026-05-13

> **Deployment:** `php bin/console cache:clear`. Kein Storefront-Build.

### Hinzugefügt

- **Neuer `VariantTagInheritanceProcessor`.** Ergänzt das `tagIds`-Payload von Varianten-LineItems automatisch um die Tag-IDs des Parent-Produkts. Shopwares `ProductCartProcessor` schreibt nur die direkten Tags der Variante — Tags, die ausschließlich am Parent gepflegt sind (häufiges Setup für Konfigurations-Tags wie „Bodenprofil"), fehlten damit im Payload und Standard-Rules wie `cartLineItemTag` für tag-basierte Versandkostenregeln matchten nie. Der Processor läuft mit Priority 4990 — also nach `ProductCartProcessor` (5000) und vor `DynamicPriceProcessor` (4950). Parent-Tag-Lookups werden in `CartDataCollection` gecacht (ein Repository-Hit pro Parent pro Cart-Recalc).
- **7 Unit-Tests** für `VariantTagInheritanceProcessor`: kein-Parent-Skip, Parent-Tags-Übernahme, Existing-Tag-Merge, Deduplizierung, leeres Parent-Tag-Array, einmaliger Lookup für mehrere Variants desselben Parents, leerer Cart.

### Entfernt

- **Diagnose-Debug-Logs aus den Zwischen-Patches 1.10.1–1.10.4 wieder entfernt** (`file_put_contents` zu `/tmp/rc-dynamic-debug.log`, WARNING-Level-Loops). Diese waren reine Live-Diagnose-Hilfen und nicht für Produktion gedacht. Production-Code wieder schmal.

## [1.10.2] - 2026-05-13

> **Deployment:** `php bin/console cache:clear`.

### Behoben

- **Logger-Channel auf Standard zurückgestuft.** Bisher referenzierten alle Service-Definitionen den Channel `monolog.logger.rc_dynamic_price` — Symfony erstellt für solche dynamisch generierten Channel-Services automatisch einen Logger, aber **ohne Handler-Routing** landen alle Log-Einträge im Nichts. Konsequenz: weder die WARNING-Pfade des `DynamicPriceProcessor` (ungültige Länge, Min/Max-Verletzung, fehlender Preis) noch der neue INFO-Log aus v1.10.1 waren in `var/log/prod-*.log` sichtbar. Live-Diagnose war damit blind. Fix: alle drei Service-Argumente in `services.xml` von `monolog.logger.rc_dynamic_price` auf `monolog.logger` (Standard-Channel) umgestellt — Logs landen jetzt regulär im Production-Log.
- Wenn später ein dedizierter Channel mit eigenem Handler gewünscht ist, muss eine `Resources/config/packages/monolog.yaml` mit Channel-Definition und Handler-Routing ergänzt werden.

## [1.10.1] - 2026-05-13

> **Deployment:** `php bin/console cache:clear`.

### Diagnose

- **Info-/Warning-Log im `DynamicPriceProcessor`** rund um das Setzen der `DeliveryInformation.length`. Live wurde beobachtet, dass die längenbasierte Versandregel trotz v1.10.0 nicht greift — die neuen Log-Einträge geben Aufschluss, ob der Setter überhaupt durchläuft, was die vorherige Länge war und welcher Wert gesetzt wurde. Per `tail -f var/log/prod-*.log | grep RcDynamicPrice` direkt im Live-Betrieb sichtbar.
- Kein Verhaltenswechsel — nur zusätzliches Logging. Wird nach abgeschlossener Diagnose in v1.10.2 auf `debug`-Level zurückgestuft oder entfernt.

## [1.10.0] - 2026-05-13

> **Deployment:** `php bin/console cache:clear`. Keine Migration, kein Storefront-Build — reine Cart-Processor-Erweiterung.

### Hinzugefügt

- **Tatsächliche Position-Länge landet jetzt in `DeliveryInformation.length`.** Bisher schrieb der `DynamicPriceProcessor` die berechnete (ggf. gerundete) Länge nur in den LineItem-Payload (`PAYLOAD_BILLED_LENGTH_MM`). Shopwares Standard-Rules `LineItemDimensionLengthRule` und `LineItemDimensionVolumeRule` sowie längenbasierte Versandkostenregeln lesen aber aus `lineItem->deliveryInformation->length`. Folge: eine Regel wie „Lieferadresse Deutschland UND Position mit Tag Bodenprofil UND Position mit Länge: alle kleiner 2000 mm" griff nicht, obwohl der Kunde z. B. 800 mm im Storefront eingegeben hatte. Fix: nach dem Setzen des Payload-Werts wird der berechnete `billedLength` zusätzlich in die vorhandene `DeliveryInformation` geschrieben. Damit greifen alle Shopware-Standard-Rules ohne weiteren Eingriff.
- **Split-Positionen verhalten sich konsistent.** Da der Processor mit Priorität `4950` nach dem Cart-Splitter läuft, bekommt jede Split-Position ihre individuelle `billedLength` auch in der `DeliveryInformation`. Ein „2 × 3 m"-Cart greift damit korrekt eine Regel wie „alle Längen ≤ 6000 mm".

### Tests

- 3 neue Tests im `DynamicPriceProcessorTest`: einfache Längenübernahme, gerundete Längen, robuster Umgang mit fehlender `DeliveryInformation`.
- Gesamt 194 Tests grün.

## [1.9.0] - 2026-05-11

> **Deployment:** `bin/build-storefront.sh` (Twig + JS + SCSS geändert) + `php bin/console cache:clear`. Keine Datenbank-Migration.

### Behoben (BFSG / Accessibility)
- **Rundungs-Hinweis hatte falsche ARIA-Semantik (WCAG 4.1.3).** `_showRoundUpHint()` schrieb den informativen Text bisher in den Error-Container (`role="alert"` + `aria-live="assertive"`) und tauschte die CSS-Klasse von `text-danger` auf `text-info`. Screenreader unterbrachen dadurch den Lesefluss für eine bloße Status-Bestätigung. Fix: neue dedizierte Region `rc-length-info-{productId}` mit `role="status"` + `aria-live="polite"` im Twig; JS schreibt dort hinein. Error-Container bleibt reserviert für Validierungsfehler.
- **Unit-Span `mm` war screenreader-unsichtbar (WCAG 1.3.1).** `<span class="input-group-text" aria-hidden="true">mm</span>` war für Screenreader-Nutzer komplett verborgen. Fix: `aria-hidden` entfernt, Span bekommt eine ID und wird in der `aria-describedby`-Liste des Inputs aufgenommen. Screenreader liest jetzt „Länge … mm" korrekt zusammenhängend.
- **Focus-Trap im Hinweis-Modal war nicht robust gegen Erweiterungen.** `onTrapFocus` fing jede Tab-Taste und sprang fest auf den Close-Button — funktionierte nur, weil das Modal genau einen fokussierbaren Knoten enthielt. Fix: Trap ermittelt jetzt erste und letzte fokussierbaren Knoten (`a[href]`, `button:not([disabled])`, `[tabindex]:not([tabindex="-1"])`, etc.) und navigiert via Shift+Tab/Tab korrekt zwischen ihnen. Bestehende Mono-Button-Setups verhalten sich unverändert; künftige Links im Hinweistext bleiben tab-fähig.
- **Modal-Hintergrund/Vordergrund nutzte Hard-Coded Hex-Werte.** `.rc-dynamic-price-modal` hatte `background: #fff` fest verdrahtet und brach in Dark-Themes optisch. Fix: `var(--bs-body-bg, #fff)`, `var(--bs-body-color, #212529)` und `var(--bs-border-color, …)` als Theme-Variablen mit Fallback-Werten.

### Hinzugefügt
- **PHP↔JS-Splitter-Parität ist jetzt verankert.** Neuer Vertrags-Test `tests/Js/dynamic-price.split-parity.test.mjs` extrahiert die JS-Klasse aus der Plugin-Quelle und ruft `_previewSplit()` für jeden Fall der gemeinsamen Fixture `tests/Fixtures/split-cases.json` auf — dieselbe Fixture, die der PHP-`LengthSplitterTest::testMatchesSharedFixture` konsumiert. Drift zwischen `LengthSplitter::split` (PHP) und `_previewSplit` (JS) wird ab sofort sofort rot — kein silent Mismatch in der Preview mehr möglich. `composer test:js` läuft ihn automatisch mit.
- **`StorefrontResponseSubscriberTest`** (Unit-Test) sichert das Cache-Tag-Anhängungs-Verhalten (`sw-cache-tags`-Header) ab: korrekte Event-Prioritäten, Merge mit bestehenden Tags ohne Duplikate, Ignorieren von Nicht-String- und Non-Array-Attributen, no-op bei fehlenden Tags. Acht neue Tests gegen die Header-Sicherheit.

### Hinweis für Integrationen
- Templates oder externe JS-Erweiterungen, die direkt auf `_errorEl` schreiben oder die CSS-Klasse `text-info` am Error-Container erwarten, brechen nicht — `_errorEl` bleibt im DOM, wird aber nicht mehr für Rundungs-Hinweise befüllt. Lesepfad bleibt kompatibel.

## [1.8.1] - 2026-05-11

> **Deployment:** `php bin/console cache:clear` (Container-Rebuild für neue Cart-Processor-Priorität). Keine Datenbank-Migration, kein Storefront-Build.

### Behoben
- **Kritisch: Prozentuale Promotions (z. B. 3-%-Skonto) wirkten nur auf den Meter-Stückpreis statt auf den ausmultiplizierten Positions-Preis.** Beispiel vor Fix: Meterpreis 10 €, Länge 3 m, 3 %-Skonto → 29,70 € statt erwarteter 29,10 €. Ursache: `DynamicPriceProcessor` lief mit Cart-Processor-Priorität `4800` **nach** dem Shopware-`PromotionProcessor` (`4900`). Die Promotion bezog den Rabatt deshalb auf den noch nicht multiplizierten Stückpreis (10 €), während der `DynamicPriceProcessor` den Positions-Preis (30 €) erst danach schrieb. Fix: Priorität auf `4950` angehoben — der Processor läuft jetzt zwischen `ProductCartProcessor` (5000) und `PromotionProcessor` (4900). Prozentuale und absolute Discount-Promotions mit LineItem-Scope rechnen damit korrekt gegen den fertigen Positions-Preis.
- **Security: HIGH-Severity-CVE-2026-44167** in `phpseclib/phpseclib` (OID-Amplification-DoS in `ASN1::decodeOID()`) durch Lockfile-Update von `3.0.51` auf `3.0.52` adressiert. Composer-Audit (prod + dev) ist wieder clean.

### Geändert
- README um Abschnitt „Promotions und Skonto" ergänzt: dokumentiert die Pipeline-Reihenfolge und gibt einen Hinweis für Rule-Builder-Promotions, die direkt auf Meterpreis-Custom-Fields referenzieren.

### Hinzugefügt
- Pinning-Test `DynamicPriceProcessorPriorityTest` (Unit): hält die Cart-Processor-Priorität exakt auf `4950` und verifiziert das gültige Intervall zwischen Product- und Promotion-Processor. Eine versehentliche Drift wird sofort rot.
- Pipeline-Test `DynamicPricePromotionOrderIntegrationTest` (Integration): simuliert mit echten Shopware-Core-Calculatoren (`QuantityPriceCalculator`, `PercentagePriceCalculator`) die korrekte Reihenfolge und prüft mehrere Längen-/Stückpreis-Kombinationen.

## [1.8.0] - 2026-04-30

> **Deployment:** `bin/build-storefront.sh` (JS geändert). Keine Datenbank-Migration.

### Geändert
- **Listener-Symmetrie:** `_registerEvents` hört jetzt auf das generische `rcSuffixChanged`-Event statt nur auf das plugin-spezifische `rcColorPickerChanged`. Neuer Handler `_onForeignSuffixChanged(event)` mit Self-Loop-Guard via `event.detail?.source === 'rcDynamicPrice'` (RcDynamicPrice feuert das Event auch selbst — ohne Filter Endlosschleife). Konsequenz: Längen-Re-Berechnung greift künftig auf jedes Sibling-Plugin, das den Protokoll-Vertrag erfüllt — nicht mehr nur auf RcColorPicker.
- Listener wird jetzt sauber im `destroy()` abgemeldet (vorher Anonymous-Handler ohne Cleanup-Pfad).

### Hinzugefügt
- Vier Unit-Tests in `tests/Js/dynamic-price.listener.test.mjs`: Self-Source-Filter, Fremd-Source-Trigger, leerer/ungültiger `hidden.value`, defensives Detail-Default.

## [1.7.1] - 2026-04-30

> **Deployment:** keine Datenbank-Migration, kein Build-Schritt nötig — Test- und Tooling-Änderung.

### Hinzugefügt
- Vertrags-Test `tests/Js/dynamic-price.suffix-event.test.mjs` verankert die statische Konstante `DynamicPricePlugin.SUFFIX_CHANGED_EVENT` (= `rcSuffixChanged`). Ein Wert-Drift bricht ab sofort den Test, statt stumm durchzulaufen.
- `composer test:js` und Aufnahme in `composer quality` ergänzt.

## [1.7.0] - 2026-04-30

> **Deployment:** `bin/build-storefront.sh` (JS geändert). Keine Datenbank-Migration, kein `plugin:update` nötig.

### Hinzugefügt
- **Generisches Suffix-Event** `rcSuffixChanged` wird zusätzlich zum bestehenden `rcMeterLengthChanged` nach jeder Längen-Änderung gefeuert. Damit erfüllt RcDynamicPrice das aktualisierte Plugin-Interaktionsprotokoll: ID-berechnende Sibling-Plugins (RcCartSplitter ab v2.0.0) lauschen nur noch auf den neutralen Event-Namen, kein Plugin owned den Namespace. Event-Konstante als statische `DynamicPricePlugin.SUFFIX_CHANGED_EVENT` exponiert.

### Geändert
- `_updateMeterState` ruft den neuen privaten Helper `_dispatchSuffixChanged(detail)` auf, der beide Events in einer Stelle bündelt (DRY). Plugin-spezifisches `rcMeterLengthChanged` bleibt für bestehende interne Listener verfügbar.

### Hinweis
- Standalone-Setups (RcDynamicPrice ohne RcCartSplitter / RcCustomFields) verhalten sich unverändert: das zusätzlich gefeuerte Event ist ohne Listener ein No-op.

## [1.6.5] - 2026-04-29

> **Deployment:** `php bin/console plugin:update RcDynamicPrice` (führt Heilungs-Migration `1745700000` aus) + `php bin/console cache:clear` + `bin/build-storefront.sh` (JS geändert).

### Behoben
- **Backend-Labels der Splitting- und Kategorie-Custom-Fields zeigen wieder korrekte Umlaute.** Im Shopware-Backend waren die `de-DE`-Labels falsch geschrieben. Die Migration `1745700000FixCustomFieldLabelsUmlauts` heilt bestehende Installationen, indem sie die `config`-JSON-Spalte der betroffenen `custom_field`-Rows neu schreibt. Idempotent — bereits korrigierte Configs werden übersprungen, `en-GB`-Strings bleiben unverändert.
- **Drift-Risiko zwischen serverseitiger und clientseitiger Rundung beseitigt.** Die Rundungs-Tabelle (`none → 0`, `cm → 10`, `quarter_m → 250`, `half_m → 500`, `full_m → 1000`) war in PHP (`MeterProductHelper::ROUNDING_STEPS`) und JS (`dynamic-price.plugin.js::_roundUp()`) doppelt definiert mit „Muss identisch sein"-Kommentaren — ein Kommentar zwingt aber keine Konsistenz. Der Server ist jetzt Single-Source: `MeterProductHelper::ROUNDING_STEPS` ist `public const`, der `ProductPageSubscriber` hängt die Map an `RcDynamicPriceConfigStruct->roundingSteps`, das Twig-Template serialisiert sie als `data-rounding-steps`-JSON, und das JS-Plugin liest sie zur Laufzeit. Bei JSON-Parse-Fehler oder fehlendem Attribut bleibt die Map leer und `_roundUp()` liefert den Eingabewert unverändert — sicheres Fallback.

### Geändert
- **Regression-Guard erweitert:** `AdminLabelCleanlinessTest` prüft `de-DE`-Labels jetzt zusätzlich auf falsche Schreibweise. `en-GB`-Strings bleiben unberührt. Greift für `config.xml` und für die Locale-Maps der Migrations.
- **Test-Suite:** +6 Unit-Tests für die neue Heilungs-Migration (Korrektur, nested Options, Idempotenz, fehlende Felder, kaputtes JSON), +1 Test für `MeterProductHelper::ROUNDING_STEPS`-Konstante, +1 Test für `RcDynamicPriceConfigStruct::getRoundingSteps()`. Insgesamt 172 Unit-Tests + 7 Integration-Tests grün.

## [1.6.4] - 2026-04-23

> **Deployment:** `php bin/console cache:clear` + `bin/build-storefront.sh` (JS geändert). Keine Datenbank-Migration. Bestehende Warenkörbe heilen sich beim nächsten Cart-Zugriff durch den regulären Enrichment-Pass — kein Backfill nötig.

### Behoben
- **Kritisch: Gesplittete Warenkorbpositionen konnten vom Kunden nicht entfernt werden.** `CartItemSplitAssembler::appendSiblingPieces` erzeugte Siblings per `new LineItem(...)` ohne `setRemovable(true)`/`setStackable(true)`. Shopware-Default ist `false`; der reguläre Add-Pfad setzt die Flags im Product-Enrichment, Siblings, die mitten im `BeforeLineItemAddedEvent` per `$cart->add()` eingeschleust werden, erreichen diesen Enrichment-Pass aber nicht zuverlässig — in der Storefront fehlten deshalb X-Button und Mengen-Minus an den Teilstücken, eine manuelle Mengenänderung auf 0 wurde per HTML5-`min="1"` abgelehnt. Der Kunde hatte keinen Weg, Sibling-Positionen zu entfernen. Fix: Flags werden jetzt explizit auf `true` gesetzt. Regression durch zwei Unit-Tests (`Equal`-Split + `MaxRest`-Split) und einen Integrationstest abgesichert.
- **Handshake-Bug im Plugin-Interaktionsprotokoll.** Die ID-Controller-Erkennung funktionierte in keiner Richtung zuverlässig:
    - **Frontend:** `dynamic-price.plugin.js` prüfte per `this._form.querySelector('[data-rc-id-controller]')`. `querySelector` durchsucht nur Nachkommen, nicht das Element selbst — RcCartSplitter setzt den Marker aber auf das Form-Element direkt (`this._form.dataset.rcIdController = 'true'`). RcDynamicPrice erkannte die fremde ID-Hoheit deshalb nicht und überschrieb die Hash-basierte LineItem-ID. Fix: zusätzliche `dataset.rcIdController`-Prüfung vor dem DOM-Query.
    - **Backend:** `LineItemSubscriber::effectiveSplitMode` suchte die Marker-Keys `rcTmmsActive`/`rcCustomFieldsActive` top-level im Request. Die Plugins injizieren die Marker aber genested als `lineItems[{productId}][payload][rcTmmsActive]`. Der Split-Modus-Downgrade auf `Hint` (bei fremder ID-Hoheit) griff deshalb nie. Fix: neue Helper-Methode `hasForeignIdControllerMarker` iteriert `$request->request->all('lineItems')` und prüft die Payload-Ebene; Top-Level-Check bleibt als Legacy-Pfad. Abgesichert durch drei neue Unit-Tests (nested TMMS, nested CustomFields, Negativ-Fall ohne Marker).
- Das Plugin-Interaktionsprotokoll dokumentiert die beiden Prüfebenen (Element-Dataset + Nachkommen-Selector; nested Payload-Lookup) explizit, damit zukünftige Plugins nicht in dieselbe Falle laufen.

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
- `.gitattributes` zwingt LF-Line-Endings auf allen Text-Dateien, damit Windows-Clients (`core.autocrlf=true`) und die CI nicht mehr auseinanderlaufen.

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
- `composer audit` läuft für Prod-Abhängigkeiten strikt, der Vollaudit separat und informativ.
- `composer.lock` wird wieder committed, damit der Lock-Stand zwischen lokal und CI eindeutig ist.

## [1.5.1] - 2026-04-22

> **Deployment:** kein Shop-Deployment nötig (nur Tests + Regel-Doku).

### Hinzugefügt
- Regression-Guard `AdminLabelCleanlinessTest`: prüft `config.xml` (Card-Titles, Labels, HelpTexts, Placeholders, Option-Names) und alle Migration-Label-Maps gegen technische Strings (`Rc `-Prefix, `rc_`-Prefix, `Custom Field`/`Custom Fields`-Platzhalter)

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
- Das Plugin-Interaktionsprotokoll um einen Hinweis zu Requests mit mehreren Positionen beim Auto-Split ergänzt

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
