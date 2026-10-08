import Plugin from 'src/plugin-system/plugin.class';
import HintModal from '../util/hint-modal';
import { parseLength } from '../util/parse-length';

/**
 * Das Längenfeld der Meterpreis-Artikel: prüft die Eingabe, zeigt Rundung, Aufteilung und den Preis vor
 * dem Klick auf „In den Warenkorb" und schreibt die Länge in das Formular.
 *
 * Die Vorschau rechnet nach denselben Regeln wie der Server (`LengthSplitter`, `DynamicPriceProcessor`),
 * damit der Kunde im Warenkorb keinen anderen Preis sieht als auf der Produktseite. Weil andere
 * Erweiterungen ebenfalls an der Kennung der Warenkorbposition mitschreiben, folgt die Kennung dem
 * gemeinsamen Suffix-Protokoll der Ruhrcoder-Erweiterungen.
 */
export default class DynamicPricePlugin extends Plugin {

    // Das gemeinsame Ereignis des Suffix-Protokolls. Der Name gehört keiner einzelnen Erweiterung; jede,
    // die einen Suffix an der Kennung setzt, meldet ihn darüber.
    static SUFFIX_CHANGED_EVENT = 'rcSuffixChanged';

    init() {
        this._input       = this.el.querySelector('.rc-dynamic-price__input');
        this._hidden      = this.el.querySelector('.rc-dynamic-price__hidden');
        this._errorEl     = this.el.querySelector('.rc-dynamic-price__error');
        this._infoEl      = this.el.querySelector('.rc-dynamic-price__info');
        this._splitInfoEl = this.el.querySelector('.rc-dynamic-price__split-info');
        this._resultEl    = this.el.querySelector('.rc-dynamic-price__result');
        this._resultPrice = this.el.querySelector('.rc-dynamic-price__result-price');
        this._form        = this.el.closest('form');
        this._submitBtn   = this._form ? this._form.querySelector('[type="submit"]') : null;
        this._productId   = this.el.dataset.productId;

        this._productPrice = document.querySelector('.product-detail-price');
        this._originalPriceHtml = this._productPrice ? this._productPrice.innerHTML : '';

        this._hintShown = false;

        // Die Rundungsstufen schreibt der Server (`MeterProductHelper::ROUNDING_STEPS`) als JSON in
        // `data-rounding-steps`. Fehlt das Attribut oder ist es unlesbar, bleibt die Tabelle leer, und
        // `_roundUp()` gibt die Eingabe unverändert zurück; ohne Stufe wird also nicht geraten.
        this._roundingSteps = this._parseRoundingSteps(this.el.dataset.roundingSteps);

        this._lineItemIdInput = this._form
            ? this._form.querySelector('[name="lineItems[' + this._productId + '][id]"]')
            : null;

        // Gebunden, damit `destroy()` dieselben Funktionen wieder abmelden kann.
        this._boundOnFocus               = this._onFocus.bind(this);
        this._boundOnInput               = this._onInput.bind(this);
        this._boundOnKeydown             = this._onKeydown.bind(this);
        this._boundOnChange              = this._onChange.bind(this);
        this._boundOnForeignSuffixChange = this._onForeignSuffixChanged.bind(this);

        this._disableSubmit();
        this._registerEvents();
    }

    destroy() {
        if (this._input) {
            this._input.removeEventListener('focus', this._boundOnFocus);
            this._input.removeEventListener('input', this._boundOnInput);
            this._input.removeEventListener('keydown', this._boundOnKeydown);
            this._input.removeEventListener('change', this._boundOnChange);
        }

        if (this._form) {
            this._form.removeEventListener(
                DynamicPricePlugin.SUFFIX_CHANGED_EVENT,
                this._boundOnForeignSuffixChange,
            );
        }

        super.destroy();
    }

