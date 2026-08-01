import Plugin from 'src/plugin-system/plugin.class';
import HintModal from '../util/hint-modal';

export default class DynamicPricePlugin extends Plugin {

    // Generisches Suffix-Event des Ruhrcoder-Plugin-Interaktionsprotokolls.
    // Neutraler Namespace — kein Plugin owned den Namen, jedes Suffix-Plugin feuert ihn.
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

        // Rundungs-Stufen werden vom Server (MeterProductHelper::ROUNDING_STEPS) als JSON-Map
        // ins data-rounding-steps geschrieben. Bei Parse-Fehler oder fehlendem Attribut bleibt
        // die Map leer — _roundUp() liefert dann den Eingabewert unverändert (sicheres Fallback).
        this._roundingSteps = this._parseRoundingSteps(this.el.dataset.roundingSteps);

        this._lineItemIdInput = this._form
            ? this._form.querySelector('[name="lineItems[' + this._productId + '][id]"]')
            : null;

        // Gebundene Event-Handler für sauberes Cleanup in destroy()
        this._boundOnFocus               = this._onFocus.bind(this);
        this._boundOnInput               = this._onInput.bind(this);
        this._boundOnKeydown             = this._onKeydown.bind(this);
        this._boundOnForeignSuffixChange = this._onForeignSuffixChanged.bind(this);

