import Plugin from 'src/plugin-system/plugin.class';
import PseudoModalUtil from 'src/utility/modal-extension/pseudo-modal.util';
import { parseLength } from '../util/parse-length';

/**
 * Die eingegebene Länge wählt die Größenstufe der Variante.
 *
 * Bei Artikeln nach Maß wählt der Kunde eine Stufe wie „96 - 116 cm" und trägt darunter seine Länge
 * ein. Passt 1350 mm nicht zur gewählten Stufe, stellt das Skript auf „130 - 140 cm" um und sagt es dem
 * Kunden; ohne das fiele der Widerspruch erst beim Zuschnitt auf.
 *
 * Die Länge steht in einem Feld von `TmmsProductCustomerInputs`. Dessen Code bleibt unberührt, das Feld
 * wird über sein eigenes `change` angesprochen, denn nur dieses Ereignis speichert den Wert in der Sitzung
 * und berechnet die Kennung der Warenkorbposition. Gleiche Eingabe ergibt dieselbe Kennung (die Menge
 * steigt), andere Eingabe eine neue Position. Nur ins Feld geschrieben, stünde die richtige Länge im Text
 * und die falsche Kennung dahinter.
 *
 * Der Variantenwechsel lädt die Seite neu, und TMMS legt den Wert unter der Produktnummer der Variante ab;
 * nach dem Sprung stünde dort nichts. Deshalb trägt der `sessionStorage` den Wert über den Wechsel, und das
 * Skript schreibt ihn danach samt `change` zurück.
 *
 * Die Stufen kommen aus den Beschriftungen, nicht aus Zusatzfeldern je Variante: Bei 19 Größen wären das
 * 19 Eingaben je Artikel, die mit der Zeit gegen die Beschriftung auseinanderlaufen. Weil ein Text sich
 * ändern kann, gilt: Lässt sich auch nur eine Option nicht lesen, bleibt die Automatik auf der Seite aus.
 * Lieber nichts tun als auf die falsche Größe springen.
 */
export default class LengthVariantSwitchPlugin extends Plugin {
    static options = {
        // Das Längenfeld des fremden Plugins. Es wird gelesen und beschrieben, nie ersetzt.
        inputSelector: '.tmms-customer-input-value',

        // Die Größenstufen als Knopffeld. Jede Option ist ein Radiofeld, das Shopwares
        // Variantenwechsel auslöst.
        optionSelector: '.product-detail-configurator-option',

        // Dieselben Stufen als Auswahlfeld. Welche Form ein Artikel zeigt, entscheidet die Anzeigeart
        // der Eigenschaftsgruppe im Verwaltungsbereich; bedient werden beide.
        selectSelector: '.product-detail-configurator-select-input',

        // Das Mengenfeld der Kaufbox. Es folgt der Zahl der angegebenen Längen, sobald eine Eingabe
        // beurteilt ist. Ändert der Kunde die Menge danach von Hand, hat er das letzte Wort; zurückgesetzt
        // wird sie erst bei der nächsten neuen Eingabe.
        quantitySelector: '.product-detail-quantity-select, input[name*="[quantity]"]',

        // Die Eingabe steht in Millimetern, die Beschriftungen in Zentimetern.
        millimetresPerCentimetre: 10,

        // Die Gruppe, deren Optionen Längen sind. Leer heißt: alle Optionen der Seite, wie bei
        // Artikeln mit nur einer Gruppe. Mit geführter Auswahl setzt die Vorlage hier deren
        // Längengruppe; die übrigen Gruppen („2 Pfosten") wären keine lesbare Größe und schalteten
        // die Automatik sonst ab.
        groupId: '',

        // Die Größen nur über die Länge wählen: Die Knöpfe der Längengruppe verschwinden, und unter dem
        // Feld steht, welche Länge berechnet wird. Gedacht für Stangenmaterial.
        lengthOnly: false,

        // So lange wartet eine fertige Angabe nach der letzten Taste, bevor die Seite handelt (ms).
        typingPause: 1200,

        // Die Texte kommen aus den Übersetzungen und werden am Element übergeben. Die Überschriften hier
        // sind nur der Rückfall; ein Satz, der nur im Skript stünde, gäbe es nur auf Deutsch, und der Shop
        // hat zwei Sprachen. Die Platzhalter füllt das Skript.
        titleAdjusted: 'Größe angepasst',
        titleHint: 'Hinweis zur Länge',
        textAdjusted: '',
        textOutOfRange: '',
        textNextStep: '',
        showStepHint: false,
        textAskUnit: '',
    };

