// Die Mehrlänge im max_rest-Modus, bevor der Kunde in den Warenkorb legt.
//
// Fällt das Reststück unter die Mindestlänge, wird es für die Abrechnung darauf angehoben, und der
// Kunde zahlt mehr, als er eingegeben hat. Die Preisvorschau rechnet deshalb auf der Summe der
// abgerechneten Teilstücke, nicht auf der Eingabe, sonst zeigte sie einen zu niedrigen Preis. Und
// der Hinweis auf die Mehrlänge steht auf der Produktseite, nicht erst im Warenkorb.

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

const parseLengthSource = readFileSync(
    join(__dirname, '..', '..', 'src', 'Resources', 'app', 'storefront', 'src', 'util', 'parse-length.js'),
    'utf8',
).replace(/^export /m, '');
const rawSource = readFileSync(sourcePath, 'utf8');
const stripped = rawSource
    .replace(/^import [^\n]*\n/gm, '')
    .replace(/^export default /m, '');

const DynamicPricePlugin = new Function(`
    class Plugin {
        init() {}
        destroy() {}
    }
    ${parseLengthSource}
    ${stripped}
    return DynamicPricePlugin;
`)();

globalThis.document = { documentElement: { lang: 'de-DE' } };

function makePlugin() {
    const plugin = Object.create(DynamicPricePlugin.prototype);

    plugin.el = {
        dataset: {
            minLength: '1000',
            maxLength: '50000',
            // Keine Rundung; die Mehrlänge kommt hier allein aus der Anhebung auf die Mindestlänge.
            roundingMode: 'none',
            splitMode: 'max_rest',
            maxPieceLength: '6000',
            splitHintTemplate: 'Aufteilung: {pieces} Positionen',
            snippetRoundUp: 'Eingabe %input% mm → berechnet werden %billed% mm (gerundet)',
            snippetMinPieceUplift:
                'Das Reststück von {remainder} mm liegt unter der Mindestlänge von {minLength} mm '
                + 'und wird darauf angehoben. Berechnet werden {billed} mm statt {input} mm.',
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

    // Die Preisvorschau wird mitgeschnitten statt gezeichnet; an ihr hängt der Betrag, den der
    // Kunde sieht.
    plugin.billedMm = null;
    plugin._updatePrice = (mm) => { plugin.billedMm = mm; };

    plugin._clearError = () => {};
    plugin._showError = () => {};
    plugin._showSplitInfo = () => {};
    plugin._clearSplitInfo = () => {};
    plugin._showBlockingInfo = () => {};
    plugin._updateMeterState = () => {};
    plugin._enableSubmit = () => {};
    plugin._disableSubmit = () => {};
    plugin._clearResult = () => {};

    return plugin;
}

function typeLength(plugin, value) {
    plugin._input.value = value;
    plugin._onInput();
}

describe('Mehrlänge durch Mindestlängen-Anhebung (max_rest)', () => {
    let plugin;

    beforeEach(() => {
        plugin = makePlugin();
    });

    // 6100 mm bei 6000 mm Höchstmaß ergeben [6000, 100]; der Rest liegt unter 1000 mm und wird
    // für die Abrechnung auf 1000 mm angehoben. Berechnet werden 7000 mm statt der eingegebenen 6100.
    test('Preisvorschau rechnet auf der Summe der Teilstücke, nicht auf der Eingabe', () => {
        typeLength(plugin, '6100');

        assert.equal(plugin.billedMm, 7000, 'Die Vorschau muss 7000 mm berechnen, nicht 6100 mm');
    });

    test('weist die Mehrlänge samt Grund aus', () => {
        typeLength(plugin, '6100');

        assert.equal(plugin._infoEl.hidden, false, 'Der Hint muss sichtbar sein');
        assert.match(plugin._infoEl.textContent, /Reststück von 100 mm/);
        assert.match(plugin._infoEl.textContent, /Mindestlänge von 1\.000 mm/);
        assert.match(plugin._infoEl.textContent, /7\.000 mm statt 6\.100 mm/);
    });

    test('kein Hint, wenn das Reststück die Mindestlänge erreicht', () => {
        // 7500 ergeben [6000, 1500]; der Rest erreicht die Mindestlänge, die Summe ist die Eingabe.
        typeLength(plugin, '7500');

        assert.equal(plugin.billedMm, 7500);
        assert.equal(plugin._infoEl.hidden, true, 'Ohne Mehrlänge darf kein Hint stehen');
        assert.equal(plugin._infoEl.textContent, '');
    });

    test('kein Hint ohne Split (Eingabe unter der Teilstückgrenze)', () => {
        typeLength(plugin, '5000');

        assert.equal(plugin.billedMm, 5000);
        assert.equal(plugin._infoEl.hidden, true);
    });

    test('Hint verschwindet wieder, sobald die Eingabe ohne Anhebung auskommt', () => {
        typeLength(plugin, '6100');
        assert.equal(plugin._infoEl.hidden, false, 'Vorbedingung: Hint steht');

        typeLength(plugin, '7500');

        assert.equal(plugin._infoEl.textContent, '', 'Stehengebliebener Hint meldet eine falsche Länge');
        assert.equal(plugin._infoEl.hidden, true);
    });

    test('ohne Rest bleibt die Summe gleich der Eingabe', () => {
        // 12000 ergeben [6000, 6000], ohne Rest und ohne Anhebung.
        typeLength(plugin, '12000');

        assert.equal(plugin.billedMm, 12000);
        assert.equal(plugin._infoEl.hidden, true);
    });
});

describe('Zusammenspiel von Rundung und Anhebung', () => {
    test('gerundete Teilstücke summieren sich zur berechneten Gesamtlänge', () => {
        const plugin = makePlugin();
        // Rundung auf volle Meter zusätzlich zur Anhebung.
        plugin.el.dataset.roundingMode = 'full_m';

        // 6100 ergeben [6000, 100], angehoben [6000, 1000], je Stück gerundet 6000 + 1000 = 7000.
        plugin._input.value = '6100';
        plugin._onInput();

        assert.equal(plugin.billedMm, 7000);
    });

    test('Rundung je Teilstück, nicht auf der Gesamtlänge', () => {
        const plugin = makePlugin();
        plugin.el.dataset.roundingMode = 'full_m';
        plugin.el.dataset.maxPieceLength = '2500';
        plugin.el.dataset.minLength = '100';

        // 5200 ergeben [2500, 2500, 200]. Je Stück auf volle Meter gerundet sind es 3000 + 3000 + 1000
        // = 7000; die Gesamtlänge gerundet wären nur 6000. Der Server rundet je Teilstück, die Vorschau
        // muss es genauso tun, sonst zeigt sie einen Meter zu wenig.
        plugin._input.value = '5200';
        plugin._onInput();

        assert.equal(plugin.billedMm, 7000);
    });
});
