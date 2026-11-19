// Das Ereignis `rcDynamicPriceChanged` auf `document`: Kaufleiste des Themes und Zweitpreis von RcDualPrice übernehmen
// daraus den berechneten Preis. Anlass BRIEF167 (Theme): Die Kaufleiste zeigte nach der Längeneingabe weiter den
// Meterpreis, die Nettozeile ebenso.
// Ohne Abhängigkeiten, nur mit der Standardbibliothek von Node (node:test).

import { describe, test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const src = join(__dirname, '..', '..', 'src', 'Resources', 'app', 'storefront', 'src');
const parseLengthSource = readFileSync(join(src, 'util', 'parse-length.js'), 'utf8').replace(/^export /m, '');
const stripped = readFileSync(join(src, 'dynamic-price', 'dynamic-price.plugin.js'), 'utf8')
    .replace(/^import [^\n]*\n/gm, '')
    .replace(/^export default /m, '');

const DynamicPricePlugin = new Function(`
    class Plugin { init() {} destroy() {} }
    ${parseLengthSource}
    ${stripped}
    return DynamicPricePlugin;
`)();

let events;

beforeEach(() => {
    events = [];
    globalThis.document = {
        documentElement: { lang: 'de-DE' },
        dispatchEvent: (event) => events.push(event),
    };
    globalThis.CustomEvent = class {
        constructor(type, init) {
            this.type = type;
            this.detail = init.detail;
        }
    };
});

function plugin() {
    const instance = Object.create(DynamicPricePlugin.prototype);
    instance.el = { dataset: { basePrice: '68.04', currency: 'EUR' } };
    instance._productId = 'cac9066c33f3976e67999022135f84aa';
    instance._resultEl = { hidden: true };
    instance._resultPrice = { textContent: '' };
    instance._hidden = { value: '' };
    instance._productPrice = { textContent: '68,04 €', innerHTML: '68,04 €' };
    instance._originalPriceHtml = '68,04 €';

    return instance;
}

describe('rcDynamicPriceChanged', () => {
    test('der Name steht als Konstante fest', () => {
        assert.strictEqual(DynamicPricePlugin.PRICE_CHANGED_EVENT, 'rcDynamicPriceChanged');
    });

    test('nach der Längeneingabe meldet es den berechneten Preis', () => {
        const instance = plugin();
        instance._updatePrice(1500);

        assert.strictEqual(events.length, 1);
        assert.strictEqual(events[0].type, 'rcDynamicPriceChanged');
        assert.strictEqual(events[0].detail.unitPrice, 102.06);
        assert.match(events[0].detail.formatted, /^102,06\s€$/);
        assert.strictEqual(events[0].detail.basePrice, 68.04);
        assert.strictEqual(events[0].detail.productId, 'cac9066c33f3976e67999022135f84aa');
        assert.match(instance._productPrice.textContent, /^102,06\s€$/);
    });

    test('der gemeldete Preis ist auf Cent gerundet wie der angezeigte', () => {
        // 1500 mm zu 52,37 € je Meter sind 78,555 €; angezeigt und im Warenkorb gelten 78,56 €.
        const instance = plugin();
        instance.el.dataset.basePrice = '52.37';
        instance._updatePrice(1500);

        assert.strictEqual(events[0].detail.unitPrice, 78.56);
        assert.match(events[0].detail.formatted, /^78,56\s€$/);
    });

    test('nach dem Zurücksetzen meldet es keinen Preis, nur den Grundpreis', () => {
        const instance = plugin();
        instance._clearResult();

        assert.strictEqual(events.length, 1);
        assert.strictEqual(events[0].detail.unitPrice, null);
        assert.strictEqual(events[0].detail.formatted, null);
        assert.strictEqual(events[0].detail.basePrice, 68.04);
    });
});