    init() {
        // Die Kaufbox rendert auf der Produktseite zweimal, einmal regulär und einmal für die
        // mitlaufende Kaufleiste. Ohne diesen Riegel hinge zweimal derselbe Zuhörer am Feld, das Fenster
        // ginge doppelt auf und die Menge würde zweimal gesetzt. Es arbeitet nur das erste Element.
        if (document.querySelector('[data-rc-length-variant-switch]') !== this.el) {
            return;
        }

        this._input = document.querySelector(this.options.inputSelector);
        this._ranges = this._readRanges();

        if (!this._input || this._ranges === null) {
            return;
        }

        this._onInputChange = this._onInputChange.bind(this);
        this._onInputTyping = this._onInputTyping.bind(this);

        // Zwei Zuhörer, ein Ergebnis. `change` kommt erst beim Verlassen des Feldes; wer zwei Längen zu
        // verschiedenen Größen eintippt, erführe es dann erst nach dem Weiterklicken. `input` kommt bei
        // jedem Zeichen, gehandelt wird aber nur, wenn die Angabe fertig ist (`isComplete()`).
        this._input.addEventListener('input', this._onInputTyping);
        this._input.addEventListener('change', this._onInputChange);

        this._hint = this._createHint();

        if (this.options.lengthOnly) {
            this._hideLengthGroup();
        }

        this._subscribeToAddToCart();
        this._restoreAfterSwitch();
    }

    destroy() {
        window.clearTimeout(this._typingTimer);

        if (this._input) {
            this._input.removeEventListener('input', this._onInputTyping);
            this._input.removeEventListener('change', this._onInputChange);
        }
    }

    /**
     * Liest die Größenstufen aus den Beschriftungen der Optionen.
     *
     * Gibt `null` zurück, sobald eine einzige Beschriftung nicht lesbar ist. Eine halb gelesene Liste
     * führte zu Sprüngen auf die falsche Stufe, und die bemerkt niemand; eine Automatik, die gar nichts
     * tut, fällt dagegen auf.
     *
     * @return {Array<{min: number, max: number, label: string, apply: Function, isCurrent: Function, confirm: Function}>|null}
     */
    _readRanges() {
        const groupId = this.options.groupId;
        const select = groupId
            ? document.querySelector(`select${this.options.selectSelector}[name="${groupId}"]`)
            : document.querySelector(this.options.selectSelector);
        const ranges = select ? this._readRangesFromSelect(select) : this._readRangesFromButtons();

        return ranges === null ? null : LengthVariantSwitchPlugin.completeFixedSizes(ranges);
    }

    /**
     * Die Größen als Auswahlfeld: eine Zeile statt mehrerer Reihen Knöpfe.
     *
     * Neunzehn Knöpfe schieben den Kaufknopf unter die Falz, deshalb zeigen manche Artikel ihre Stufen als
     * Auswahlfeld. Das ist die Anzeigeart der Eigenschaftsgruppe, eine Einstellung im Verwaltungsbereich;
     * das Skript darf sich auf keine der beiden Formen festlegen.
     *
     * @return {Array<{min: number, max: number, label: string, apply: Function, isCurrent: Function, confirm: Function}>|null}
     */
    _readRangesFromSelect(select) {
        const ranges = [];

        for (const option of Array.from(select.options)) {
            if (option.value === '') {
                // Der Platzhalter der geführten Auswahl ist keine Größe.
                continue;
            }

            const text = LengthVariantSwitchPlugin.withoutUnavailableNote(option.textContent, option.title !== '');
            const bounds = LengthVariantSwitchPlugin.parseRange(text);

            if (bounds === null) {
                LengthVariantSwitchPlugin.warnUnreadable(text);

                return null;
            }

            ranges.push({
                ...bounds,
                // Der Variantenwechsel hängt am `change` des Feldes, nicht an einem Klick.
                apply: () => {
                    select.value = option.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                },
                // Gezeigt ist, was der Server ausgewählt ausgeliefert hat. Der aktuelle Wert des
                // Feldes kann leer sein, solange die geführte Auswahl auf die Länge wartet.
                isCurrent: () => option.defaultSelected,
                confirm: () => {
                    if (select.value !== option.value) {
                        select.value = option.value;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                },
            });
        }

        return ranges.length > 0 ? ranges : null;
    }

    /**
     * Die Größen als Knopffeld, die Form, die Shopware ohne besondere Anzeigeart rendert.
     */
    _readRangesFromButtons() {
        const groupId = this.options.groupId;
        const options = Array.from(document.querySelectorAll(this.options.optionSelector))
            .filter((option) => !groupId || option.querySelector(`input[name="${groupId}"]`) !== null);

        if (options.length === 0) {
            return null;
        }

        const ranges = [];

        for (const option of options) {
            const label = option.querySelector('label');
            const input = option.querySelector('input');
            const text = label ? LengthVariantSwitchPlugin.labelText(label) : '';
            const bounds = LengthVariantSwitchPlugin.parseRange(text);

            if (bounds === null || !input) {
                LengthVariantSwitchPlugin.warnUnreadable(label ? text : '(ohne Beschriftung)');

                return null;
            }

            ranges.push({
                ...bounds,
                apply: () => input.click(),
                isCurrent: () => input.defaultChecked,
                confirm: () => {
                    if (!input.checked) {
                        input.click();
                    }
                },
            });
        }

        return ranges;
    }

    /**
     * Die Beschriftung eines Knopfes ohne den versteckten Zusatz des Kerns.
     *
     * Ist eine Größe zur gezeigten Variante nicht kombinierbar, hängt der Kern für Vorleseprogramme
     * „(Diese Option ist zurzeit nicht verfügbar.)" als `small.visually-hidden` an. Mitgelesen machte er
     * die Beschriftung unlesbar, und die Automatik bliebe auf der ganzen Seite aus.
     */
    static labelText(label) {
        const copy = label.cloneNode(true);
        copy.querySelectorAll('small').forEach((note) => note.remove());

        return copy.textContent;
    }

    /**
     * Im Auswahlfeld steht derselbe Zusatz als Klammer hinter dem Namen; der Kern setzt dann auch
     * einen Titel an die Option. Nur dann wird die letzte Klammer abgeschnitten, damit eine
     * Beschriftung, die selbst mit einer Klammer endet, unberührt bleibt.
     */
    static withoutUnavailableNote(text, marked) {
        const value = String(text || '');

        return marked ? value.replace(/\s*\([^)]*\)\s*$/, '') : value;
    }