    _registerEvents() {
        this._input.addEventListener('focus', this._boundOnFocus);
        this._input.addEventListener('input', this._boundOnInput);
        this._input.addEventListener('keydown', this._boundOnKeydown);
        this._input.addEventListener('change', this._boundOnChange);

        // Ändert eine andere Erweiterung ihren Suffix, wird die Kennung neu berechnet. Dieses Plugin meldet
        // das Ereignis auch selbst; der Handler übergeht deshalb die eigenen Meldungen.
        this._form.addEventListener(
            DynamicPricePlugin.SUFFIX_CHANGED_EVENT,
            this._boundOnForeignSuffixChange,
        );
    }

    /**
     * Reagiert auf `rcSuffixChanged` anderer Erweiterungen. Eigene Meldungen erkennt er an
     * `detail.source` und übergeht sie; sonst riefe jede Meldung die nächste hervor.
     */
    _onForeignSuffixChanged(event) {
        if (event?.detail?.source === 'rcDynamicPrice') {
            return;
        }

        const mm = parseInt(this._hidden.value, 10);
        if (mm > 0) {
            this._updateMeterState(mm);
        }
    }

    _onFocus() {
        if (this._hintShown) {
            return;
        }

        this._hintShown = true;
        const hintText = this.el.dataset.hintText || '';
        if (!hintText) {
            return;
        }

        this._showHintModal(hintText);
    }

    _showHintModal(text) {
        // Gibt es kein vorher fokussiertes Element, geht der Fokus nach dem Schließen ins Längenfeld zurück.
        const modal = new HintModal({
            text,
            buttonLabel: this.el.dataset.snippetModalButton || 'OK',
            titleId: 'rc-dynamic-price-modal-title-' + this._productId,
            fallbackFocusEl: this._input,
        });

        modal.open();
    }

    _onKeydown(event) {
        // Neben Ziffern das, was eine Einheit braucht: Komma, Punkt, Leerzeichen und die Buchstaben von
        // „mm", „cm" und „m". Tastenkürzel wie Strg+V bleiben frei.
        const allowed = ['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab', 'Home', 'End', 'Enter'];
        if (event.ctrlKey || event.metaKey || allowed.includes(event.key)) {
            return;
        }

        if (!/^[\d.,\s]$/.test(event.key) && !/^[cm]$/i.test(event.key)) {
            event.preventDefault();
        }
    }

    /**
     * Beim Verlassen des Feldes steht die Länge in Millimetern da, wie sie berechnet wird. Wer „4,2 m"
     * tippt, sieht danach „4200"; die Einheit am Feld sagt „mm".
     */
    _onChange() {
        const mm = parseInt(this._hidden.value, 10);
        const parsed = this._parse(this._input.value.trim());

        if (parsed === null || mm !== parsed || String(mm) === this._input.value.trim()) {
            return;
        }

        this._input.value = String(mm);
    }

