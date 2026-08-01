// Vertrags-Test für dynamic-price.plugin.js → _hasForeignIdController().
//
// Das Interaktionsprotokoll kennt zwei Kennzeichnungs-Wege für ID-Hoheit: `data-rc-id-controller`
// an einem Nachkommen des Formulars oder `dataset.rcIdController` am Formular selbst. Beide
// müssen erkannt werden, und Vorschau wie ID-Setzung müssen dieselbe Antwort bekommen — sonst
// zeigt die Storefront eine Aufteilung, die der Server nicht rechnet.
//
// Zero-Dependency: Node-Standardbibliothek (node:test).

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));

const pluginSourcePath = join(
    __dirname,
    '..',
    '..',
    'src',
    'Resources',
    'app',
    'storefront',
    'src',
    'dynamic-price',
    'dynamic-price.plugin.js',
);

const rawSource = readFileSync(pluginSourcePath, 'utf8');
const stripped = rawSource
    .replace(/^import [^\n]*\n/gm, '')
    .replace(/^export default /m, '');

const wrapped = `
    class Plugin {
        init() {}
        destroy() {}
    }
    ${stripped}
    return DynamicPricePlugin;
`;

const DynamicPricePlugin = new Function(wrapped)();

/**
 * Minimales Form-Double. `descendants` listet die Selektoren, die im Form-INNEREN existieren —
 * querySelector matcht bewusst nur diese, niemals das Form selbst. Ein Double ohne diese
 * DOM-Semantik prüft am Kern vorbei.
 */
function makeForm({ dataset = {}, descendants = [] } = {}) {
    return {
        dataset,
        querySelector: (selector) => (descendants.includes(selector) ? { tagName: 'DIV' } : null),
    };
}

function makeInstance(form) {
    const instance = Object.create(DynamicPricePlugin.prototype);
    instance._form = form;

    return instance;
}

describe('_hasForeignIdController — Handshake-Vertrag', () => {
    test('erkennt die Kennzeichnung am Form-dataset', () => {
        const instance = makeInstance(makeForm({ dataset: { rcIdController: 'true' } }));

        assert.strictEqual(instance._hasForeignIdController(), true);
    });

    test('erkennt die Kennzeichnung am Nachkommen-Attribut', () => {
        const instance = makeInstance(makeForm({ descendants: ['[data-rc-id-controller]'] }));

        assert.strictEqual(instance._hasForeignIdController(), true);
    });

    test('meldet false, wenn kein fremder ID-Controller am Form ist', () => {
        const instance = makeInstance(makeForm());

        assert.strictEqual(instance._hasForeignIdController(), false);
    });

    test('meldet false ohne Form (Plugin außerhalb eines Buy-Forms)', () => {
        const instance = makeInstance(null);

        assert.strictEqual(instance._hasForeignIdController(), false);
    });

    test('wertet nur den exakten dataset-Wert "true" als Marker', () => {
        const instance = makeInstance(makeForm({ dataset: { rcIdController: 'false' } }));

        assert.strictEqual(instance._hasForeignIdController(), false);
    });

    test('liefert einen echten Boolean, keinen DOM-Knoten', () => {
        const instance = makeInstance(makeForm({ descendants: ['[data-rc-id-controller]'] }));

        assert.strictEqual(typeof instance._hasForeignIdController(), 'boolean');
    });
});

/**
 * Schneidet den Rumpf einer Methode aus dem Quelltext. Ankert auf die DEFINITION (vier Leerzeichen
 * Einrückung), nicht auf den Namen — sonst trifft ein früherer Aufruf derselben Methode und der
 * Ausschnitt ist leer oder falsch. Zählt Klammern, damit verschachtelte Blöcke drin bleiben.
 */
function extractMethodBody(source, name) {
    const start = source.indexOf(`\n    ${name}(`);
    assert.notStrictEqual(start, -1, `Methode ${name} nicht gefunden — Test veraltet?`);

    const bodyStart = source.indexOf('{', start);
    let depth = 0;

    for (let i = bodyStart; i < source.length; i += 1) {
        if (source[i] === '{') {
            depth += 1;
        } else if (source[i] === '}') {
            depth -= 1;
            if (depth === 0) {
                return source.slice(bodyStart, i + 1);
            }
        }
    }

    throw new Error(`Rumpf von ${name} nicht abgeschlossen`);
}

describe('_hasForeignIdController — einziger Einstiegspunkt', () => {
    // Vorschau und ID-Setzung müssen dieselbe Antwort bekommen — deshalb genau eine Prüfstelle.

    test('_onInput baut keine eigene Marker-Prüfung', () => {
        const onInput = extractMethodBody(rawSource, '_onInput');

        assert.ok(
            onInput.includes('this._hasForeignIdController()'),
            '_onInput muss _hasForeignIdController() aufrufen',
        );
        assert.ok(
            !onInput.includes("querySelector('[data-rc-"),
            '_onInput darf die Kennzeichnung nicht selbst per querySelector suchen',
        );
    });

    test('_updateMeterState baut keine eigene Marker-Prüfung', () => {
        const updateMeterState = extractMethodBody(rawSource, '_updateMeterState');

        assert.ok(
            updateMeterState.includes('this._hasForeignIdController()'),
            '_updateMeterState muss _hasForeignIdController() aufrufen',
        );
        assert.ok(
            !updateMeterState.includes("querySelector('[data-rc-"),
            '_updateMeterState darf den Marker nicht selbst per querySelector suchen',
        );
    });

    test('prüft nur Kennzeichnungen, die auch gesetzt werden', () => {
        // RcCartSplitter meldet sich ausschließlich über dataset.rcIdController.
        assert.ok(
            !rawSource.includes('data-rc-cart-splitter'),
            'Selektor ohne Produzenten',
        );
    });
});