    static warnUnreadable(text) {
        // eslint-disable-next-line no-console
        console.warn(
            '[RcLengthVariantSwitch] Größenstufe nicht lesbar — die Automatik bleibt auf dieser Seite aus.',
            String(text || '').trim(),
        );
    }

    /**
     * `96 - 116 cm` wird zu `{min: 96, max: 116}`, ein festes Maß wie `1,5 m` zu `{min: null, max: 150}`.
     *
     * Erlaubt sind Bindestrich und Gedankenstrich, beliebige Leerzeichen und ein Zeilenumbruch, denn die
     * Beschriftungen sind im Verwaltungsbereich von Hand gesetzt. Feste
     * Maße stehen in Metern, Zentimetern oder Millimetern, mit Komma oder Punkt; ihre Untergrenze
     * setzt `completeFixedSizes()`, denn sie ergibt sich erst aus dem nächstkleineren Maß.
     *
     * @return {{min: ?number, max: number, label: string}|null}
     */
    static parseRange(text) {
        const normalized = String(text || '')
            .replace(/\s+/g, ' ')
            .trim();
        const fixed = normalized.match(/^(\d+(?:[.,]\d+)?) ?(mm|cm|m)$/i);

        if (fixed) {
            const centimetresPerUnit = { mm: 0.1, cm: 1, m: 100 };
            const max = Math.round(Number(fixed[1].replace(',', '.')) * centimetresPerUnit[fixed[2].toLowerCase()] * 10) / 10;

            return max > 0 ? { min: null, max, label: normalized } : null;
        }

        const match = normalized.match(/^(\d+)\s*[-–]\s*(\d+)\s*cm$/i);

        if (!match) {
            return null;
        }

        const min = Number(match[1]);
        const max = Number(match[2]);

        if (min >= max) {
            return null;
        }

        return { min, max, label: `${min} – ${max} cm` };
    }

    /**
     * Gibt festen Maßen ihre Untergrenze: das nächstkleinere Maß, beim kleinsten null.
     *
     * Damit gilt für feste Maße dieselbe Regel wie für Bereiche, die nächstgrößere Stufe: 1,37 m
     * wird 1,5 m, und eine Länge unter dem kleinsten Maß bekommt das kleinste.
     */
    static completeFixedSizes(ranges) {
        const sorted = [...ranges].sort((left, right) => left.max - right.max);

        return ranges.map((range) => {
            if (range.min !== null) {
                return range;
            }

            const smaller = sorted.filter((candidate) => candidate.max < range.max);

            return { ...range, min: smaller.length > 0 ? smaller[smaller.length - 1].max : 0 };
        });
    }

