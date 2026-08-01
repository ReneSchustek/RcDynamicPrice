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

Das vorkompilierte Storefront-JS liegt dem Plugin bei (`Resources/app/storefront/dist`) — auf dem Server ist **kein Node-Build** nötig.

```bash
php bin/console plugin:refresh
php bin/console plugin:install --activate RcDynamicPrice
php bin/console theme:compile
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
| Meterpreis global für alle Produkte aktivieren | Aktiviert den Meterpreis für alle Produkte, die am Produkt auf **Vererben** stehen und deren Kategorie-Kette keinen Override setzt. Produkte mit **Inaktiv** bleiben immer deaktiviert. | aus |

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

| Modus | Eingabe 8 000 mm bei Max-Teilstück 5 000 | Teilstück unter der Mindestlänge |
|-------|------------------------------------------|----------------------------------|
| Gleichmäßig (`equal`) | 2 × 4 000 mm | wird so geschnitten; die Option `equalSplitEnforceMin` entscheidet, ob mit der Mindestlänge **berechnet** wird |
| Volle Stücke + Rest (`max_rest`) | 5 000 + 3 000 mm | wird so geschnitten und mit der Mindestlänge **berechnet** (z. B. 5 100 mm → Schnitt 5 000 + 100 mm, berechnet 6 000 mm) |
| Nur Hinweis (`hint`) | Kein Auto-Split, Hinweistext wird gerendert, Submit blockiert | — |

Die Rundungsstufe wirkt **pro Teilstück** und ebenfalls nur in der Abrechnung. Beispiel: 3 × 4 750 mm
im Modus `Voller Meter` werden als 3 × 5 000 mm berechnet.

### Ein Zuschnitt-Auftrag ist eine Warenkorb-Position

Die Teilstücke sind eine **Fertigungsfolge**, kein Einkauf. Ein Kunde, der 5 100 mm eingibt, bekommt
genau **eine** Position:

```
Set 6 Bodenprofil 2.0 aufgesetzt (Zuschnitt 5.100 mm:      Anzahl 1     1.809,24 €
1× 5.000 mm + 1× 100 mm, berechnet 6.000 mm)
```

Zwei identische Eingaben mergen weiterhin zu einer Position mit Menge n. Der Preis ist die Summe der
abgerechneten Teilstücke — nicht die aufgerundete Eingabelänge.

Bis einschließlich 1.15.0 war jedes Teilstück ein eigenes LineItem. Der Kunde konnte damit ein
Reststück einzeln aus dem Warenkorb löschen und bestellte kommentarlos eine kürzere Länge, als er
eingegeben hatte. Das ist strukturell nicht mehr möglich.

**Versandkosten (wichtig):** Die `DeliveryInformation` einer Position trägt die **längste
Einzellänge**, nicht die Gesamtlänge. Längenbasierte Versandregeln (`cartLineItemDimensionLength`)
sehen damit das längste zu versendende Stück. Vorher sah jede Regel jedes Teilstück einzeln — ein
kurzes Reststück konnte eine günstigere Versandart freischalten, die es ohne Split nie gegeben hätte.
Wer solche Regeln nutzt, prüft nach dem Update die Versandart für gesplittete Warenkörbe.

### Die Länge steht im Positionsnamen

Länge und Aufteilung sind Teil des **Positionsnamens** — er nennt die Schnittlängen und ist damit die
Fertigungsanweisung:

```
Set 6 Bodenprofil 2.0 aufgesetzt (Zuschnitt 5.100 mm: 1× 5.000 mm + 1× 100 mm, berechnet 6.000 mm)
```

Damit erscheinen sie überall, wo Shopware eine Position ausgibt — Warenkorb, Bestellbestätigung,
Rechnung, Lieferschein, Kundenkonto und **Admin-Bestellansicht** — ohne dafür ein Template zu
überschreiben. Entscheidend ist der letzte Punkt: Der Name ist auch das Einzige, was eine
**Warenwirtschaft** übernimmt. Der ERP-Connector für orgaMAX (`DeltraShopConnector6`) etwa
überträgt je Position nur Artikelnummer, Menge, Preis und den Namen (als `abweichenderArtikeltext`);
den LineItem-Payload liest er nicht. Stünde die Länge nur dort, wüsste die Fertigung aus dem ERP
nicht, welche Stücke zu schneiden sind.

Zusammengesetzt wird der Name im `DynamicPriceProcessor` aus den Snippets `labelWithDetails`,
`labelLength`, `labelSplit`, `labelPiece` und `labelBilled`. Der unveränderte Produktname liegt im
Payload (`rc_base_label`); der Name wird bei jeder Neuberechnung des Warenkorbs **daraus neu
gebildet**, nie angehängt — sonst stünde der Zusatz nach dem dritten Durchlauf dreimal da.

Ob ein Split vorliegt, entscheidet die Zahl der **Teilstücke** (`rc_billed_pieces`), nicht die der
Anzeigegruppen: Drei gleich lange Stücke ergeben nur eine Gruppe, sind aber sehr wohl ein Split.

Die Migration `Migration1784000000RemoveMeterLengthFromOrderConfirmationMail` entfernt den früher in
die Mail-Vorlagen geschriebenen Längen-Block wieder, weil die Angabe sonst doppelt erschiene. Wurde
dieser Block im Shop von Hand verändert, findet die Migration ihn nicht wortgleich und lässt die
Vorlage unangetastet — dort steht die Länge dann zweimal, und der Block gehört von Hand entfernt.

### Schnittlänge und Abrechnungslänge sind zweierlei

Die **Mindestlänge ist eine Abrechnungsregel**, keine Fertigungsregel: „Ein Zuschnitt kostet
mindestens X mm." Sie verlängert das Werkstück nicht.

Bestellt ein Kunde 5.100 mm bei maximaler Stücklänge 5.000 mm und Mindestlänge 1.000 mm:

| | |
|---|---|
| **Schnitt** (Fertigung, Lieferschein, Positionsname) | 5.000 + **100** mm — genau die bestellte Länge |
| **Abrechnung** (Preis) | 5.000 + **1.000** mm = 6.000 mm |

Der `LengthSplitter` liefert ausschließlich Schnittlängen; die Anhebung auf die Mindestlänge und die
Rundung passieren im `DynamicPriceProcessor`, wo der Preis entsteht. Im Modus „Gleichmäßig aufteilen"
entscheidet `equalSplitEnforceMin`, ob kurze Teilstücke mit der Mindestlänge berechnet werden — im
`max_rest`-Modus gilt sie immer.

### LineItem-Payload

| Key | Bedeutung |
|-----|-----------|
| `meterLengthMm` | vom Kunden eingegebene Gesamtlänge des Auftrags |
| `rc_split_pieces` | **Schnittlängen** — was die Fertigung schneidet |
| `rc_min_billing` | werden kurze Teilstücke mit der Mindestlänge abgerechnet? |
| `rc_billed_pieces` | abgerechnete Teilstücke (auf die Mindestlänge angehoben, dann einzeln aufgerundet) |
| `rc_billed_length_mm` | **Gesamt**-Abrechnungslänge = Summe der abgerechneten Teilstücke |
| `rc_max_piece_length_mm` | längste abgerechnete Einzellänge — speist die `DeliveryInformation` |
| `rc_split_summary` | gruppierte **Schnittlängen** für den Positionsnamen (`[{length, count}, …]`) |

`rc_billed_length_mm` bezeichnete vor diesem Umbau die Länge **eines Teilstücks**. Wer den Wert
außerhalb des Plugins auswertet (Export, Reporting), muss das nachziehen. Positionen aus
Bestandswarenkörben tragen die neuen Keys nicht; sie werden weiter mit ihrer bisherigen Länge und zum
bisherigen Preis berechnet.

## Backend-Sprache

Die Plugin-Konfiguration im Admin und die Custom-Fields am Produkt folgen beide der Admin-User-Locale:

1. Admin-User-Locale (z. B. `de-DE`)
2. System-Default-Locale
3. `en-GB` (Shopware-Fallback)

Wer die Ausgabesprache umschalten möchte, ändert die eigene Admin-User-Sprache (Rechts oben → Nutzerprofil → Sprache). Das Plugin pflegt aktuell `de-DE` und `en-GB`. Weitere Sprachen müssen im Schema und in allen Migrations gleichzeitig ergänzt werden, sonst fällt das Backend still auf einen der gepflegten Locales zurück.

## Promotions und Skonto

Der `DynamicPriceProcessor` ist mit der Cart-Processor-Priorität `4950` zwischen Shopware-`ProductCartProcessor` (5000) und `PromotionProcessor` (4900) eingehängt. Prozentuale Rabatte (Skonto, Sonderaktionen) und Absolut-Rabatte mit LineItem-Scope wirken dadurch auf den **ausmultiplizierten Positions-Preis** (Meterpreis × Länge), nicht auf den ungewichteten Meter-Stückpreis.

Beispiel: Meterpreis 10 €, Länge 3 m, 3-%-Skonto → Positions-Preis 30 €, Rabatt 0,90 €, Endpreis 29,10 €.

Wer eine Promotion über den Rule-Builder direkt auf das Meterpreis-Custom-Field (`customFields.rc_meter_price_*`) referenziert, sollte die Regel auf einen LineItem- oder Cart-Scope umstellen, damit der Discount auf den fertig berechneten Positions-Preis greift.

## Metriken (optional)

Das Plugin bietet eine optionale, standardmäßig deaktivierte Observability-Schnittstelle (`MetricsRecorderInterface`). Ohne Aktivierung entsteht **kein Mehraufwand** — der Default ist der `NullMetricsRecorder` (No-Op).

Erfasste Kennzahlen (an den Hot-Paths):

| Schlüssel | Typ | Wann |
|-----------|-----|------|
| `cart.meter_item.processed` | Counter | je erfolgreich im Warenkorb berechneter Meterposition (Tag: `rounding`) |
| `rounding.duration_ms` | Timing | Dauer der Rundungs-Arithmetik (Tag: `mode`) |
| `product_page.meter_widget.shown` | Counter | je auf der Produktseite eingeblendetem Meter-Widget |

**Aktivierung:** Plugin-Konfiguration → Karte „Observability (Metriken)" → *Metriken protokollieren* einschalten. Dann schreibt der mitgelieferte `LoggingMetricsRecorder` die Kennzahlen in den Plugin-Logkanal `rc_dynamic_price`. Der Recorder ist fail-safe: ein Fehler beim Loggen beeinflusst weder Warenkorb noch Seite.

**Eigener Adapter (z. B. StatsD):** Eine eigene Klasse implementiert `MetricsRecorderInterface` und wird per Service-Decoration anstelle des `LoggingMetricsRecorder` eingehängt — der Hot-Path-Code bleibt unverändert.

## Deployment

| Änderung | Befehl |
|----------|--------|
| Nur PHP / Twig | `php bin/console cache:clear` |
| SCSS geändert | `php bin/console theme:compile` |
| JS / main.js geändert | `bin/build-storefront.sh` **auf einem Host mit Node**, danach das neu gebaute `Resources/app/storefront/dist` einchecken (der Gate-Schritt „dist-Frische" prüft das). Auf dem Zielshop genügt `theme:compile`. |

Siehe CHANGELOG.md für den Deployment-Hinweis pro Version.

## Update / Live-Gang

Beim Update auf eine neue Plugin-Version gelten diese Schritte pro Shop-Instanz. **Die Reihenfolge ist nicht optional** — `plugin:update` ohne anschließenden `cache:clear` lässt neue DI-Services (z. B. den Monolog-Channel seit 1.6.0) nicht auflösen und produziert `ServiceNotFoundException` beim ersten Request.

### Pflicht-Sequenz

```bash
# 1. Sicherheitsnetz: DB-Snapshot vor jedem Minor- oder Major-Sprung
mysqldump "$DATABASE_URL" > backup_pre_<neue-version>.sql

