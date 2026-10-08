// Testet die gemeinsame Regel für Längenangaben: Meterpreis-Feld und Längenschalter lesen „4,2"
// gleich. Ein Fehler hier ist ein Zuschnitt um den Faktor tausend daneben.

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(
    join(__dirname, '..', '..', 'src', 'Resources', 'app', 'storefront', 'src', 'util', 'parse-length.js'),
    'utf8',
).replace(/^export /m, '');

const parseLength = new Function(`${source}\nreturn parseLength;`)();

describe('parseLength — Einheiten', () => {
    test('mit Einheit ist jede Angabe eindeutig', () => {
        for (const [input, mm] of [['4,2 m', 4200], ['4.2m', 4200], ['420 cm', 4200], ['4200 mm', 4200],
            ['4200MM', 4200], ['1,25 m', 1250], ['4 m', 4000], ['97,5 cm', 975]]) {
            assert.deepEqual(parseLength(input), { mm }, input);
        }
    });

    test('ohne Einheit: Komma oder Punkt heißt Meter, eine ganze Zahl Millimeter', () => {
        assert.deepEqual(parseLength('4,2'), { mm: 4200 });
        assert.deepEqual(parseLength('0.5'), { mm: 500 });
        assert.deepEqual(parseLength('4200'), { mm: 4200 });
        assert.deepEqual(parseLength('10'), { mm: 10 });
    });

    test('ein Tausenderpunkt ergibt dieselbe Länge', () => {
        assert.deepEqual(parseLength('4.200'), { mm: 4200 });
    });

    test('eine ganze Zahl unter 10 ohne Einheit fragt nach', () => {
        assert.deepEqual(parseLength('4'), { ask: 4 });
        assert.deepEqual(parseLength('9'), { ask: 9 });
    });

    test('Unlesbares und null ergeben null', () => {
        for (const input of ['', 'abc', '-5', '4,2 km', '0', '0 m', '4,2,1']) {
            assert.equal(parseLength(input), null, input);
        }
    });
});