    /**
     * Zerlegt die Eingabe in einzelne Längen in Millimetern.
     *
     * Mehrere Angaben trennt der Kunde mit Semikolon, so steht es im Hinweistext über dem Feld. Jede Angabe
     * darf eine Einheit tragen (`parseLength()`). Ein leeres Feld ergibt eine leere Liste, eine unlesbare
     * Angabe `null`: Das Feld ist ein Textfeld, und wer noch tippt, soll nicht angesprungen werden.
     *
     * @return {number[]|null}
     */
    static parseLengths(value) {
        const raw = String(value || '').trim();

        if (raw === '') {
            return [];
        }

        const parts = raw.split(';').map((part) => part.trim()).filter((part) => part !== '');

        if (parts.length === 0) {
            return [];
        }

        const lengths = [];

        for (const part of parts) {
            const length = LengthVariantSwitchPlugin.parseLength(part);

            if (length === null || length.ask !== undefined) {
                return null;
            }

            lengths.push(length.mm);
        }

        return lengths;
    }

    /**
     * Eine Angabe in Millimetern, mit oder ohne Einheit; die Regel steht in `util/parse-length.js`.
     *
     * @return {{mm: number}|{ask: number}|null}
     */
    static parseLength(text) {
        return parseLength(text);
    }

    /**
     * Die erste Angabe der Eingabe, die nach ihrer Einheit fragen muss, oder `null`.
     */
    static unitQuestion(value) {
        for (const part of String(value || '').split(';')) {
            const length = LengthVariantSwitchPlugin.parseLength(part);

            if (length !== null && length.ask !== undefined) {
                return length.ask;
            }
        }

        return null;
    }

    /**
     * Teilt Längen nach ihrer Größe, in der Reihenfolge, in der der Kunde sie eingetragen hat.
     *
     * @return {Array<{label: string, lengths: number[]}>}
     */
    static groupByRange(ranges, lengths, millimetresPerCentimetre) {
        const groups = [];

        for (const length of lengths) {
            const range = LengthVariantSwitchPlugin.findRange(ranges, length, millimetresPerCentimetre);
            const group = groups.find((candidate) => candidate.label === range.label);

            if (group) {
                group.lengths.push(length);
            } else {
                groups.push({ label: range.label, lengths: [length] });
            }
        }

        return groups;
    }

    /**
     * Die Stufe zu einer Länge: die kleinste Obergrenze, die noch reicht.
     *
     * So wird „nächst größere Länge" aus dem Hinweistext zur Regel. Die Bereiche überlappen an ihren
     * Grenzen; genau 1300 mm gehört zu `116 - 130 cm`, nicht zu `130 - 140 cm`. Ohne diese Regel
     * entschiede die Reihenfolge im Markup, und die ist keine fachliche Entscheidung. Liegt die Länge
     * unter der kleinsten Untergrenze, gibt es keine Stufe.
     */
    static findRange(ranges, lengthInMillimetres, millimetresPerCentimetre) {
        const centimetres = lengthInMillimetres / millimetresPerCentimetre;
        const smallest = ranges.reduce((carry, range) => (range.min < carry ? range.min : carry), Infinity);

        if (centimetres < smallest) {
            return null;
        }

        return ranges
            .filter((range) => range.max >= centimetres)
            .reduce((carry, range) => (carry === null || range.max < carry.max ? range : carry), null);
    }

    /**
     * Fasst zusammen, was die Eingabe bedeutet: eine Stufe, mehrere oder keine.
     *
     * @return {{status: string, range: ?Object, count: number}}
     */
    static evaluate(ranges, lengths, millimetresPerCentimetre) {
        if (lengths.length === 0) {
            return { status: 'empty', range: null, count: 0 };
        }

        const matches = lengths.map(
            (length) => LengthVariantSwitchPlugin.findRange(ranges, length, millimetresPerCentimetre),
        );

        if (matches.some((match) => match === null)) {
            return { status: 'outOfRange', range: null, count: lengths.length };
        }

        const distinct = new Set(matches.map((match) => match.label));

        if (distinct.size > 1) {
            return { status: 'mixed', range: null, count: lengths.length };
        }

        return { status: 'matched', range: matches[0], count: lengths.length };
    }

