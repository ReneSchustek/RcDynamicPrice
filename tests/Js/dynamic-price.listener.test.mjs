// Test für _onForeignSuffixChanged: Self-Loop-Guard und Fremd-Source-Trigger.
// Plugin-Interaktionsprotokoll, Sektion "Self-Loop-Konvention".

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

function makePlugin(hiddenValue = '500') {
    const instance = Object.create(DynamicPricePlugin.prototype);
    instance._hidden = { value: hiddenValue };
    instance._updateMeterStateCalls = [];
    instance._updateMeterState = function (mm) {
        instance._updateMeterStateCalls.push(mm);
    };
    return instance;
}

describe('_onForeignSuffixChanged — Self-Loop-Konvention', () => {
    test('ignoriert Events mit eigener Source (Self-Loop-Schutz)', () => {
        const plugin = makePlugin('500');
        plugin._onForeignSuffixChanged({ detail: { source: 'rcDynamicPrice', suffix: 'mm500' } });
        assert.deepStrictEqual(plugin._updateMeterStateCalls, []);
    });

    test('triggert _updateMeterState bei Fremd-Source-Event mit gültigem hidden-Value', () => {
        const plugin = makePlugin('500');
        plugin._onForeignSuffixChanged({ detail: { source: 'rcColorPicker', suffix: 'cRAL3025' } });
        assert.deepStrictEqual(plugin._updateMeterStateCalls, [500]);
    });

    test('triggert nicht, wenn hidden-Value 0 oder leer ist (kein gültiger Meter-State)', () => {
        const plugin = makePlugin('');
        plugin._onForeignSuffixChanged({ detail: { source: 'rcColorPicker' } });
        assert.deepStrictEqual(plugin._updateMeterStateCalls, []);
    });

    test('akzeptiert auch Events ohne detail (defensiv, falls fremder Caller schlampt)', () => {
        const plugin = makePlugin('500');
        // Kein detail-Property — defensiver Pfad: kein source-Match → Trigger.
        plugin._onForeignSuffixChanged({});
        assert.deepStrictEqual(plugin._updateMeterStateCalls, [500]);
    });
});
