// Hält den Wert von SUFFIX_CHANGED_EVENT in dynamic-price.plugin.js fest. Andere Plugins hören
// auf diesen Namen (Plugin-Interaktionsprotokoll); ein Tippfehler oder eine Umbenennung ließe sie
// still taub werden.
// Ohne Abhängigkeiten, nur mit der Standardbibliothek von Node (node:test).

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const sourcePath = join(
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

const parseLengthSource = readFileSync(
    join(__dirname, '..', '..', 'src', 'Resources', 'app', 'storefront', 'src', 'util', 'parse-length.js'),
    'utf8',
).replace(/^export /m, '');
const rawSource = readFileSync(sourcePath, 'utf8');
const stripped = rawSource
    .replace(/^import [^\n]*\n/gm, '')
    .replace(/^export default /m, '');

const wrapped = `
    class Plugin {
        init() {}
        destroy() {}
    }
    ${parseLengthSource}
    ${stripped}
    return DynamicPricePlugin;
`;

const DynamicPricePlugin = new Function(wrapped)();

describe('SUFFIX_CHANGED_EVENT — Protokoll-Vertrag', () => {
    test('exponiert das generische Suffix-Event als statische Konstante', () => {
        assert.strictEqual(DynamicPricePlugin.SUFFIX_CHANGED_EVENT, 'rcSuffixChanged');
    });
});