    /**
     * Beim Tippen, aber nur mit fertiger Angabe.
     *
     * Wer eine Länge einträgt, die nicht zur gewählten Größe passt, soll es sehen, solange er noch am Feld
     * ist. Bei jedem Zeichen zu prüfen ginge aber schief: Aus `1` wird `13`, `135`, `1350`, und bei `135`
     * spränge die Seite auf die kleinste Stufe und lüde neu, mitten im Tippen.
     *
     * Auch eine fertige Angabe wartet einen Augenblick nach der letzten Taste. Wer „1200; 4200" eintippt,
     * hat nach „1200" eine fertige Länge im Feld; sofort beurteilt, spränge die Seite, bevor das Semikolon
     * kommt, und die zweite Länge ginge verloren. Das Verlassen des Feldes wartet nicht.
     */
    _onInputTyping() {
        window.clearTimeout(this._typingTimer);

        if (!LengthVariantSwitchPlugin.isComplete(this._input.value, this._digitsOfLongest())) {
            return;
        }

        this._typingTimer = window.setTimeout(() => this._onInputChange(), this.options.typingPause);
    }

    /**
     * Ist die Eingabe fertig genug, um darauf zu reagieren?
     *
     * Zwei Fälle machen eine Angabe fertig: genug Ziffern oder das Verlassen des Feldes; Letzteres
     * erledigt `change`, nicht diese Methode.
     *
     * Genug sind so viele Ziffern, wie die längste Stufe in Millimetern hat, mindestens vier. Reichen die
     * Stufen bis 3000 Millimeter, ist `1350` fertig; reichen sie bis 15000, kann `1500` noch der Anfang von
     * `15000` sein. Eine kürzere Angabe wie `970` bleibt unentschieden, bis der Kunde das Feld verlässt.
     *
     * Ein Semikolon am Ende kündigt die nächste Länge an und ist deshalb nie fertig. Gehörte die erste
     * Länge zu einer anderen Größe, spränge die Seite sonst mitten im Tippen und schnitte die zweite ab.
     */
    static isComplete(value, digits = 4) {
        const raw = String(value || '').trim();

        if (raw === '') {
            return false;
        }

        if (raw.endsWith(';')) {
            return false;
        }

        const lastSegment = raw.split(';').pop().trim();

        return lastSegment.length >= digits && /^\d+$/.test(lastSegment);
    }

    /**
     * Wie viele Ziffern die längste Stufe in Millimetern hat. Bei Bausätzen bis 15 m sind das fünf;
     * dort wäre `1500` mit vier Ziffern noch nicht fertig, sondern vielleicht der Anfang von `15000`.
     */
    _digitsOfLongest() {
        const longest = Math.max(...this._ranges.map((range) => range.max)) * this.options.millimetresPerCentimetre;

        return Math.max(4, String(Math.round(longest)).length);
    }

    _onInputChange() {
        window.clearTimeout(this._typingTimer);

        // Dieselbe Eingabe wird nur einmal beurteilt, gleich über welchen Weg sie kommt. Eine fertige
        // Angabe erreicht diese Methode zweimal, nach der letzten Ziffer und beim Verlassen des Feldes;
        // zweimal beurteilt, öffnete sie zwei Fenster mit je eigener Abdunklung, und nach dem Bestätigen
        // bliebe eine liegen und sperrte die Seite. Deshalb steht der Vergleich hier und nicht an einem
        // der beiden Zugänge.
        if (this._input.value === this._lastEvaluated) {
            return;
        }

        this._lastEvaluated = this._input.value;

        const lengths = LengthVariantSwitchPlugin.parseLengths(this._input.value);

        if (lengths === null) {
            const ask = LengthVariantSwitchPlugin.unitQuestion(this._input.value);

            if (ask !== null) {
                this._openModal(
                    LengthVariantSwitchPlugin.fillPlaceholders(this.options.textAskUnit, { '%value%': ask }),
                    this.options.titleHint,
                );
            }

            return;
        }

        this._normalizeInput(lengths);

        const result = LengthVariantSwitchPlugin.evaluate(
            this._ranges,
            lengths,
            this.options.millimetresPerCentimetre,
        );

        if (result.status === 'empty') {
            return;
        }

        if (result.status === 'outOfRange') {
            this._openModal(this._deliverableRangeMessage(), this.options.titleHint);
            this._clearInput();

            return;
        }

        if (result.status === 'mixed') {
            this._startSteps(LengthVariantSwitchPlugin.groupByRange(this._ranges, lengths, this.options.millimetresPerCentimetre));

            return;
        }

        this._applyQuantity(result.count);
        this._clearSteps();

        if (result.range.isCurrent()) {
            // Die Länge passt zur gezeigten Größe. Wartet die geführte Auswahl noch auf die Länge,
            // ist sie damit beantwortet.
            result.range.confirm?.();
            this._hideHint();

            return;
        }

        this._stash({
            value: this._input.value,
            from: this._currentLabel(),
            to: result.range.label,
            count: result.count,
        });

        result.range.apply();
    }

