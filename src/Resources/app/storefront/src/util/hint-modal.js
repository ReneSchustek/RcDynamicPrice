/**
 * Wiederverwendbarer, barrierearmer Hint-Dialog (Modal).
 *
 * Warum eigene Klasse: die Modal-Logik (DOM-Aufbau, Focus-Trap, Escape-Schließen,
 * Theme-Variablen) war früher inline im DynamicPricePlugin verdrahtet und damit nur
 * schwer isoliert testbar. Hier extrahiert per Komposition — das Verhalten ist
 * unverändert (siehe tests/Js/hint-modal.test.mjs) und für andere Ruhrcoder-Plugins
 * wiederverwendbar.
 *
 * Das `document` ist injizierbar, damit der Dialog außerhalb eines Browsers
 * (Node-Unit-Tests mit Fake-DOM) konstruiert werden kann.
 */
export default class HintModal {
    /**
     * @param {object}      options
     * @param {string}      [options.text]              Hint-Text (wird als Textknoten gesetzt).
     * @param {string}      [options.buttonLabel]       Beschriftung des Schließen-Buttons.
     * @param {string}      [options.titleId]           DOM-ID für aria-labelledby.
     * @param {HTMLElement} [options.fallbackFocusEl]   Fokus-Ziel beim Schließen, falls kein
     *                                                  vorher fokussiertes Element vorliegt.
     * @param {string}      [options.focusableSelector] Selektor für die Focus-Trap.
     * @param {Document}    [options.document]          Injizierbares Dokument (Default: globales document).
     */
    constructor(options = {}) {
        this._text = typeof options.text === 'string' ? options.text : '';
        this._buttonLabel = options.buttonLabel || 'OK';
        this._titleId = options.titleId || 'rc-hint-modal-title';
        this._fallbackFocusEl = options.fallbackFocusEl || null;
        this._document = options.document || (typeof document !== 'undefined' ? document : null);

        // Robust gegen künftige Erweiterungen (Links im Text, weitere Buttons):
        // first/last werden zur Laufzeit ermittelt.
        this._focusableSelector = options.focusableSelector
            || 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])';

        this._backdrop = null;
        this._modal = null;
        this._previouslyFocused = null;

        // Gebundene Handler für sauberes removeEventListener in close().
        this._boundOnEscape = this._onEscape.bind(this);
        this._boundOnTrapFocus = this._onTrapFocus.bind(this);
        this._boundClose = this.close.bind(this);
    }

    /**
     * Baut den Dialog auf, hängt ihn an den Body, aktiviert Focus-Trap und
     * Escape-Schließen und legt den Fokus auf den Schließen-Button.
     * Mehrfach-Aufrufe ohne vorheriges close() sind ein No-Op.
     */
    open() {
        if (this._document === null || this._modal !== null) {
            return;
        }

        const doc = this._document;
        this._previouslyFocused = doc.activeElement;

        this._backdrop = doc.createElement('div');
        this._backdrop.className = 'rc-dynamic-price-backdrop';

        this._modal = doc.createElement('div');
        this._modal.className = 'rc-dynamic-price-modal';
        // role=dialog + aria-modal + aria-labelledby machen den Dialog für Screenreader
        // als modal erkennbar; der Hint-Text dient als Label.
        this._modal.setAttribute('role', 'dialog');
        this._modal.setAttribute('aria-modal', 'true');
        this._modal.setAttribute('aria-labelledby', this._titleId);

        this._modal.appendChild(this._buildContent(doc));

        this.applyThemeVariables();

        doc.body.appendChild(this._backdrop);
        doc.body.appendChild(this._modal);

        const closeButton = this._modal.querySelector('.rc-dynamic-price-modal__close');

        closeButton.addEventListener('click', this._boundClose);
        this._backdrop.addEventListener('click', this._boundClose);
        doc.addEventListener('keydown', this._boundOnEscape);

        this.setupFocusTrap(this._focusableSelector);

        closeButton.focus();
    }

    /**
     * Baut den Dialog-Inhalt rein programmatisch auf.
     *
     * Warum kein innerHTML: Text, Button-Beschriftung und titleId stammen von Aufrufern.
     * Über `textContent` und `setAttribute` kann keiner dieser Werte aus seinem Kontext
     * ausbrechen — bei String-Konkatenation wäre insbesondere die titleId im
     * `id="…"`-Attribut ein Breakout-Vektor.
     *
     * @param  {Document} doc
     * @return {HTMLElement}
     */
    _buildContent(doc) {
        const content = doc.createElement('div');
        content.className = 'rc-dynamic-price-modal__content';

        const title = doc.createElement('p');
        title.setAttribute('id', this._titleId);
        title.textContent = this._text;

        const closeButton = doc.createElement('button');
        closeButton.setAttribute('type', 'button');
        closeButton.className = 'btn btn-primary btn-sm rc-dynamic-price-modal__close';
        closeButton.textContent = this._buttonLabel;

        content.appendChild(title);
        content.appendChild(closeButton);

        return content;
    }

    /**
     * Entfernt den Dialog, räumt die Listener ab und stellt den Fokus auf das
     * vorher fokussierte Element (sonst auf fallbackFocusEl) zurück.
     * Aufruf ohne offenen Dialog ist ein No-Op.
     */
    close() {
        if (this._modal === null) {
            return;
        }

        const doc = this._document;
        const modal = this._modal;
        const previouslyFocused = this._previouslyFocused;

        this._backdrop.remove();
        modal.remove();
        doc.removeEventListener('keydown', this._boundOnEscape);
        modal.removeEventListener('keydown', this._boundOnTrapFocus);

        this._modal = null;
        this._backdrop = null;
        this._previouslyFocused = null;

        if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
            previouslyFocused.focus();
        } else if (this._fallbackFocusEl && typeof this._fallbackFocusEl.focus === 'function') {
            this._fallbackFocusEl.focus();
        }
    }

    /**
     * Aktiviert die Focus-Trap auf dem Dialog: Tab/Shift+Tab zyklen zwischen erstem
     * und letztem fokussierbaren Knoten, statt den Fokus aus dem Modal zu lassen.
     *
     * @param {string} focusableSelector Selektor der fokussierbaren Knoten.
     */
    setupFocusTrap(focusableSelector) {
        if (this._modal === null) {
            return;
        }

        if (typeof focusableSelector === 'string' && focusableSelector !== '') {
            this._focusableSelector = focusableSelector;
        }

        this._modal.addEventListener('keydown', this._boundOnTrapFocus);
    }

    /**
     * Setzt die theme-abhängigen Farben als Inline-Style auf den Dialog.
     *
     * Warum inline statt nur via SCSS: damit der Dialog auch in Plugins ohne das
     * RcDynamicPrice-SCSS im aktiven (Dark-/Custom-)Theme korrekt erscheint. Die
     * Werte spiegeln base.scss eins zu eins (`var(--bs-*, fallback)`) — die
     * gerenderte Darstellung bleibt identisch.
     */
    applyThemeVariables() {
        if (this._modal === null) {
            return;
        }

        const style = this._modal.style;
        style.setProperty('background', 'var(--bs-body-bg, #fff)');
        style.setProperty('color', 'var(--bs-body-color, #212529)');
        style.setProperty('border', '1px solid var(--bs-border-color, rgba(0, 0, 0, .175))');
    }

    _onEscape(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }

    _onTrapFocus(event) {
        if (event.key !== 'Tab' || this._modal === null) {
            return;
        }

        const focusables = this._modal.querySelectorAll(this._focusableSelector);
        if (focusables.length === 0) {
            event.preventDefault();
            return;
        }

        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        const active = this._document.activeElement;

        if (event.shiftKey && active === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    }
}