    _onInput() {
        const raw = this._input.value.trim();

        if (raw === '') {
            this._clearError();
            this._clearSplitInfo();
            this._resetInput();
            return;
        }

        const ask = parseLength(raw);

        // „4" ohne Einheit: als Millimeter sinnlos, als Meter geraten. Die Frage steht, bis der Kunde
        // weitertippt; aus „4" wird beim Tippen von „4200" ohnehin gleich eine eindeutige Zahl.
        if (ask !== null && ask.ask !== undefined) {
            this._showError((this.el.dataset.snippetAskUnit || '%value% m?').split('%value%').join(String(ask.ask)));
            this._clearSplitInfo();
            this._resetInput();
            return;
        }

        const mm = this._parse(raw);

        if (mm === null) {
            this._showError(this.el.dataset.snippetErrorInteger || 'Invalid input');
            this._clearSplitInfo();
            this._resetInput();
            return;
        }

        const min = parseInt(this.el.dataset.minLength, 10) || 1;
        const max = parseInt(this.el.dataset.maxLength, 10) || 10000;
        const locale = document.documentElement.lang || 'de-DE';

        if (mm < min) {
            const msg = (this.el.dataset.snippetErrorMin || 'Min: %minLength% mm')
                .replace('%minLength%', min.toLocaleString(locale));
            this._showError(msg);
            this._clearSplitInfo();
            this._resetInput();
            return;
        }

        if (mm > max) {
            const msg = (this.el.dataset.snippetErrorMax || 'Max: %maxLength% mm')
                .replace('%maxLength%', max.toLocaleString(locale));
            this._showError(msg);
            this._clearSplitInfo();
            this._resetInput();
            return;
        }

        const configuredSplitMode = this.el.dataset.splitMode || '';
        const maxPiece = parseInt(this.el.dataset.maxPieceLength, 10) || 0;

        // Steuert eine Erweiterung mit höherer Rangfolge die Kennung, gelingt das automatische Aufteilen
        // nicht: Die anderen Erweiterungen lösen kein eigenes `BeforeLineItemAdded` aus, und die Eingaben
        // aus TMMS oder Zusatzfeldern gingen verloren. Dann gilt der Hinweis-Modus.
        const hasForeignIdController = this._hasForeignIdController();

        const splitMode = (hasForeignIdController && (configuredSplitMode === 'equal' || configuredSplitMode === 'max_rest'))
            ? 'hint'
            : configuredSplitMode;

        // Hinweis-Modus: Eine Eingabe über der größten Teilstücklänge wird abgewiesen; der Kunde teilt selbst auf.
        if (splitMode === 'hint' && maxPiece > 0 && mm > maxPiece) {
            const template = this.el.dataset.splitHintTemplate
                || this.el.dataset.snippetErrorMaxPiece
                || 'Max piece length: %maxPiece% mm';
            const preview = this._previewSplit(mm, maxPiece, 'equal');
            this._clearError();
            this._showBlockingInfo(this._renderSplitText(template, mm, maxPiece, preview));
            this._resetInput();
            return;
        }

        this._clearError();

        // Greift die Teilstückgrenze, steht die Aufteilung als Vorschau unter dem Feld.
        const splitActive = (splitMode === 'equal' || splitMode === 'max_rest') && maxPiece > 0 && mm > maxPiece;
        const pieces = splitActive ? this._previewSplit(mm, maxPiece, splitMode) : [mm];

        if (splitActive) {
            const template = this.el.dataset.splitHintTemplate || '';
            if (template) {
                this._showSplitInfo(this._renderSplitText(template, mm, maxPiece, pieces));
            }
        } else {
            this._clearSplitInfo();
        }

        this._hidden.value = mm;
        this._updateMeterState(mm);
        this._enableSubmit();

        // Berechnet wird die Summe der abgerechneten Teilstücke, nicht die gerundete Eingabe. Beides fällt
        // auseinander, sobald ein Reststück mit der Mindestlänge berechnet wird; auf der Eingabe gerechnet,
        // zeigte die Vorschau einen zu niedrigen Preis.
        const billedMm = this._billedPieces(pieces, splitMode, min).reduce((sum, piece) => sum + piece, 0);
        this._updatePrice(billedMm);

        // Ein Teilstück unter der Mindestlänge wird geschnitten wie bestellt, aber mit der
        // Mindestlänge berechnet. Das ist der Aufpreis, den der Kunde vor dem Klick sehen muss.
        const shortPiece = this._billsShortPiecesAtMinimum(splitMode) && min > 0
            ? pieces.find((piece) => piece < min)
            : undefined;

        if (shortPiece !== undefined) {
            this._showMinPieceUpliftHint(mm, billedMm, shortPiece, min);
        } else if (billedMm !== mm) {
            this._showRoundUpHint(mm, billedMm);
        } else {
            // Sonst bliebe der Hinweis einer früheren, gerundeten Eingabe stehen, und die Statusregion für
            // Vorleseprogramme meldete eine falsche Länge.
            this._clearRoundUpHint();
        }
    }

    /**
     * Die Eingabe in Millimetern, mit oder ohne Einheit (`util/parse-length.js`), oder `null`.
     */
    _parse(value) {
        const parsed = parseLength(value);

        return parsed !== null && parsed.mm !== undefined ? parsed.mm : null;
    }