    /**
     * Nach dem Neuladen den mitgenommenen Wert zurückschreiben und dasselbe `change` auslösen, das der
     * Kunde beim Verlassen des Feldes auslöst; erst das Ereignis lässt TMMS den Wert für die neue Variante
     * speichern. Das Zwischenlager ist zu diesem Zeitpunkt schon geleert (`_takeStash()`), ein zweites
     * Neuladen zeigt den Hinweis also nicht noch einmal.
     */
    _restoreAfterSwitch() {
        const stashed = this._takeStash();

        if (stashed === null) {
            return;
        }

        this._input.value = stashed.value;
        this._lastEvaluated = stashed.value;
        this._input.dispatchEvent(new Event('change', { bubbles: true }));

        this._applyQuantity(stashed.count);

        const lengths = LengthVariantSwitchPlugin.parseLengths(stashed.value) || [];
        const steps = this._readSteps();

        // Bei mehreren Größen erklärt der Schritt-Hinweis den Sprung; ein Fenster obendrauf wäre doppelt.
        if (steps.length > 0 || stashed.step) {
            this._showNextStep(lengths, stashed.to, steps[0]);

            return;
        }

        this._hideHint();

        // War noch keine Größe gewählt, etwa am Anfang der geführten Auswahl, hat die Länge sie erst
        // bestimmt; „passt nicht zu …" wäre dann falsch.
        if (this.options.lengthOnly || !stashed.from) {
            // Ohne sichtbare Größen ist der Sprung kein Ereignis für den Kunden; ein Fenster hätte nichts zu
            // erklären.
            return;
        }

        this._openModal(
            LengthVariantSwitchPlugin.fillPlaceholders(this.options.textAdjusted, {
                '%from%': stashed.from,
                '%to%': stashed.to,
            }),
            this.options.titleAdjusted,
        );
    }