        this._disableSubmit();
        this._registerEvents();
    }

    destroy() {
        if (this._input) {
            this._input.removeEventListener('focus', this._boundOnFocus);
            this._input.removeEventListener('input', this._boundOnInput);
            this._input.removeEventListener('keydown', this._boundOnKeydown);
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

        // Generisches Suffix-Protokoll: ID neu berechnen, wenn ein anderes Plugin seinen Suffix ändert.
        // Self-Loop-Guard im Handler — RcDynamicPrice feuert das Event auch selbst.
        this._form.addEventListener(
            DynamicPricePlugin.SUFFIX_CHANGED_EVENT,
            this._boundOnForeignSuffixChange,
        );
    }

    /**
     * Reagiert auf rcSuffixChanged-Events von Sibling-Plugins. Eigene Dispatches werden per
     * detail.source ausgefiltert (Self-Loop-Schutz).
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
        // Modal-Logik per Komposition (HintModal) — Verhalten identisch zur früheren
        // Inline-Implementierung. Fallback-Fokus geht zurück auf das Input-Feld.
        const modal = new HintModal({
            text,
            buttonLabel: this.el.dataset.snippetModalButton || 'OK',
            titleId: 'rc-dynamic-price-modal-title-' + this._productId,
            fallbackFocusEl: this._input,
        });

        modal.open();
    }

    _onKeydown(event) {
        const allowed = ['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab', 'Home', 'End'];
        if (!allowed.includes(event.key) && !/^\d$/.test(event.key)) {
            event.preventDefault();
        }
    }

    _onInput() {
        const raw = this._input.value.trim();

        if (raw === '') {
            this._clearError();
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

        // Wenn ein Plugin mit höherer ID-Priorität am Form ist, läuft Auto-Split
        // nicht sauber durch (Siblings dispatchen kein eigenes BeforeLineItemAdded-Event,
        // TMMS-/Custom-Field-Payload würde verloren gehen). Fallback: Hint-Verhalten.
        const hasForeignIdController = this._hasForeignIdController();

        const splitMode = (hasForeignIdController && (configuredSplitMode === 'equal' || configuredSplitMode === 'max_rest'))
            ? 'hint'
            : configuredSplitMode;

        // Hint-Modus: Eingabe oberhalb maxPiece wird abgewiesen, Kunde muss selbst aufteilen
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

        // Auto-Split-Vorschau als Info, wenn eine Teilstückgrenze greift
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

        // Berechnet wird die Summe der abgerechneten Teilstücke — nicht die gerundete Eingabe.
        // Beides fällt auseinander, sobald ein Reststück mit der Mindestlänge berechnet wird.
        // Die Vorschau rechnete bisher auf der Eingabe und zeigte dadurch einen zu niedrigen Preis.
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
            // Ohne dieses Zurücksetzen bliebe der Hinweis einer früheren, gerundeten
            // Eingabe stehen und die aria-live-Region meldete eine falsche Länge.
            this._clearRoundUpHint();
        }
    }

    _parse(value) {
        if (!value || !/^[1-9]\d*$/.test(value)) {
            return null;
        }
        return parseInt(value, 10);
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

        // ID-Setzung delegieren, wenn ein Plugin mit höherer Priorität vorhanden ist.
        if (this._hasForeignIdController()) {
            return;
        }

        if (this._lineItemIdInput) {
            // Generisches Suffix-Protokoll: Alle rc*Suffix-Attribute einbeziehen
            const allSuffixes = this._collectAllSuffixes();
            this._lineItemIdInput.value = allSuffixes
                ? (this._productId + '-' + allSuffixes)
                : this._productId;
        }
    }

    /**
     * Feuert das generische rcSuffixChanged-Event (Pflicht laut Plugin-Interaktionsprotokoll)
     * UND das plugin-spezifische rcMeterLengthChanged-Event (Hook für interne Listener / Bestand).
     */
    _dispatchSuffixChanged(detail) {
        const payload = { source: 'rcDynamicPrice', ...detail };

        this._form.dispatchEvent(new CustomEvent(DynamicPricePlugin.SUFFIX_CHANGED_EVENT, { detail: payload }));
        this._form.dispatchEvent(new CustomEvent('rcMeterLengthChanged', { detail }));
    }

    /**
     * Sammelt alle rc*Suffix-Data-Attribute vom Formular.
     * Damit werden Suffixe anderer Plugins (RcColorPicker, etc.) automatisch in die ID einbezogen.
     * Sortiert wird alphabetisch auf dem Suffix-Wert (nicht auf dem Key), damit die erzeugte
     * LineItem-ID stabil bleibt, unabhängig von der Reihenfolge, in der Plugins ihre Suffixe setzen.
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

        // Rundungs-Hinweis ist Status, kein Fehler — eigene polite-Region (BFSG WCAG 4.1.3),
        // damit Screenreader den Lesefluss nicht abrupt unterbrechen.
        if (this._infoEl) {
            this._infoEl.textContent = msg;
            this._infoEl.hidden = false;
        }
        this._input.classList.remove('is-invalid');
        this._input.setAttribute('aria-invalid', 'false');
    }

    /**
     * Weist die Mehrlänge aus, die entsteht, wenn ein Reststück unter der Mindestlänge liegt
     * und darauf angehoben wird. Der Kunde zahlt dann mehr, als er eingegeben hat — das muss er
     * vor dem Klick auf "In den Warenkorb" erfahren, nicht erst auf der Rechnung.
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
     * Berechnet die Schnittlängen analog zum serverseitigen LengthSplitter.
     * Muss identisch bleiben mit Service\LengthSplitter — rein numerische Logik.
     *
     * Die Mindestlänge kommt hier nicht vor: Sie ist eine Abrechnungsregel und wirkt erst in
     * _billedPieces(). Geschnitten wird, was der Kunde bestellt hat — 5.100 mm ergeben 5.000 + 100 mm.
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
                // Exakte Verteilung (Summe == total): erste remainder Stücke 1 mm länger.
                const base = Math.floor(total / n);
                const remainder = total - base * n;
                const pieces = [];
                for (let i = 0; i < n; i++) {
                    pieces.push(i < remainder ? base + 1 : base);
                }
                return pieces;
            }

            // Schnittlänge: jedes Stück auf dieselbe aufgerundete Länge.
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
     * Rechnet die Schnittlängen in Abrechnungslängen um — spiegelt DynamicPriceProcessor.
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
     * max_rest immer, equal nach der Händler-Option — identisch zu CartItemSplitAssembler.
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
     * Ersetzt die Platzhalter im Hinweis-Template durch die berechneten Werte.
     * Platzhalter: {length}, {maxPiece}, {pieces}, {pieceLength}, {remainder}
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
     * Blockierender Hinweis (Kunde muss aktiv handeln), aber kein Fehler-Stil.
     * Wird im Hint-Modus genutzt, damit der Kunde nicht denkt, er habe etwas falsch gemacht.
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

    /** Setzt Ergebnis, Submit und Meter-State zurück — gemeinsamer Pfad bei ungültiger Eingabe. */
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
