import Plugin from 'src/plugin-system/plugin.class';

/**
 * Geführte Variantenauswahl: erst die Länge, dann nur die dazu kaufbaren Optionen.
 *
 * Der Kern zeigt alle Optionen aller Gruppen zugleich und streicht durch, was zur gerade gezeigten
 * Variante nicht passt. Beim Relinggeländer wirkt dadurch alles ab „3 Pfosten" gesperrt, und der
 * Kunde hält den Artikel für nicht konfigurierbar. Gefragt wird deshalb Schritt für Schritt: zuerst
 * die Längengruppe, dann jede weitere Gruppe in ihrer Reihenfolge, jeweils nur mit den Optionen, die
 * es zu den bisherigen Antworten zu kaufen gibt. Preis und Kaufknopf erscheinen, wenn die Variante
 * feststeht.
 *
 * Den Variantenwechsel macht weiter der Kern. Wählt der Kunde eine Option, die eine andere Variante
 * bedeutet, lädt der Kern die Seite wie immer; davor merkt sich das Skript die Antworten im
 * `sessionStorage` und stellt die Felder auf den Stand zurück, den der Kern auslesen will. Wählt er
 * die Option der gezeigten Variante, wird nur bestätigt, ohne Neuladen.
 *
 * Welche Variante gezeigt ist, steht im Markup des Servers (`defaultChecked`, `defaultSelected`), nicht
 * im aktuellen Zustand der Felder; den verändert das Skript, um unbeantwortete Schritte leer zu zeigen.
 *
 * Ohne Skript, oder wenn die Angaben nicht zur Seite passen, bleibt die Auswahl des Kerns.
 */
export default class GuidedSelectionPlugin extends Plugin {
    static options = {
        lengthGroupId: '',

        // Die Gruppen in der Reihenfolge der Schritte, je mit ihren Optionen.
        groups: [],

        // Je kaufbarer Variante ihre Optionen.
        combinations: [],

        textChooseFirst: '',
        textChooseNext: '',
        textPlaceholder: '',

        configuratorSelector: '.product-detail-configurator-container',
        groupSelector: '.product-detail-configurator-group',
        groupTitleSelector: '.product-detail-configurator-group-title',
        optionSelector: '.product-detail-configurator-option',

        // Bis die Variante feststeht, verbirgt die Gestaltung damit Preis, Lieferzeit und Kaufknopf.
        incompleteClass: 'rc-guided-incomplete',
        hiddenClass: 'rc-guided-hidden',
        unconfirmedClass: 'rc-guided-unconfirmed',
    };

    init() {
        // Die Kaufbox rendert auf der Produktseite mehr als einmal; es arbeitet nur das erste Element.
        if (document.querySelector('[data-rc-guided-selection]') !== this.el) {
            return;
        }

        this._configurator = document.querySelector(this.options.configuratorSelector);
        this._groups = this.options.groups.filter((group) => this._field(group.id) !== null);

        // Fehlt eine Gruppe im Markup, passt die Seite nicht zu den Angaben; dann lieber gar nicht
        // führen als halb.
        if (!this._configurator || this._groups.length !== this.options.groups.length || this._groups.length === 0) {
            return;
        }

        this._current = this._readCurrent();
        this._moveLengthGroupFirst();
        this._hint = this._createHint();

        const remembered = this._takeStash();
        const { confirmed, switchTo } = GuidedSelectionPlugin.settle(
            this._groups,
            this.options.combinations,
            this._current,
            remembered,
        );
        this._confirmed = confirmed;

        this._onChange = this._onChange.bind(this);
        document.addEventListener('change', this._onChange, true);

        this._render();

        // Gibt es zum nächsten Schritt nur eine Möglichkeit und zeigt die Seite eine andere, wird sie
        // gesetzt statt abgefragt.
        if (switchTo !== null) {
            this._select(switchTo.groupId, switchTo.optionId);
        }
    }

    destroy() {
        document.removeEventListener('change', this._onChange, true);
    }

    /**
     * Die Optionen einer Gruppe, die es zu den bisherigen Antworten zu kaufen gibt, in der
     * Reihenfolge der Gruppe.
     *
     * @param {Array<{id: string, optionIds: string[]}>} groups
     * @param {string[][]} combinations
     * @param {number} index der Schritt
     * @param {Object<string, string>} confirmed Gruppe → bestätigte Option
     * @return {string[]}
     */
    static possibleOptions(groups, combinations, index, confirmed) {
        const required = groups.slice(0, index).map((group) => confirmed[group.id]).filter(Boolean);
        const found = new Set();

        for (const combination of combinations) {
            if (required.every((optionId) => combination.includes(optionId))) {
                combination.forEach((optionId) => found.add(optionId));
            }
        }

        return groups[index].optionIds.filter((optionId) => found.has(optionId));
    }

