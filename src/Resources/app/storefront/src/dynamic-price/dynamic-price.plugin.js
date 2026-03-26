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

        // LineItem-ID-Input aus dem Shopware-Formular (gesetzt im buy_widget_buy_product_buy_info-Block)
        this._lineItemIdInput = this._form
            ? this._form.querySelector('[name="lineItems[' + this._productId + '][id]"]')
            : null;

        this._disableSubmit();
        this._registerEvents();
    }

    _registerEvents() {
        this._input.addEventListener('focus', this._onFocus.bind(this));
        this._input.addEventListener('input', this._onInput.bind(this));
        this._input.addEventListener('keydown', this._onKeydown.bind(this));
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
        const backdrop = document.createElement('div');
        backdrop.className = 'rc-dynamic-price-backdrop';

        const modal = document.createElement('div');
        modal.className = 'rc-dynamic-price-modal';
        modal.innerHTML =
            '<div class="rc-dynamic-price-modal__content">' +
                '<p>' + this._escapeHtml(text) + '</p>' +
                '<button type="button" class="btn btn-primary btn-sm rc-dynamic-price-modal__close">Verstanden</button>' +
            '</div>';

        document.body.appendChild(backdrop);
        document.body.appendChild(modal);

        const close = () => {
            backdrop.remove();
            modal.remove();
            this._input.focus();
        };

        modal.querySelector('.rc-dynamic-price-modal__close').addEventListener('click', close);
        backdrop.addEventListener('click', close);
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
            this._clearResult();
            this._disableSubmit();
            this._updateMeterState(null);
            return;
        }

        const mm = this._parse(raw);

        if (mm === null) {
            this._showError('Bitte nur positive ganze Zahlen eingeben.');
            this._clearResult();
            this._disableSubmit();
            this._updateMeterState(null);
            return;
        }

        const min = parseInt(this.el.dataset.minLength, 10) || 1;
        const max = parseInt(this.el.dataset.maxLength, 10) || 10000;

        if (mm < min) {
            this._showError('Mindestlänge: ' + min.toLocaleString('de-DE') + ' mm');
            this._clearResult();
            this._disableSubmit();
            this._updateMeterState(null);
            return;
        }

        if (mm > max) {
            this._showError('Maximallänge: ' + max.toLocaleString('de-DE') + ' mm');
            this._clearResult();
            this._disableSubmit();
            this._updateMeterState(null);
            return;
        }

        this._clearError();

        // Originaleingabe speichern — das ist die Schnittlänge
        this._hidden.value = mm;
        this._updateMeterState(mm);
        this._enableSubmit();

        // Preis auf Basis der berechneten Länge (ggf. aufgerundet)
        const roundUp = this.el.dataset.roundUpMeter === '1';
        const billedMm = roundUp ? Math.ceil(mm / 1000) * 1000 : mm;
        this._updatePrice(billedMm);

        if (roundUp && billedMm !== mm) {
            this._showRoundUpHint(mm, billedMm);
        }
    }

    _parse(value) {
        if (!value || !/^[1-9]\d*$/.test(value)) {
            return null;
        }
        return parseInt(value, 10);
    }

    /**
     * Setzt den Meter-Suffix als data-Attribut auf dem Formular und feuert ein Event.
     * RcCustomFields kann diesen Suffix in seinen ID-Hash einbeziehen.
     * Wenn RcCustomFields nicht aktiv ist, setzt dieses Plugin die LineItem-ID direkt.
     */
    _updateMeterState(mm) {
        if (!this._form || !this._productId) {
            return;
        }

        const suffix = mm ? ('mm' + mm) : '';
        this._form.dataset.rcMeterSuffix = suffix;

        this._form.dispatchEvent(new CustomEvent('rcMeterLengthChanged', {
            detail: { mm: mm, suffix: suffix },
        }));

        // Wenn RcCustomFields aktiv ist, überlässt es diesem Plugin die finale ID
        const hasCustomFields = this._form.querySelector('[data-rc-custom-fields]');
        if (hasCustomFields) {
            return;
        }

        // Kein RcCustomFields → ID direkt setzen
        if (this._lineItemIdInput) {
            this._lineItemIdInput.value = mm
                ? (this._productId + '-' + suffix)
                : this._productId;
        }
    }

    _showRoundUpHint(inputMm, billedMm) {
        this._errorEl.textContent =
            'Eingabe ' + inputMm.toLocaleString('de-DE') + ' mm → berechnet werden '
            + billedMm.toLocaleString('de-DE') + ' mm (auf vollen Meter gerundet)';
        this._errorEl.hidden = false;
        this._errorEl.classList.remove('text-danger');
        this._errorEl.classList.add('text-info');
        this._input.classList.remove('is-invalid');
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
        this._errorEl.classList.remove('text-info');
        this._errorEl.classList.add('text-danger');
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
