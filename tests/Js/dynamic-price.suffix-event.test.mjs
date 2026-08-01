// Vertrags-Test für dynamic-price.plugin.js. Verankert die SUFFIX_CHANGED_EVENT-Konstante,
// damit ein Wert-Drift (Tippfehler, Refactor) sofort auffällt — Plugin-Interaktionsprotokoll.
// Zero-Dependency: Node-Standardbibliothek (node:test).

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

const rawSource = readFileSync(sourcePath, 'utf8');
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

describe('SUFFIX_CHANGED_EVENT — Protokoll-Vertrag', () => {
    test('exponiert das generische Suffix-Event als statische Konstante', () => {
        assert.strictEqual(DynamicPricePlugin.SUFFIX_CHANGED_EVENT, 'rcSuffixChanged');
    });
});