    /**
     * Ein Plugin mit höherer ID-Priorität kennzeichnet sich laut Interaktionsprotokoll entweder
     * per `data-rc-id-controller` an einem Nachkommen des Formulars oder — ohne eigenes
     * DOM-Element — per `dataset.rcIdController` am Formular selbst. `querySelector` deckt nur
     * den ersten Weg ab, deshalb beide prüfen.
     *
     * Alle Aufrufer gehen über diese Methode: Vorschau und ID-Setzung müssen dieselbe Antwort
     * bekommen, sonst rechnet die Storefront anders als der Server.
     */
    _hasForeignIdController() {
        if (!this._form) {
            return false;
        }

        return this._form.dataset.rcIdController === 'true'
            || this._form.querySelector('[data-rc-id-controller]') !== null;
    }

    _updateMeterState(mm) {
        if (!this._form || !this._productId) {
            return;
        }

        const suffix = mm ? ('mm' + mm) : '';
        this._form.dataset.rcMeterSuffix = suffix;

        this._dispatchSuffixChanged({ mm: mm, suffix: suffix });

        // Steuert eine Erweiterung mit höherer Rangfolge die Kennung, setzt sie sie auch; hier nur melden.
        if (this._hasForeignIdController()) {
            return;
        }

        if (this._lineItemIdInput) {
            // Alle Suffixe am Formular gehören in die Kennung, nicht nur der eigene.
            const allSuffixes = this._collectAllSuffixes();
            this._lineItemIdInput.value = allSuffixes
                ? (this._productId + '-' + allSuffixes)
                : this._productId;
        }
    }

    /**
     * Meldet `rcSuffixChanged`, das das Suffix-Protokoll verlangt, und zusätzlich `rcMeterLengthChanged`
     * für Zuhörer, die sich nur für die Länge interessieren.
     */
    _dispatchSuffixChanged(detail) {
        const payload = { source: 'rcDynamicPrice', ...detail };

        this._form.dispatchEvent(new CustomEvent(DynamicPricePlugin.SUFFIX_CHANGED_EVENT, { detail: payload }));
        this._form.dispatchEvent(new CustomEvent('rcMeterLengthChanged', { detail }));
    }

    /**
     * Sammelt alle `rc…Suffix`-Angaben am Formular, auch die anderer Erweiterungen wie RcColorPicker.
     * Sortiert wird nach dem Wert, damit dieselbe Auswahl dieselbe Kennung ergibt, gleich in welcher
     * Reihenfolge die Erweiterungen ihre Suffixe setzen; gleiche Kennung heißt im Warenkorb: Menge
     * erhöhen statt neue Position.
     */
    _collectAllSuffixes() {
        const parts = [];
        const dataset = this._form.dataset;

        for (const key in dataset) {
            if (key.startsWith('rc') && key.endsWith('Suffix') && dataset[key]) {
                parts.push(dataset[key]);
            }
        }

        return parts.sort().join('-');
    }

    _showRoundUpHint(inputMm, billedMm) {
        const locale = document.documentElement.lang || 'de-DE';
        const msg = (this.el.dataset.snippetRoundUp || 'Input %input% mm → billed %billed% mm')
            .replace('%input%', inputMm.toLocaleString(locale))
            .replace('%billed%', billedMm.toLocaleString(locale));

        // Der Rundungshinweis ist eine Statusmeldung, kein Fehler. Er steht in einer höflichen Statusregion
        // (WCAG 4.1.3), damit Vorleseprogramme den Lesefluss nicht unterbrechen.
        if (this._infoEl) {
            this._infoEl.textContent = msg;
            this._infoEl.hidden = false;
        }
        this._input.classList.remove('is-invalid');
        this._input.setAttribute('aria-invalid', 'false');
    }