    /**
     * Welche Schritte nach dem Laden der Seite als beantwortet gelten.
     *
     * Ein Schritt gilt, wenn der Kunde ihn beantwortet hat und die gezeigte Variante diese Antwort
     * trägt, oder wenn es zu ihm nur eine Möglichkeit gibt. Der erste offene Schritt beendet die
     * Reihe; was danach gemerkt war, verfällt, sobald es nicht mehr zur Variante passt.
     *
     * @return {{confirmed: Object<string, string>, switchTo: ?{groupId: string, optionId: string}}}
     */
    static settle(groups, combinations, current, remembered) {
        const confirmed = {};

        for (let index = 0; index < groups.length; index += 1) {
            const groupId = groups[index].id;
            const possible = GuidedSelectionPlugin.possibleOptions(groups, combinations, index, confirmed);
            const shown = current[groupId];

            if (possible.includes(shown) && (remembered[groupId] === shown || possible.length === 1)) {
                confirmed[groupId] = shown;
                continue;
            }

            if (possible.length === 1) {
                return { confirmed, switchTo: { groupId, optionId: possible[0] } };
            }

            break;
        }

        return { confirmed, switchTo: null };
    }

    _field(groupId) {
        return document.querySelector(`${this.options.configuratorSelector} select[name="${groupId}"]`)
            || document.querySelector(`${this.options.configuratorSelector} input[type="radio"][name="${groupId}"]`);
    }

    _isSelect(groupId) {
        return this._field(groupId).tagName === 'SELECT';
    }

    _radios(groupId) {
        return Array.from(document.querySelectorAll(
            `${this.options.configuratorSelector} input[type="radio"][name="${groupId}"]`,
        ));
    }

    _readCurrent() {
        const current = {};

        for (const group of this._groups) {
            if (this._isSelect(group.id)) {
                const option = Array.from(this._field(group.id).options).find((candidate) => candidate.defaultSelected);
                current[group.id] = option ? option.value : '';
            } else {
                const radio = this._radios(group.id).find((candidate) => candidate.defaultChecked);
                current[group.id] = radio ? radio.value : '';
            }
        }

        return current;
    }

    /**
     * Die Längengruppe nach oben: Der Kern ordnet die Gruppen nach ihrer Position, beim
     * Relinggeländer steht „Ausführung" vor „Maße". Der zweite Schritt erschiene sonst über dem ersten.
     */
    _moveLengthGroupFirst() {
        const first = this._field(this._groups[0].id).closest(this.options.groupSelector);
        const top = first.parentElement.querySelector(this.options.groupSelector);

        if (top !== first) {
            first.parentElement.insertBefore(first, top);
        }
    }

    _createHint() {
        const hint = document.createElement('p');
        hint.className = 'rc-guided-selection__hint alert alert-info';
        hint.setAttribute('role', 'status');
        hint.setAttribute('aria-live', 'polite');
        this._configurator.after(hint);

        return hint;
    }

    _render() {
        const next = Object.keys(this._confirmed).length;

        this._groups.forEach((group, index) => {
            const element = this._field(group.id).closest(this.options.groupSelector);
            const visible = index <= next;

            element.classList.toggle(this.options.hiddenClass, !visible);

            if (!visible) {
                return;
            }

            const possible = GuidedSelectionPlugin.possibleOptions(this._groups, this.options.combinations, index, this._confirmed);
            const answered = this._confirmed[group.id] !== undefined;

            element.classList.toggle(this.options.unconfirmedClass, !answered);

            if (this._isSelect(group.id)) {
                this._renderSelect(group.id, possible, answered);
            } else {
                this._renderRadios(group.id, possible, answered);
            }
        });

        this._renderHint(next);
    }

    _renderRadios(groupId, possible, answered) {
        for (const radio of this._radios(groupId)) {
            const option = radio.closest(this.options.optionSelector);
            const isPossible = possible.includes(radio.value);

            option.classList.toggle(this.options.hiddenClass, !isPossible);

            if (isPossible) {
                GuidedSelectionPlugin.clearUnavailableMark(option);
            }

            // Unbeantwortet heißt: nichts angehakt, auch wenn die gezeigte Variante eine Option trägt.
            radio.checked = answered && radio.value === this._confirmed[groupId];
        }
    }

    _renderSelect(groupId, possible, answered) {
        const select = this._field(groupId);

        for (const option of Array.from(select.options)) {
            if (option.value === '') {
                continue;
            }

            option.hidden = !possible.includes(option.value);
            option.disabled = option.hidden;

            // Der Kern hängt nicht kombinierbaren Optionen „(nicht verfügbar)" an den Namen und setzt
            // einen Titel. Was hier sichtbar bleibt, ist kaufbar.
            if (!option.hidden && option.title) {
                option.textContent = option.textContent.replace(/\s*\([^)]*\)\s*$/, '').trim();
                option.removeAttribute('title');
            }
        }

        let placeholder = select.querySelector('option[value=""]');

        if (answered) {
            placeholder?.remove();
            select.value = this._confirmed[groupId];

            return;
        }

        if (!placeholder) {
            placeholder = new Option(this.options.textPlaceholder, '');
            placeholder.disabled = true;
            select.prepend(placeholder);
        }