# 2. Neue Plugin-Version ziehen
composer require ruhrcoder/rc-dynamic-price:^<neue-version>
php bin/console plugin:refresh

# 3. Migrations und Container-Cache — zwingend in dieser Reihenfolge
php bin/console plugin:update RcDynamicPrice
php bin/console cache:clear

# 4. Theme/Storefront-Assets — das vorkompilierte JS liegt bei, kein Node-Build nötig
php bin/console theme:compile

# 6. HTTP-Cache verwerfen — greift auch Cache-Tag-Schema-Änderungen ab 1.5.0
php bin/console http:cache:clear
```

### Staging-Smoke-Test

Vor dem Produktions-Rollout auf einer Staging-Instanz durchspielen:

- [ ] Admin-Backend lädt, Plugin-Config öffnet ohne Exception
- [ ] Produkt-Edit → Individuelle Felder → Meterpreis: erwartete Optionen vorhanden (ab 1.5.x: Vererben / Aktiv / Inaktiv)
- [ ] Storefront-PDP eines aktiven Meterproduktes lädt, Längeneingabe funktioniert, Live-Preis aktualisiert
- [ ] Cart: Längen-LineItem mit korrektem Preis; bei aktiviertem Splitting entstehen Sibling-Positionen
- [ ] Screenreader (ab 1.6.0): Hinweis-Modal hat Fokus beim Öffnen, Escape schließt, Fokus kehrt zum Input zurück; Validierungsfehler werden angesagt
- [ ] Logs: Plugin-Events erscheinen unter dem Channel `rc_dynamic_price` (ab 1.6.0)

### Was pro Versions-Sprung neu ist

| Sprung | Neu | Zusätzliche Schritte / Besonderheiten |
|--------|-----|---------------------------------------|
| 1.4.x → 1.5.0 | Tri-State-`rc_meter_price_active`, Kategorie-CustomFieldSet, Cache-Tag-Schema | `plugin:update` führt zwei Migrations aus; HTTP-Cache verwerfen. **Breaking:** Integrationen mit `customFields.rc_meter_price_active === true` müssen auf `=== 'on'` umstellen. |
| 1.5.0 → 1.5.3 | Migration-Batch-Atomarität, Cache-Invalidation bei Kategorie-Delete | Nur `cache:clear`, keine neuen Migrations. |
| 1.5.x → 1.6.0 | Monolog-Channel, `DynamicPriceException`, BFSG-Accessibility, Integration-Tests | **`cache:clear` zwingend** (Container-Rebuild registriert den Channel). `bin/build-storefront.sh` (JS und Twig geändert). **Breaking:** `catch (\InvalidArgumentException)` auf Plugin-Aufrufe bricht — neue Exception erbt von `\RuntimeException`. |
| 1.6.0 → 1.6.1 | Monolog-Channel-Registrierung via `Plugin::build()` | `cache:clear` (Container-Rebuild). Ohne 1.6.1-Fix tritt in 1.6.0 `ServiceNotFoundException` beim ersten Request auf. |
| 1.6.1 → 1.6.2 | Kategorie-Custom-Fields mit eigenem Namespace `rc_meter_price_cat_*` | `plugin:update` registriert das Kategorie-CustomFieldSet (in 1.5.0/1.6.x scheiterte die Migration silent mit `UniqueConstraintViolation`). Keine Datenaktion nötig. **Breaking:** Integrationen, die Kategorie-Felder lesen oder schreiben, auf `rc_meter_price_cat_*`-Namen umstellen. |

### Log-Aggregation anpassen

Seit 1.6.0 schreibt das Plugin strukturierte Events auf den Monolog-Channel `rc_dynamic_price`:

- `info` beim Add-to-Cart mit der aufgelösten Scope-Herkunft pro Feld (`activeScope`, `minLengthScope`, …). Hilft bei Support-Fällen „warum ist der Preis X?".
- `warning` beim Verwerfen ungültiger Eingaben (Bounds, Format).
- `info` beim Auslösen eines Splits mit Teilstück-Vektor.

Wenn Ops Log-Filter (Graylog, ELK, Loki, CloudWatch Insights etc.) pflegt, den Channel `rc_dynamic_price` dort ergänzen — sonst laufen die Events nur im Default-Channel auf und fallen im gefilterten View weg.

### Multi-Shop-Hinweis

Wird das Plugin in mehreren Instanzen betrieben (Staging, Live, evtl. weitere Mandanten), ist die obige Sequenz **pro Instanz** zu durchlaufen. `plugin:update` greift auf die DB der jeweiligen Instanz — `cache:clear` betrifft das Filesystem der jeweiligen Instanz.

## Rollback

Falls ein Release zurückgerollt werden muss, gelten diese Schritte. Vorab **DB-Snapshot** und einen kurzen Storefront-Test auf Staging einplanen.

### Rollback 1.6.x → 1.5.x

1. **Plugin-Version herunterziehen**
   ```bash
   composer require ruhrcoder/rc-dynamic-price:^1.5.3
   php bin/console plugin:refresh
   php bin/console plugin:update RcDynamicPrice
   ```
2. **Keine Datenbank-Rückmigration nötig.** 1.6.x hat keine Migrations hinzugefügt — die Änderungen betrafen Exception-API, Monolog-Channel, Accessibility-Templates und Integration-Tests.
3. **Externe Integrationen prüfen.** Falls ein externer Consumer auf `DynamicPriceException::getErrorCode()` umgebaut wurde, existiert die Methode nach dem Rollback nicht mehr. Rückfall auf `\RuntimeException` oder `\InvalidArgumentException` (1.5.x-Verhalten).
4. **Log-Aggregation:** Der Channel `rc_dynamic_price` verschwindet. Wenn Filter für diesen Channel gepflegt wurden, wieder auf den Default-Channel umhängen, sonst werden Plugin-Logs nicht mehr eingesammelt.
5. **Caches verwerfen und Storefront neu bauen** (die a11y-Attribute im Twig und im JS fallen weg, JS-Bundles ändern sich):
   ```bash
   bin/build-storefront.sh
   php bin/console cache:clear
   php bin/console http:cache:clear
   ```

### Rollback 1.5.x → 1.4.x

1. **Plugin-Version herunterziehen**
   ```bash
   composer require ruhrcoder/rc-dynamic-price:^1.4.0
   php bin/console plugin:refresh
   php bin/console plugin:update RcDynamicPrice
   ```
2. **Tri-State-Werte auf bool zurückschreiben.** 1.4.x erwartet `rc_meter_price_active` als Boolean; 1.5.x speichert `'inherit' | 'on' | 'off'`. Direkt auf der DB:
   ```sql
   UPDATE product SET custom_fields = JSON_SET(
       custom_fields,
       '$.rc_meter_price_active',
       CASE JSON_UNQUOTE(JSON_EXTRACT(custom_fields, '$.rc_meter_price_active'))
           WHEN 'on' THEN CAST('true' AS JSON)
           ELSE CAST('false' AS JSON)
       END
   ) WHERE JSON_EXTRACT(custom_fields, '$.rc_meter_price_active') IS NOT NULL;
   ```
   `inherit` und `off` werden beide zu `false` — der Effekt entspricht „Meterpreis aus", weil 1.4.x kein Inherit-Konzept kennt.
3. **Custom-Field-Definition auf bool zurück.** Auch das `custom_field`-Schema muss rückumgestellt werden, sonst versteckt sich das Feld im Admin-UI:
   ```sql
   UPDATE custom_field SET type = 'bool', config = '{"type":"checkbox","label":{"de-DE":"Meterpreis aktiv","en-GB":"Meter price active"},"customFieldType":"checkbox","customFieldPosition":1}'
   WHERE name = 'rc_meter_price_active';
   ```
4. **Kategorie-Custom-Field-Set stilllegen.** 1.4.x ignoriert `rc_dynamic_price_category` komplett; die Tabelle bleibt bestehen. Will man sie physisch entfernen:
   ```sql
   DELETE FROM custom_field_set WHERE name = 'rc_dynamic_price_category';
   ```
   (Kaskadiert auf Felder und Relations.)
5. **HTTP-Cache und App-Cache verwerfen.**
   ```bash
   php bin/console cache:clear
   php bin/console http:cache:clear
   ```
6. **Plugin-Config-Keys**, die 1.5.x hinzugefügt hat (`applyToAllProducts`, `splitMode`, `maxPieceLength`, `splitHintTemplate`), werden von 1.4.x ignoriert — keine Datenaktion nötig.
7. **Smoke-Test** auf einem aktivierten Meterprodukt: PDP lädt, Längeneingabe funktioniert, Cart-Position korrekt.

### Was nicht rückgängig gemacht werden kann

- **Bestellungen**, die mit Split-Modus `equal`/`max_rest` in Teilstücke zerlegt wurden, bleiben als getrennte Positionen im Auftrag bestehen. 1.4.x versteht die Sibling-IDs, rendert sie aber ohne Split-Hinweis.
- **Split-Hint-Templates**, die Kunden-Admins in 1.5.x gepflegt haben, gehen beim Rollback verloren (1.4.x kennt das Feld nicht).

## Entwicklung

```bash
composer install
composer quality   # cs-check + phpstan + test
```
