// Prüft _previewSplit aus dynamic-price.plugin.js gegen die Fälle in tests/Fixtures/split-cases.json,
// dieselbe Datei, die LengthSplitterTest::testMatchesSharedFixture auf dem Server prüft. Rechnen
// Vorschau und Server verschieden, zeigt die Produktseite eine Aufteilung, die der Warenkorb nicht
// übernimmt, und dieser Test schlägt an.
//
// Ohne Abhängigkeiten, nur mit der Standardbibliothek von Node (node:test).

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

const parseLengthSource = readFileSync(
    join(__dirname, '..', '..', 'src', 'Resources', 'app', 'storefront', 'src', 'util', 'parse-length.js'),
    'utf8',
).replace(/^export /m, '');
const rawSource = readFileSync(pluginSourcePath, 'utf8');
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
const instance = Object.create(DynamicPricePlugin.prototype);

const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));

describe('split-parity — PHP↔JS-Vertrag gegen split-cases.json', () => {
    for (const testCase of fixture.cases) {
        test(testCase.name, () => {
            // equalBilling liest das Plugin aus einem Data-Attribut; hier steht es an einem
            // nachgebildeten Element, damit dieselben Fälle wie auf dem Server greifen.
            instance.el = {
                dataset: {
                    equalBilling: testCase.equalBilling ?? 'cut_length',
                    equalEnforceMin: (testCase.enforceMin ?? true) ? '1' : '0',
                },
            };

            // Geprüft werden die Schnittlängen. Die Mindestlänge kommt hier nicht vor; sie ist eine
            // Abrechnungsregel und wirkt erst in _billedPieces().
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
