// Testet die Rechenregeln der geführten Auswahl: welche Optionen ein Schritt zeigt und welche
// Schritte nach dem Laden der Seite als beantwortet gelten. Der Quelltext wird in einer
// Stub-Umgebung evaluiert, wie beim Längenschalter.
//
// Grundlage ist der echte Bestand: das Relinggeländer `EB270100-2-0` mit zwölf Maßen und fünf
// Pfostenzahlen, von denen es 18 Kombinationen gibt (live-clone, 2026-10-05).

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(
    join(__dirname, '..', '..', 'src', 'Resources', 'app', 'storefront', 'src', 'guided-selection', 'guided-selection.plugin.js'),
    'utf8',
)
    .replace(/^import [^\n]*\n/gm, '')
    .replace(/^export default /m, '');

const GuidedSelectionPlugin = new Function(`
    class Plugin {
        init() {}
        destroy() {}
    }
    ${source}
    return GuidedSelectionPlugin;
`)();

const lengths = ['1,0', '1,2', '1,5', '2,0', '2,5', '3,0', '3,5', '4,0', '4,5', '5,0', '5,5', '6,0'];
const posts = ['2', '3', '4', '5', '6'];
const postsByLength = {
    '1,0': ['2'], '1,2': ['2'], '1,5': ['2'], '2,0': ['2', '3'], '2,5': ['3'], '3,0': ['3', '4'],
    '3,5': ['4'], '4,0': ['4', '5'], '4,5': ['5'], '5,0': ['5', '6'], '5,5': ['5', '6'], '6,0': ['5', '6'],
};

const m = (length) => `m-${length}`;
const p = (count) => `p-${count}`;
const groups = [
    { id: 'dimensions', optionIds: lengths.map(m) },
    { id: 'design', optionIds: posts.map(p) },
];
const combinations = Object.entries(postsByLength).flatMap(([length, counts]) => counts.map((count) => [p(count), m(length)]));

describe('possibleOptions — was ein Schritt zeigt', () => {
    test('der Bestand hat 18 Kombinationen', () => {
        assert.equal(combinations.length, 18);
    });

    test('der erste Schritt zeigt jede Länge, die es überhaupt gibt', () => {
        assert.deepEqual(GuidedSelectionPlugin.possibleOptions(groups, combinations, 0, {}), lengths.map(m));
    });

    test('nach 2,0 m gibt es 2 oder 3 Pfosten, nichts sonst', () => {
        assert.deepEqual(
            GuidedSelectionPlugin.possibleOptions(groups, combinations, 1, { dimensions: m('2,0') }),
            [p('2'), p('3')],
        );
    });

    test('nach 5,5 m gibt es 5 oder 6 Pfosten', () => {
        assert.deepEqual(
            GuidedSelectionPlugin.possibleOptions(groups, combinations, 1, { dimensions: m('5,5') }),
            [p('5'), p('6')],
        );
    });

    test('die Reihenfolge ist die der Gruppe, nicht die der Kombinationen', () => {
        const reversed = [...combinations].reverse();
        assert.deepEqual(
            GuidedSelectionPlugin.possibleOptions(groups, reversed, 1, { dimensions: m('4,0') }),
            [p('4'), p('5')],
        );
    });
});

describe('settle — welche Schritte nach dem Laden beantwortet sind', () => {
    test('beim ersten Besuch ist nichts beantwortet, auch wenn die Seite eine Variante zeigt', () => {
        const result = GuidedSelectionPlugin.settle(groups, combinations, { dimensions: m('1,0'), design: p('2') }, {});
        assert.deepEqual(result, { confirmed: {}, switchTo: null });
    });

    test('nach der Wahl von 1,5 m stehen die Pfosten fest: es gibt nur 2', () => {
        const result = GuidedSelectionPlugin.settle(
            groups, combinations, { dimensions: m('1,5'), design: p('2') }, { dimensions: m('1,5') },
        );
        assert.deepEqual(result.confirmed, { dimensions: m('1,5'), design: p('2') });
        assert.equal(result.switchTo, null);
    });

    test('nach der Wahl von 3,0 m wird nach den Pfosten gefragt', () => {
        const result = GuidedSelectionPlugin.settle(
            groups, combinations, { dimensions: m('3,0'), design: p('3') }, { dimensions: m('3,0') },
        );
        assert.deepEqual(result.confirmed, { dimensions: m('3,0') });
    });

    test('gewählte Pfosten bleiben, wenn sie zur neuen Länge passen und die Seite sie zeigt', () => {
        const result = GuidedSelectionPlugin.settle(
            groups, combinations, { dimensions: m('4,0'), design: p('4') }, { dimensions: m('4,0'), design: p('4') },
        );
        assert.deepEqual(result.confirmed, { dimensions: m('4,0'), design: p('4') });
    });

    test('gewählte Pfosten verfallen, wenn die Seite nach dem Längenwechsel andere zeigt', () => {
        // Gemerkt waren 3 Pfosten zu 2,0 m; die Seite zeigt 4,0 m mit 5 Pfosten.
        const result = GuidedSelectionPlugin.settle(
            groups, combinations, { dimensions: m('4,0'), design: p('5') }, { dimensions: m('4,0'), design: p('3') },
        );
        assert.deepEqual(result.confirmed, { dimensions: m('4,0') });
    });

    test('gibt es nur eine Möglichkeit und zeigt die Seite eine andere, wird umgestellt', () => {
        // Etwa wenn die gezeigte Variante nicht kaufbar ist.
        const result = GuidedSelectionPlugin.settle(
            groups, combinations, { dimensions: m('2,5'), design: p('4') }, { dimensions: m('2,5') },
        );
        assert.deepEqual(result, { confirmed: { dimensions: m('2,5') }, switchTo: { groupId: 'design', optionId: p('3') } });
    });

    test('eine gemerkte Länge, die die Seite nicht zeigt, gilt nicht', () => {
        const result = GuidedSelectionPlugin.settle(
            groups, combinations, { dimensions: m('1,0'), design: p('2') }, { dimensions: m('6,0') },
        );
        assert.deepEqual(result.confirmed, {});
    });

    test('ein Bausatz mit nur der Länge ist nach ihr vollständig', () => {
        const lengthOnly = [{ id: 'length', optionIds: ['l-1', 'l-2'] }];
        const result = GuidedSelectionPlugin.settle(lengthOnly, [['l-1'], ['l-2']], { length: 'l-2' }, { length: 'l-2' });
        assert.deepEqual(result.confirmed, { length: 'l-2' });
    });
});
