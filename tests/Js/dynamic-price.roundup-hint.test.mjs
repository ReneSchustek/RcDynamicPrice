// Regressionstest: der Rundungs-Hinweis muss verschwinden, sobald eine Eingabe
// nicht mehr gerundet werden muss. Vorher blieb der Text einer früheren Eingabe
// stehen — die aria-live-Region (role="status") meldete damit eine falsche Länge.

import { describe, test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const sourcePath = join(
    __dirname, '..', '..',
    'src', 'Resources', 'app', 'storefront', 'src', 'dynamic-price', 'dynamic-price.plugin.js',
);

const rawSource = readFileSync(sourcePath, 'utf8');
const stripped = rawSource
    .replace(/^import [^\n]*\n/gm, '')
    .replace(/^export default /m, '');

const DynamicPricePlugin = new Function(`
    class Plugin {
        init() {}
        destroy() {}
    }
    ${stripped}
    return DynamicPricePlugin;
`)();

// _onInput liest document.documentElement.lang für toLocaleString.
globalThis.document = { documentElement: { lang: 'de-DE' } };

function makePlugin() {
    const plugin = Object.create(DynamicPricePlugin.prototype);

    plugin.el = {
        dataset: {
            minLength: '1000',
            maxLength: '50000',
            roundingMode: 'full_m',
            splitMode: '',
            maxPieceLength: '0',
            snippetRoundUp: 'Eingabe %input% mm → berechnet werden %billed% mm (gerundet)',
        },
    };
    plugin._roundingSteps = { none: 0, cm: 10, quarter_m: 250, half_m: 500, full_m: 1000 };
    plugin._form = null;
    plugin._productId = null;
    plugin._input = {
        value: '',
        classList: { remove() {}, add() {} },
        setAttribute() {},
    };
    plugin._hidden = { value: '' };
    plugin._infoEl = { textContent: '', hidden: true };

    // Alles, was echtes DOM oder Preis-Rendering braucht, wird neutralisiert.
    plugin._clearError = () => {};
    plugin._showError = () => {};
    plugin._showSplitInfo = () => {};
    plugin._clearSplitInfo = () => {};
    plugin._showBlockingInfo = () => {};
    plugin._updateMeterState = () => {};
    plugin._enableSubmit = () => {};
    plugin._disableSubmit = () => {};
    plugin._updatePrice = () => {};
    plugin._clearResult = () => {};

    return plugin;
}

function typeLength(plugin, value) {
    plugin._input.value = value;
    plugin._onInput();
}

describe('Rundungs-Hinweis (aria-live status)', () => {
    let plugin;

    beforeEach(() => {
        plugin = makePlugin();
    });

    test('erscheint, wenn die Eingabe aufgerundet wird', () => {
        typeLength(plugin, '1234');

        assert.equal(plugin._infoEl.hidden, false);
        assert.match(plugin._infoEl.textContent, /1\.234 mm/);
        assert.match(plugin._infoEl.textContent, /2\.000 mm/);
    });

    test('verschwindet wieder, sobald die Eingabe glatt ist (Regression)', () => {
        typeLength(plugin, '1234');
        assert.equal(plugin._infoEl.hidden, false, 'Vorbedingung: Hinweis steht');

        typeLength(plugin, '3000');

        assert.equal(plugin._infoEl.textContent, '', 'Stehengebliebener Hinweis meldet eine falsche Länge');
        assert.equal(plugin._infoEl.hidden, true);
    });

    test('verschwindet bei geleertem Feld', () => {
        typeLength(plugin, '1234');
        typeLength(plugin, '');

        assert.equal(plugin._infoEl.textContent, '');
        assert.equal(plugin._infoEl.hidden, true);
    });

    test('erscheint nicht, wenn ohne Rundung gestartet wird', () => {
        typeLength(plugin, '2000');

        assert.equal(plugin._infoEl.textContent, '');
        assert.equal(plugin._infoEl.hidden, true);
    });
});
