// Vertrags-Test für dynamic-price.plugin.js → _previewSplit-Methode.
// Liest die geteilte Fixture tests/Fixtures/split-cases.json (gleiche Datei wie der PHP-Test
// LengthSplitterTest::testMatchesSharedFixture) und prüft, dass die JS-Implementierung
// für jeden Fall identische Ergebnisse liefert wie der serverseitige LengthSplitter.
// Drift zwischen PHP und JS wird damit sofort rot — kein silent Mismatch in der Preview.
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
const fixturePath = join(__dirname, '..', 'Fixtures', 'split-cases.json');

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
const instance = Object.create(DynamicPricePlugin.prototype);

const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));

describe('split-parity — PHP↔JS-Vertrag gegen split-cases.json', () => {
    for (const testCase of fixture.cases) {
        test(testCase.name, () => {
            // equalBilling wird im Plugin aus dem Data-Attribut gelesen; hier über ein
            // Fake-Element gespiegelt, damit dieselben Fälle wie in PHP greifen.
            instance.el = {
                dataset: {
                    equalBilling: testCase.equalBilling ?? 'cut_length',
                    equalEnforceMin: (testCase.enforceMin ?? true) ? '1' : '0',
                },
            };

            // Geprüft werden die Schnittlängen. Die Mindestlänge kommt hier nicht mehr vor —
            // sie ist eine Abrechnungsregel und wirkt erst in _billedPieces().
            const result = instance._previewSplit(
                testCase.total,
                testCase.maxPiece,
                testCase.mode,
            );
            assert.deepStrictEqual(
                result,
                testCase.expected,
                `Fall "${testCase.name}": PHP-Erwartung ${JSON.stringify(testCase.expected)} ` +
                    `differiert von JS-Ergebnis ${JSON.stringify(result)}.`,
            );
        });
    }
});
