import Plugin from 'src/plugin-system/plugin.class';

export default class DynamicPricePlugin extends Plugin {

    init() {
        this._input      = this.el.querySelector('.rc-dynamic-price__input');
        this._hidden     = this.el.querySelector('.rc-dynamic-price__hidden');
        this._errorEl    = this.el.querySelector('.rc-dynamic-price__error');
        this._resultEl   = this.el.querySelector('.rc-dynamic-price__result');
        this._resultPrice = this.el.querySelector('.rc-dynamic-price__result-price');
        this._modal      = this.el.querySelector('.rc-dynamic-price__modal');
        this._form       = this.el.closest('form');
        this._submitBtn  = this._form ? this._form.querySelector('[type="submit"]') : null;

        // Absenden sperren bis eine gültige Länge eingegeben ist
        this._disableSubmit();

        this._registerEvents();
    }

    _registerEvents() {
        this._input.addEventListener('focus', this._onFocus.bind(this));
        this._input.addEventListener('blur',  this._onBlur.bind(this));
        this._input.addEventListener('input', this._onInput.bind(this));
        this._input.addEventListener('keydown', this._onKeydown.bind(this));
    }

    _onFocus() {
        if (this._modal) {
            this._modal.hidden = false;
        }
    }

    _onBlur() {
        if (this._modal) {
            this._modal.hidden = true;
        }
    }

    // Kommazahlen und nicht-numerische Zeichen direkt beim Tippen blockieren
    _onKeydown(event) {
        const allowed = ['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab', 'Home', 'End'];
        if (!allowed.includes(event.key) && !/^\d$/.test(event.key)) {
            event.preventDefault();
        }
    }

    _onInput() {
        const raw = this._input.value.trim();
        const mm  = this._parse(raw);

        if (mm === null) {
            this._showError('Bitte nur positive ganze Zahlen eingeben.');
            this._clearResult();
            this._disableSubmit();
            return;
        }

        const min = parseInt(this.el.dataset.minLength, 10) || 1;
        const max = parseInt(this.el.dataset.maxLength, 10) || 10000;

        if (mm < min) {
            this._showError('Mindestlänge: ' + min.toLocaleString('de-DE') + ' mm');
            this._clearResult();
            this._disableSubmit();
            return;
        }

        if (mm > max) {
            this._showError('Maximallänge: ' + max.toLocaleString('de-DE') + ' mm');
            this._clearResult();
            this._disableSubmit();
            return;
        }

        this._clearError();
        this._hidden.value = mm;
        this._updatePrice(mm);
        this._enableSubmit();
    }

    // Nur positive Ganzzahlen ohne führende Null akzeptieren
    _parse(value) {
        if (!value || !/^[1-9]\d*$/.test(value)) {
            return null;
        }
        return parseInt(value, 10);
    }

    _updatePrice(mm) {
        const basePrice = parseFloat(this.el.dataset.basePrice);
        if (!basePrice) {
            return;
        }

        const price    = (basePrice / 1000) * mm;
        const currency = this.el.dataset.currency || 'EUR';

        // Locale aus HTML-Element bestimmen, Fallback auf de-DE
        const locale = document.documentElement.lang || 'de-DE';

        this._resultPrice.textContent = new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: currency,
        }).format(price);

        this._resultEl.hidden = false;
    }

    _clearResult() {
        this._resultEl.hidden = true;
        this._resultPrice.textContent = '';
        this._hidden.value = '';
    }

    _showError(message) {
        this._errorEl.textContent = message;
        this._errorEl.hidden = false;
        this._input.classList.add('is-invalid');
    }

    _clearError() {
        this._errorEl.hidden = true;
        this._input.classList.remove('is-invalid');
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