        select.value = '';
    }

    /**
     * Nimmt die Kennzeichnung „nicht kombinierbar" des Kerns von einer Option.
     *
     * Der Kern urteilt relativ zur gezeigten Variante: Zeigt die Seite „2 Pfosten", ist jede Länge
     * ab 2,5 m durchgestrichen und per `disabled`-Klasse unklickbar. In der geführten Auswahl ist
     * jede gezeigte Option kaufbar; die Kennzeichnung behauptete das Gegenteil und sperrte den Klick.
     */
    static clearUnavailableMark(option) {
        option.querySelectorAll('.not-combinable').forEach((element) => {
            element.classList.remove('not-combinable', 'disabled');
            element.classList.add('is-combinable');
        });
        option.querySelectorAll('small.visually-hidden').forEach((note) => note.remove());
    }

    _renderHint(next) {
        const complete = next >= this._groups.length;

        document.body.classList.toggle(this.options.incompleteClass, !complete);
        this._hint.hidden = complete;

        if (complete) {
            return;
        }

        this._hint.textContent = next === 0
            ? this.options.textChooseFirst
            : this.options.textChooseNext.split('%group%').join(this._groupTitle(this._groups[next].id));
    }

    _groupTitle(groupId) {
        const title = this._field(groupId).closest(this.options.groupSelector).querySelector(this.options.groupTitleSelector);

        // Die Überschrift trägt ein verstecktes „auswählen" für Vorleseprogramme; gezeigt wird nur der Name.
        return title && title.firstChild ? title.firstChild.textContent.trim() : '';
    }

    /**
     * Läuft vor dem Variantenwechsel des Kerns (Capture-Phase am Dokument).
     *
     * Die Option der gezeigten Variante wird nur bestätigt; das Ereignis endet hier, sonst lüde der
     * Kern dieselbe Seite neu. Jede andere Option ist ein Variantenwechsel: Die Antworten werden
     * gemerkt, die übrigen Felder auf den Stand der gezeigten Variante gestellt, und der Kern
     * übernimmt.
     */
    _onChange(event) {
        const target = event.target;
        const index = this._groups.findIndex((group) => group.id === target.name);

        if (index === -1 || !this._configurator.contains(target)) {
            return;
        }

        const groupId = target.name;
        const optionId = target.value;

        if (optionId === this._current[groupId]) {
            event.stopPropagation();
            this._confirm(index, optionId);

            return;
        }

        const answers = {};
        this._groups.slice(0, index).forEach((group) => {
            answers[group.id] = this._confirmed[group.id];
        });
        answers[groupId] = optionId;

        this._stash(answers);
        this._restoreShownVariant(groupId);
    }

    _confirm(index, optionId) {
        const confirmed = {};
        this._groups.slice(0, index).forEach((group) => {
            confirmed[group.id] = this._confirmed[group.id];
        });
        confirmed[this._groups[index].id] = optionId;

        // Die folgenden Schritte neu bestimmen: Was dahinter nur eine Möglichkeit hat, ist damit
        // auch beantwortet.
        const { confirmed: settled, switchTo } = GuidedSelectionPlugin.settle(
            this._groups,
            this.options.combinations,
            this._current,
            confirmed,
        );
        this._confirmed = settled;
        this._stash(settled);
        this._render();

        if (switchTo !== null) {
            this._select(switchTo.groupId, switchTo.optionId);
        }
    }

    _restoreShownVariant(exceptGroupId) {
        for (const group of this._groups) {
            if (group.id === exceptGroupId) {
                continue;
            }

            if (this._isSelect(group.id)) {
                this._field(group.id).value = this._current[group.id];
            } else {
                this._radios(group.id).forEach((radio) => {
                    radio.checked = radio.value === this._current[group.id];
                });
            }
        }
    }

    _select(groupId, optionId) {
        if (this._isSelect(groupId)) {
            const select = this._field(groupId);
            select.value = optionId;
            select.dispatchEvent(new Event('change', { bubbles: true }));

            return;
        }

        const radio = this._radios(groupId).find((candidate) => candidate.value === optionId);
        radio?.click();
    }

    // Das Zwischenlager lebt so lange wie der Reiter (`sessionStorage`) und wird beim Lesen nicht
    // geleert. Kommt der Kunde zum Artikel zurück, gilt eine gemerkte Antwort nur, wenn die gezeigte
    // Variante sie trägt (`settle()`). Der Schlüssel ist das Elternprodukt, damit sich zwei Artikel in
    // zwei Reitern nicht gegenseitig überschreiben.
    _stashKey() {
        return `rcGuidedSelection:${this.el.dataset.rcGuidedSelection || 'default'}`;
    }

    _stash(answers) {
        try {
            window.sessionStorage.setItem(this._stashKey(), JSON.stringify(answers));
        } catch (error) {
            // Ohne Zwischenlager beginnt die Auswahl nach dem Wechsel von vorn; bestellen geht trotzdem.
        }
    }

    _takeStash() {
        try {
            const raw = window.sessionStorage.getItem(this._stashKey());
            const answers = raw ? JSON.parse(raw) : {};

            return answers && typeof answers === 'object' ? answers : {};
        } catch (error) {
            return {};
        }
    }
}