    /**
     * Schreibt die Eingabe in Millimetern ins Feld zurück, wenn der Kunde mit Einheit oder Komma getippt
     * hat. In Warenkorb, Bestellung und Fertigung steht so immer dieselbe Einheit. Das `change` speichert
     * den Wert bei TMMS; die Sperre gegen doppelte Beurteilung trägt schon den neuen Wert.
     */
    _normalizeInput(lengths) {
        const normalized = lengths.join(';');

        if (lengths.length === 0 || this._input.value.trim() === normalized) {
            return;
        }

        this._input.value = normalized;
        this._lastEvaluated = normalized;
        this._input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * Längen verschiedener Größen nacheinander: Eine Warenkorbposition trägt nur eine Größe.
     *
     * Die erste Größe kommt sofort ins Feld, die übrigen warten im Zwischenlager. Nach jedem „In den
     * Warenkorb" stellt `_onAddedToCart()` auf die nächste um; getrennte Positionen legt RcCartSplitter an,
     * weil sich die Eingaben unterscheiden.
     */
    _startSteps(groups) {
        const [first, ...rest] = groups;
        const range = this._ranges.find((candidate) => candidate.label === first.label);

        this._writeSteps(rest);
        this._input.value = first.lengths.join(';');
        this._lastEvaluated = this._input.value;
        this._input.dispatchEvent(new Event('change', { bubbles: true }));
        this._applyQuantity(first.lengths.length);

        if (range.isCurrent()) {
            range.confirm?.();
            this._showNextStep(first.lengths, first.label, rest[0]);

            return;
        }

        this._stash({
            value: this._input.value,
            from: this._currentLabel(),
            to: first.label,
            count: first.lengths.length,
        });

        range.apply();
    }

    _onAddedToCart() {
        const steps = this._readSteps();

        if (steps.length === 0) {
            return;
        }

        const [next, ...rest] = steps;
        const range = this._ranges.find((candidate) => candidate.label === next.label);

        if (!range) {
            this._clearSteps();

            return;
        }

        this._writeSteps(rest);
        this._stash({
            value: next.lengths.join(';'),
            from: this._currentLabel(),
            to: next.label,
            count: next.lengths.length,
            step: true,
        });

        // Liegt die nächste Größe schon auf der Seite, gibt es keinen Sprung; das Feld wird direkt gefüllt.
        if (range.isCurrent()) {
            this._restoreAfterSwitch();

            return;
        }

        range.apply();
    }

    /**
     * Hängt sich an die Kaufbox des Kerns. Sie meldet einen erfolgreichen Warenkorb-Aufruf mit
     * `openOffCanvasCart` oder, wenn keine Leiste aufgeht, mit `addToCartWithoutOffcanvas`.
     */
    _subscribeToAddToCart() {
        const manager = window.PluginManager;

        if (!manager || typeof manager.getPluginInstances !== 'function') {
            return;
        }

        const onAdded = () => this._onAddedToCart();

        for (const instance of manager.getPluginInstances('AddToCart') || []) {
            instance.$emitter.subscribe('openOffCanvasCart', onAdded);
            instance.$emitter.subscribe('addToCartWithoutOffcanvas', onAdded);
        }
    }

    _showNextStep(lengths, label, next) {
        // Ist kein Schritt mehr offen, gibt es nichts mehr zu sagen; ein stehender Hinweis vom vorigen
        // Schritt verschwindet. Ist der Hinweis abgeschaltet, laufen die Schritte ohne ihn.
        if (!next || !this.options.showStepHint) {
            this._hideHint();

            return;
        }

        this._showHint(LengthVariantSwitchPlugin.fillPlaceholders(this.options.textNextStep, {
            '%done%': LengthVariantSwitchPlugin.formatLengths(lengths),
            '%size%': label,
            '%next%': LengthVariantSwitchPlugin.formatLengths(next.lengths),
            '%nextSize%': next.label,
        }));
    }

    static formatLengths(lengths) {
        return lengths.map((length) => `${length} mm`).join('; ');
    }

    _createHint() {
        const hint = document.createElement('p');
        hint.className = 'rc-length-switch-hint alert alert-info';
        hint.setAttribute('role', 'status');
        hint.setAttribute('aria-live', 'polite');
        hint.hidden = true;

        // Unter das Formular des Längenfelds, damit der Hinweis bei der Eingabe steht, auf die er sich bezieht.
        const anchor = this._input.closest('form') || this._input;
        anchor.after(hint);

        return hint;
    }

    _hideHint() {
        if (this._hint) {
            this._hint.hidden = true;
        }
    }

    _showHint(text) {
        if (!this._hint || text === '') {
            return;
        }

        this._hint.textContent = text;
        this._hint.hidden = false;
    }

    /**
     * Blendet die Knöpfe der Längengruppe aus. Gewählt wird die Größe dann nur noch über die Länge; die
     * Felder bleiben im Formular, damit der Variantenwechsel des Kerns sie weiter auslesen kann.
     */
    _hideLengthGroup() {
        const field = document.querySelector(`[name="${this.options.groupId}"]`);
        const group = field ? field.closest('.product-detail-configurator-group') : null;

        group?.classList.add('rc-guided-hidden');
    }

    _stepsKey() {
        return `${this._stashKey()}:steps`;
    }

    _readSteps() {
        try {
            const steps = JSON.parse(window.sessionStorage.getItem(this._stepsKey()) || '[]');

            return Array.isArray(steps) ? steps : [];
        } catch (error) {
            return [];
        }
    }

    _writeSteps(steps) {
        try {
            if (steps.length === 0) {
                window.sessionStorage.removeItem(this._stepsKey());
            } else {
                window.sessionStorage.setItem(this._stepsKey(), JSON.stringify(steps));
            }
        } catch (error) {
            // Ohne Zwischenlager bleibt es bei der ersten Größe; die übrigen trägt der Kunde selbst ein.
        }
    }

    _clearSteps() {
        this._writeSteps([]);
    }

    /**
     * Die Menge folgt der Zahl der Längen: Zwei Zuschnitte sind zwei Stück.
     *
     * Von selbst bliebe die Menge bei 1, auch mit zwei Längen im Feld; das ist der Unterschied zwischen
     * zwei bezahlten Stücken und einem.
     */
    _applyQuantity(count) {
        const quantity = document.querySelector(this.options.quantitySelector);

        if (!quantity || count < 1) {
            return;
        }

        const wanted = String(count);

        if (quantity.value === wanted) {
            return;
        }

        quantity.value = wanted;
        quantity.dispatchEvent(new Event('change', { bubbles: true }));
    }

    _currentLabel() {
        if (!this._lengthChosen()) {
            return '';
        }

        const current = this._ranges.find((range) => range.isCurrent());

        return current ? current.label : '';
    }

    /**
     * Ist in der Längengruppe eine Größe gewählt? Die geführte Auswahl nimmt die Wahl der vom Server
     * gezeigten Variante zurück, bis der Kunde die Länge angibt.
     */
    _lengthChosen() {
        if (!this.options.groupId) {
            return true;
        }

        const fields = [...document.querySelectorAll(`[name="${this.options.groupId}"]`)];

        return fields.some((field) => (field.tagName === 'SELECT' ? field.value !== '' : field.checked));
    }

    _deliverableRangeMessage() {
        // Das kleinste feste Maß beginnt rechnerisch bei null; genannt wird das Maß selbst.
        const min = Math.min(...this._ranges.map((range) => range.min || range.max));
        const max = Math.max(...this._ranges.map((range) => range.max));
        const factor = this.options.millimetresPerCentimetre;

        return LengthVariantSwitchPlugin.fillPlaceholders(this.options.textOutOfRange, {
            '%minCm%': min,
            '%maxCm%': max,
            '%minMm%': min * factor,
            '%maxMm%': max * factor,
        });
    }

    /**
     * Setzt die Platzhalter eines Übersetzungstextes.
     *
     * Von Hand, ohne Bibliothek: Es sind vier Platzhalter in drei Sätzen, und eine Abhängigkeit dafür
     * kostete im Bau und in der Pflege mehr als diese Schleife.
     */
    static fillPlaceholders(text, values) {
        return Object.entries(values).reduce(
            (carry, [placeholder, value]) => carry.split(placeholder).join(String(value)),
            String(text || ''),
        );
    }

    // Das Zwischenlager liegt im Browser, nicht auf dem Server: Es trägt den Wert über genau einen
    // Seitenwechsel, danach hat ihn TMMS in seiner Sitzung. Ein dauerhafter zweiter Speicherort wäre ein
    // zweiter Ort, an dem dieselbe Angabe veralten kann.
    _stashKey() {
        // Der Schlüssel trägt das Elternprodukt. Bricht ein Sprung ab und öffnet der Kunde im selben Reiter
        // einen anderen Artikel nach Maß, landet der Wert nicht dort.
        return `rcLengthVariantSwitch:${this.el.dataset.rcLengthVariantSwitch || 'default'}`;
    }

    _stash(payload) {
        try {
            window.sessionStorage.setItem(this._stashKey(), JSON.stringify(payload));
        } catch (error) {
            // Ohne Zwischenlager springt die Seite trotzdem, verliert aber den Wert, und der Hinweis
            // bleibt aus; die Eingabe steht dann nicht mehr im Feld.
        }
    }

    _takeStash() {
        let raw = null;

        try {
            raw = window.sessionStorage.getItem(this._stashKey());
            window.sessionStorage.removeItem(this._stashKey());
        } catch (error) {
            return null;
        }

        if (!raw) {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch (error) {
            return null;
        }
    }

    /**
     * Leert das Feld, nachdem eine Eingabe abgewiesen wurde.
     *
     * Leer statt `0`, denn eine `0` wäre selbst eine ungültige Länge: Der Kunde bekäme eine zweite
     * Abfuhr und müsste die Ziffer erst löschen. Ein leeres Feld ist der Zustand vor der Eingabe.
     *
     * Die Sperre gegen doppelte Beurteilung wird mit zurückgesetzt: Wer dieselbe Zahl noch einmal
     * eintippt, soll denselben Hinweis wieder bekommen.
     */
    _clearInput() {
        this._input.value = '';
        this._lastEvaluated = '';
        this._input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /**
     * Das Fenster des Kerns (`PseudoModalUtil`): Es bringt Fokus, Tastaturbedienung und Schließen mit,
     * die ein eigenes Fenster nachbauen müsste.
     */
    _openModal(text, title = 'Hinweis zur Länge') {
        // Die Überschrift trägt die Klasse, die Shopware sucht, und erscheint so im Kopf des Fensters.
        // Ein eigenes Wurzelelement fehlt absichtlich; es ersetzte die Hülle samt Schließen-Knopf.
        const content = `<div class="js-pseudo-modal-template-title-element">${title}</div>`
            + `<div class="rc-length-switch-modal">${text}</div>`;

        // Das Fenster bleibt, wo Shopware es hinstellt. Eine Klasse wie `modal-dialog-centered` im
        // Rückruf nach dem Öffnen käme zu spät: Das Fenster stünde schon sichtbar oben und wanderte dann
        // in die Mitte.
        //
        // Ist schon ein Fenster offen, kommt kein zweites. Jedes `PseudoModalUtil` bringt seine eigene
        // Abdunklung mit, und eine davon bliebe nach dem Bestätigen liegen und sperrte die Seite. Der
        // Vergleich in `_onInputChange` deckt die bekannten Wege ab, dieser Riegel jeden weiteren.
        if (this._modalOpen) {
            return;
        }

        this._modalOpen = true;

        new PseudoModalUtil(content).open(() => {
            const modal = document.querySelector('.modal.show');

            if (!modal) {
                this._modalOpen = false;

                return;
            }

            modal.addEventListener('hidden.bs.modal', () => {
                this._modalOpen = false;
            }, { once: true });
        });
    }
}