    /**
     * Weist die Mehrlänge aus, die entsteht, wenn ein Reststück unter der Mindestlänge liegt und darauf
     * angehoben wird. Der Kunde zahlt dann mehr, als er eingegeben hat, und soll das vor dem Klick auf
     * „In den Warenkorb" erfahren statt auf der Rechnung.
     */
    _showMinPieceUpliftHint(inputMm, billedMm, remainderMm, minLengthMm) {
        const locale = document.documentElement.lang || 'de-DE';
        const template = this.el.dataset.snippetMinPieceUplift
            || 'Input %input% mm → billed %billed% mm';

        const msg = template
            .replace(/\{remainder\}/g, remainderMm.toLocaleString(locale))
            .replace(/\{minLength\}/g, minLengthMm.toLocaleString(locale))
            .replace(/\{billed\}/g, billedMm.toLocaleString(locale))
            .replace(/\{input\}/g, inputMm.toLocaleString(locale))
            .replace('%input%', inputMm.toLocaleString(locale))
            .replace('%billed%', billedMm.toLocaleString(locale));

        if (this._infoEl) {
            this._infoEl.textContent = msg;
            this._infoEl.hidden = false;
        }
        this._input.classList.remove('is-invalid');
        this._input.setAttribute('aria-invalid', 'false');
    }

    _clearRoundUpHint() {
        if (this._infoEl) {
            this._infoEl.textContent = '';
            this._infoEl.hidden = true;
        }
    }

    _roundUp(mm) {
        const mode = this.el.dataset.roundingMode || 'none';
        const step = this._roundingSteps[mode] || 0;

        if (step <= 0) {
            return mm;
        }

        return Math.ceil(mm / step) * step;
    }

    _parseRoundingSteps(raw) {
        if (!raw) {
            return {};
        }

        try {
            const parsed = JSON.parse(raw);
            return (parsed && typeof parsed === 'object') ? parsed : {};
        } catch (_e) {
            return {};
        }
    }

    /**
     * Berechnet die Schnittlängen wie `Service\LengthSplitter` auf dem Server. Weicht eine der beiden
     * Rechnungen ab, zeigt die Vorschau eine andere Aufteilung als der Warenkorb.
     *
     * Die Mindestlänge kommt hier nicht vor: Sie ist eine Abrechnungsregel und wirkt erst in
     * `_billedPieces()`. Geschnitten wird, was der Kunde bestellt hat; 5.100 mm ergeben 5.000 und 100 mm.
     */
    _previewSplit(total, maxPiece, mode) {
        if (maxPiece <= 0 || total <= maxPiece) {
            return [total];
        }

        const dataset = this.el?.dataset ?? {};
        const equalBilling = dataset.equalBilling === 'exact' ? 'exact' : 'cut_length';

        if (mode === 'equal') {
            const n = Math.ceil(total / maxPiece);
            if (equalBilling === 'exact') {
                // Genaue Verteilung, die Summe ist die Eingabe: Die ersten `remainder` Stücke sind 1 mm länger.
                const base = Math.floor(total / n);
                const remainder = total - base * n;
                const pieces = [];
                for (let i = 0; i < n; i++) {
                    pieces.push(i < remainder ? base + 1 : base);
                }
                return pieces;
            }

            // Gleiche Schnittlänge: Jedes Stück bekommt dieselbe, aufgerundete Länge.
            return Array(n).fill(Math.ceil(total / n));
        }

        if (mode === 'max_rest') {
            const fullPieces = Math.floor(total / maxPiece);
            const rest = total - fullPieces * maxPiece;
            const pieces = Array(fullPieces).fill(maxPiece);
            if (rest > 0) {
                pieces.push(rest);
            }
            return pieces;
        }

        return [total];
    }

    /**
     * Rechnet die Schnittlängen in Abrechnungslängen um, wie `DynamicPriceProcessor` auf dem Server.
     *
     * Zuerst auf die Mindestlänge anheben, dann jedes Stück einzeln aufrunden. Ein Reststück von
     * 100 mm wird geschnitten, aber mit der Mindestlänge von 1.000 mm berechnet.
     */
    _billedPieces(pieces, mode, min) {
        const raise = this._billsShortPiecesAtMinimum(mode) && min > 0;

        return pieces.map((piece) => this._roundUp(raise ? Math.max(piece, min) : piece));
    }

    /**
     * Werden Teilstücke unter der Mindestlänge mit der Mindestlänge abgerechnet?
     * Bei `max_rest` immer, bei `equal` nach der Einstellung des Händlers; wie `CartItemSplitAssembler`.
     */
    _billsShortPiecesAtMinimum(mode) {
        if (mode === 'max_rest') {
            return true;
        }

        if (mode === 'equal') {
            return (this.el?.dataset ?? {}).equalEnforceMin !== '0';
        }

        return false;
    }

