// Prüft _resolveForm: Steht das Längenfeld über den Eingabefeldern von TMMS und damit außerhalb des
// Kaufformulars, findet das Skript sein Formular über `data-form`. Ohne das liefe die Längenprüfung
// ins Leere, und der Kaufknopf bliebe frei, obwohl keine gültige Länge eingetragen ist.

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = (...parts) => readFileSync(join(__dirname, '..', '..', 'src', 'Resources', 'app', 'storefront', 'src', ...parts), 'utf8');

const parseLengthSource = source('util', 'parse-length.js').replace(/^export /m, '');
const stripped = source('dynamic-price', 'dynamic-price.plugin.js')
    .replace(/^import [^\n]*\n/gm, '')
    .replace(/^export default /m, '');

const DynamicPricePlugin = new Function(`
    class Plugin { init() {} destroy() {} }
    ${parseLengthSource}
    ${stripped}
    return DynamicPricePlugin;
`)();

const buyForm = { id: 'productDetailPageBuyProductForm' };

function pluginFor(enclosingForm, dataForm) {
    const plugin = Object.create(DynamicPricePlugin.prototype);
    plugin.el = { closest: () => enclosingForm, dataset: dataForm ? { form: dataForm } : {} };
    return plugin;
}

describe('_resolveForm', () => {
    test('im Kaufformular gilt das umgebende Formular', () => {
        const enclosing = { id: 'umgebend' };
        assert.strictEqual(pluginFor(enclosing, 'productDetailPageBuyProductForm')._resolveForm(), enclosing);
    });

    test('außerhalb des Formulars über data-form', () => {
        globalThis.document = { getElementById: (id) => (id === buyForm.id ? buyForm : null) };
        assert.strictEqual(pluginFor(null, 'productDetailPageBuyProductForm')._resolveForm(), buyForm);
    });

    test('ohne Formular und ohne data-form kein Formular', () => {
        globalThis.document = { getElementById: () => buyForm };
        assert.strictEqual(pluginFor(null, null)._resolveForm(), null);
    });
});
