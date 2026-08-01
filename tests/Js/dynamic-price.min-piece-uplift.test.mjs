// Transparenz der Mehrlänge im max_rest-Modus.
//
// Fällt das Reststück unter die Mindestlänge, hebt der Splitter es darauf an — der Kunde zahlt
// dann mehr, als er eingegeben hat. Zwei Fehler steckten darin:
//   1. Die Preisvorschau rechnete auf der gerundeten Eingabe statt auf der Summe der Teilstücke
//      und zeigte deshalb einen zu niedrigen Preis.
//   2. Der Kunde erfuhr von der Mehrlänge erst im Warenkorb.
// Beides muss vor dem Klick auf "In den Warenkorb" sichtbar sein.

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

globalThis.document = { documentElement: { lang: 'de-DE' } };

function makePlugin() {
    const plugin = Object.create(DynamicPricePlugin.prototype);

    plugin.el = {
        dataset: {
            minLength: '1000',
            maxLength: '50000',
            // Keine Rundung — die Mehrlänge kommt hier allein aus der Mindestlängen-Anhebung.
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

    // Preisvorschau mitschneiden statt rendern — sie ist der eigentliche Geld-Beweis.
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

    // 6100 bei maxPiece 6000 -> Stücke [6000, 100]. 100 < min 1000 -> angehoben auf 1000.
    // Berechnet werden 7000 mm, eingegeben waren 6100 mm.
    test('Preisvorschau rechnet auf der Summe der Teilstücke, nicht auf der Eingabe', () => {
        typeLength(plugin, '6100');

        assert.equal(plugin.billedMm, 7000, 'Die Vorschau muss 7000 mm berechnen, nicht 6100 mm');
    });

    test('weist die Mehrlänge samt Grund aus', () => {
        typeLength(plugin, '6100');

        assert.equal(plugin._infoEl.hidden, false, 'Der Hinweis muss sichtbar sein');
        assert.match(plugin._infoEl.textContent, /Reststück von 100 mm/);
        assert.match(plugin._infoEl.textContent, /Mindestlänge von 1\.000 mm/);
        assert.match(plugin._infoEl.textContent, /7\.000 mm statt 6\.100 mm/);
    });

    test('kein Hinweis, wenn das Reststück die Mindestlänge erreicht', () => {
        // 7500 -> [6000, 1500]. 1500 >= min 1000, keine Anhebung, Summe == Eingabe.
        typeLength(plugin, '7500');

        assert.equal(plugin.billedMm, 7500);
        assert.equal(plugin._infoEl.hidden, true, 'Ohne Mehrlänge darf kein Hinweis stehen');
        assert.equal(plugin._infoEl.textContent, '');
    });

    test('kein Hinweis ohne Split (Eingabe unter der Teilstückgrenze)', () => {
        typeLength(plugin, '5000');

        assert.equal(plugin.billedMm, 5000);
        assert.equal(plugin._infoEl.hidden, true);
    });

    test('Hinweis verschwindet wieder, sobald die Eingabe ohne Anhebung auskommt', () => {
        typeLength(plugin, '6100');
        assert.equal(plugin._infoEl.hidden, false, 'Vorbedingung: Hinweis steht');

        typeLength(plugin, '7500');

        assert.equal(plugin._infoEl.textContent, '', 'Stehengebliebener Hinweis meldet eine falsche Länge');
        assert.equal(plugin._infoEl.hidden, true);
    });

    test('ohne Rest bleibt die Summe gleich der Eingabe', () => {
        // 12000 -> [6000, 6000], kein Rest, keine Anhebung.
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

        // 6100 -> [6000, 100] -> Anhebung auf [6000, 1000] -> Rundung je Stück -> 6000 + 1000 = 7000.
        plugin._input.value = '6100';
        plugin._onInput();

        assert.equal(plugin.billedMm, 7000);
    });

    test('Rundung je Teilstück, nicht auf der Gesamtlänge', () => {
        const plugin = makePlugin();
        plugin.el.dataset.roundingMode = 'full_m';
        plugin.el.dataset.maxPieceLength = '6000';
        plugin.el.dataset.minLength = '100';

        // 6500 -> [6000, 500] -> 500 >= min 100, keine Anhebung.
        // Rundung je Stück: 6000 + 1000 = 7000. Auf der Gesamtlänge wären es 7000 -> gleich,
        // aber der Weg dorthin muss über die Stücke laufen (PHP rundet ebenfalls je Position).
        plugin._input.value = '6500';
        plugin._onInput();

        assert.equal(plugin.billedMm, 7000);
    });
});