    /**
     * Füllt die Platzhalter `{length}`, `{maxPiece}`, `{pieces}`, `{pieceLength}` und `{remainder}` im
     * Hinweistext. Als Reststück gilt das letzte Teilstück, sobald es mehr als eines gibt.
     */
    _renderSplitText(template, length, maxPiece, pieces) {
        const locale = document.documentElement.lang || 'de-DE';
        const pieceLength = pieces.length > 0 ? pieces[0] : 0;
        const remainder = pieces.length > 1 ? pieces[pieces.length - 1] : 0;

        return template
            .replace(/\{length\}/g, length.toLocaleString(locale))
            .replace(/\{maxPiece\}/g, maxPiece.toLocaleString(locale))
            .replace(/\{pieces\}/g, String(pieces.length))
            .replace(/\{pieceLength\}/g, pieceLength.toLocaleString(locale))
            .replace(/\{remainder\}/g, remainder.toLocaleString(locale));
    }

    _showSplitInfo(html) {
        if (!this._splitInfoEl) {
            return;
        }

        this._splitInfoEl.textContent = html;
        this._splitInfoEl.classList.remove('alert-warning');
        this._splitInfoEl.classList.add('alert-info');
        this._splitInfoEl.hidden = false;
    }

    /**
     * Ein Hinweis, der den Kauf aufhält, aber nicht wie ein Fehler aussieht: Im Hinweis-Modus soll der
     * Kunde nicht glauben, er habe etwas falsch gemacht.
     */
    _showBlockingInfo(html) {
        if (!this._splitInfoEl) {
            return;
        }

        this._splitInfoEl.textContent = html;
        this._splitInfoEl.classList.remove('alert-info');
        this._splitInfoEl.classList.add('alert-warning');
        this._splitInfoEl.hidden = false;
    }

    _clearSplitInfo() {
        if (!this._splitInfoEl) {
            return;
        }

        this._splitInfoEl.textContent = '';
        this._splitInfoEl.hidden = true;
    }

    _updatePrice(mm) {
        const basePrice = parseFloat(this.el.dataset.basePrice);
        if (!basePrice) {
            return;
        }

        // Der Grundpreis gilt je Meter, die Länge steht in Millimetern.
        const price    = (basePrice / 1000) * mm;
        const currency = this.el.dataset.currency || 'EUR';
        const locale   = document.documentElement.lang || 'de-DE';

        const formatted = new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: currency,
        }).format(price);

        this._resultPrice.textContent = formatted;
        this._resultEl.hidden = false;

        if (this._productPrice) {
            this._productPrice.textContent = formatted;
        }
    }

    _clearResult() {
        this._resultEl.hidden = true;
        this._resultPrice.textContent = '';
        this._hidden.value = '';

        if (this._productPrice && this._originalPriceHtml) {
            this._productPrice.innerHTML = this._originalPriceHtml;
        }
    }

    _showError(message) {
        this._errorEl.textContent = message;
        this._errorEl.hidden = false;
        this._errorEl.classList.remove('text-info');
        this._errorEl.classList.add('text-danger');
        this._input.classList.add('is-invalid');
        this._input.setAttribute('aria-invalid', 'true');
    }

    _clearError() {
        this._errorEl.hidden = true;
        this._errorEl.classList.remove('text-info', 'text-danger');
        this._input.classList.remove('is-invalid');
        this._input.setAttribute('aria-invalid', 'false');
    }

    /** Setzt Ergebnis, Kaufknopf und Längenangabe zurück; der gemeinsame Weg jeder ungültigen Eingabe. */
    _resetInput() {
        this._clearResult();
        this._clearRoundUpHint();
        this._disableSubmit();
        this._updateMeterState(null);
    }

    _disableSubmit() {
        if (this._submitBtn) {
            this._submitBtn.disabled = true;
        }
    }

    _enableSubmit() {
        if (this._submitBtn) {
            this._submitBtn.disabled = false;
        }
    }
}
