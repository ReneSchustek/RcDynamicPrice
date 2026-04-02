import Plugin from 'src/plugin-system/plugin.class';

export default class DynamicPricePlugin extends Plugin {

    init() {
        this._input       = this.el.querySelector('.rc-dynamic-price__input');
        this._hidden      = this.el.querySelector('.rc-dynamic-price__hidden');
        this._errorEl     = this.el.querySelector('.rc-dynamic-price__error');
        this._resultEl    = this.el.querySelector('.rc-dynamic-price__result');
        this._resultPrice = this.el.querySelector('.rc-dynamic-price__result-price');
        this._form        = this.el.closest('form');
        this._submitBtn   = this._form ? this._form.querySelector('[type="submit"]') : null;
        this._productId   = this.el.dataset.productId;

        this._productPrice = document.querySelector('.product-detail-price');
        this._originalPriceHtml = this._productPrice ? this._productPrice.innerHTML : '';

        this._hintShown = false;

        this._lineItemIdInput = this._form
            ? this._form.querySelector('[name="lineItems[' + this._productId + '][id]"]')
            : null;

        // Gebundene Event-Handler für sauberes Cleanup in destroy()
        this._boundOnFocus   = this._onFocus.bind(this);
        this._boundOnInput   = this._onInput.bind(this);
        this._boundOnKeydown = this._onKeydown.bind(this);

        this._disableSubmit();
        this._registerEvents();
    }

    destroy() {
        if (this._input) {
            this._input.removeEventListener('focus', this._boundOnFocus);
            this._input.removeEventListener('input', this._boundOnInput);
            this._input.removeEventListener('keydown', this._boundOnKeydown);
        }

        super.destroy();
    }

    _registerEvents() {
        this._input.addEventListener('focus', this._boundOnFocus);
        this._input.addEventListener('input', this._boundOnInput);
        this._input.addEventListener('keydown', this._boundOnKeydown);

        // Generisches Suffix-Protokoll: ID neu berechnen wenn andere Plugins ihren Suffix aendern
        this._form.addEventListener('rcColorPickerChanged', () => {
            const mm = parseInt(this._hidden.value, 10);
            if (mm > 0) {
                this._updateMeterState(mm);
            }
        });
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
        const buttonLabel = this.el.dataset.snippetModalButton || 'OK';

        const backdrop = document.createElement('div');
        backdrop.className = 'rc-dynamic-price-backdrop';

        const modal = document.createElement('div');
        modal.className = 'rc-dynamic-price-modal';
        modal.innerHTML =
            '<div class="rc-dynamic-price-modal__content">' +
                '<p>' + this._escapeHtml(text) + '</p>' +
                '<button type="button" class="btn btn-primary btn-sm rc-dynamic-price-modal__close">'
                    + this._escapeHtml(buttonLabel) +
                '</button>' +
            '</div>';

        document.body.appendChild(backdrop);
        document.body.appendChild(modal);

        const onEscape = (e) => {
            if (e.key === 'Escape') {
                close();
            }
        };

        const close = () => {
            backdrop.remove();
            modal.remove();
            document.removeEventListener('keydown', onEscape);
            this._input.focus();
        };

        modal.querySelector('.rc-dynamic-price-modal__close').addEventListener('click', close);
        backdrop.addEventListener('click', close);
        document.addEventListener('keydown', onEscape);
    }

    _escapeHtml(text) {
        const el = document.createElement('span');
        el.textContent = text;
        return el.innerHTML;
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
            this._resetInput();
            return;
        }

        const mm = this._parse(raw);

        if (mm === null) {
            this._showError(this.el.dataset.snippetErrorInteger || 'Invalid input');
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
            this._resetInput();
            return;
        }

        if (mm > max) {
            const msg = (this.el.dataset.snippetErrorMax || 'Max: %maxLength% mm')
                .replace('%maxLength%', max.toLocaleString(locale));
            this._showError(msg);
            this._resetInput();
            return;
        }

        this._clearError();

        this._hidden.value = mm;
        this._updateMeterState(mm);
        this._enableSubmit();

        const billedMm = this._roundUp(mm);
        this._updatePrice(billedMm);

        if (billedMm !== mm) {
            this._showRoundUpHint(mm, billedMm);
        }
    }

    _parse(value) {
        if (!value || !/^[1-9]\d*$/.test(value)) {
            return null;
        }
        return parseInt(value, 10);
    }

    _updateMeterState(mm) {
        if (!this._form || !this._productId) {
            return;
        }

        const suffix = mm ? ('mm' + mm) : '';
        this._form.dataset.rcMeterSuffix = suffix;

        this._form.dispatchEvent(new CustomEvent('rcMeterLengthChanged', {
            detail: { mm: mm, suffix: suffix },
        }));

        // ID-Setzung delegieren wenn ein Plugin mit hoeherer Prioritaet vorhanden ist
        const hasHigherPriority = this._form.querySelector('[data-rc-id-controller]')
            || this._form.querySelector('[data-rc-custom-fields]')
            || this._form.querySelector('[data-rc-cart-splitter]');
        if (hasHigherPriority) {
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
     * Sammelt alle rc*Suffix-Data-Attribute vom Formular.
     * Damit werden Suffixe anderer Plugins (RcColorPicker, etc.)
     * automatisch in die ID einbezogen.
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

        this._errorEl.textContent = msg;
        this._errorEl.hidden = false;
        this._errorEl.classList.remove('text-danger');
        this._errorEl.classList.add('text-info');
        this._input.classList.remove('is-invalid');
    }

    _roundUp(mm) {
        const mode = this.el.dataset.roundingMode || 'none';
        // Muss identisch sein mit MeterProductHelper::ROUNDING_STEPS
        const steps = { none: 0, cm: 10, quarter_m: 250, half_m: 500, full_m: 1000 };
        const step = steps[mode] || 0;

        if (step <= 0) {
            return mm;
        }

        return Math.ceil(mm / step) * step;
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
    }

    _clearError() {
        this._errorEl.hidden = true;
        this._errorEl.classList.remove('text-info', 'text-danger');
        this._input.classList.remove('is-invalid');
    }

    /** Setzt Ergebnis, Submit und Meter-State zurück — gemeinsamer Pfad bei ungültiger Eingabe. */
    _resetInput() {
        this._clearResult();
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
