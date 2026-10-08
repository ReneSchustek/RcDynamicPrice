// Testet die Rechenregeln des Variantensprungs: das Lesen der Größenstufen aus ihrer
// Beschriftung, das Zerlegen der Eingabe und die Zuordnung Länge → Stufe. Der Quelltext wird
// gelesen und in einer nachgebildeten Umgebung ausgeführt, damit das Skript unverändert als Modul
// gebaut werden kann, wie bei den übrigen Storefront-Skripten des Themes.
//
// Geprüft werden vor allem die Helfer, die entscheiden, auf welche Variante gesprungen wird. Ein
// Fehler darin ist ein Zuschnitt in der falschen Länge, und der fällt erst in der Fertigung auf.

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
    'length-variant-switch',
    'length-variant-switch.plugin.js',
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
    const window = globalThis;
    class Plugin {
        init() {}
        destroy() {}
    }
    class PseudoModalUtil {
        constructor() {}
        open() {}
    }
    ${parseLengthSource}
    ${stripped}
    return LengthVariantSwitchPlugin;
`;

const LengthVariantSwitchPlugin = new Function(wrapped)();

const MM_PER_CM = 10;

/** Die Stufen des Artikels `FB96-300-0.1`, gelesen am 2026-09-01 auf Staging. */
const ranges = [
    [96, 116], [116, 130], [130, 140], [140, 150], [150, 160], [160, 170], [170, 180],
    [180, 190], [190, 200], [200, 210], [210, 220], [220, 230], [230, 240], [240, 250],
    [250, 260], [260, 270], [270, 280], [280, 290], [290, 300],
].map(([min, max]) => ({ min, max, label: `${min} – ${max} cm` }));

describe('parseRange — die Beschriftung einer Größenstufe', () => {
    test('liest das Muster des Bestands', () => {
        assert.deepEqual(
            LengthVariantSwitchPlugin.parseRange('96 - 116 cm'),
            { min: 96, max: 116, label: '96 – 116 cm' },
        );
    });

    test('verträgt Gedankenstrich, mehrfache Leerzeichen und Zeilenumbrüche', () => {
        // Die Beschriftungen kommen aus dem Verwaltungsbereich und sind dort von Hand gesetzt.
        assert.equal(LengthVariantSwitchPlugin.parseRange('130 – 140 cm').max, 140);
        assert.equal(LengthVariantSwitchPlugin.parseRange('  130   -  140   cm ').min, 130);
        assert.equal(LengthVariantSwitchPlugin.parseRange('130\n-\n140 cm').min, 130);
    });

    test('gibt null zurück, wo das Muster nicht steht', () => {
        // Jeder dieser Fälle schaltet die Automatik auf der Seite ganz ab — bewusst.
        for (const text of ['96 bis 116 cm', '96 - 116', '96 - 116 mm', 'Standard', '', null]) {
            assert.equal(LengthVariantSwitchPlugin.parseRange(text), null, `unerwartet gelesen: ${text}`);
        }
    });

    test('gibt null zurück, wenn die Grenzen verdreht sind', () => {
        // `140 - 130 cm` ist keine Stufe, sondern ein Tippfehler. Ihn zu lesen hieße, ihn zu
        // benutzen.
        assert.equal(LengthVariantSwitchPlugin.parseRange('140 - 130 cm'), null);
        assert.equal(LengthVariantSwitchPlugin.parseRange('130 - 130 cm'), null);
    });
});

describe('parseLengths — die Eingabe des Kunden', () => {
    test('liest eine einzelne Länge', () => {
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths('1350'), [1350]);
    });

    test('liest mehrere, mit Semikolon getrennt', () => {
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths('970; 1140'), [970, 1140]);
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths('970;1140;'), [970, 1140]);
    });

    test('das leere Feld ist keine Fehleingabe', () => {
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths(''), []);
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths('   '), []);
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths(';'), []);
    });

    test('Einheiten werden in Millimeter umgerechnet', () => {
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths('4,2 m'), [4200]);
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths('4.2m'), [4200]);
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths('420 cm'), [4200]);
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths('97 cm'), [970]);
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths('1350mm'), [1350]);
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths('1,2 m; 4200'), [1200, 4200]);
    });

    test('ohne Einheit: Komma heißt Meter, eine ganze Zahl Millimeter', () => {
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths('13,5'), [13500]);
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths('4200'), [4200]);
    });

    test('eine ganze Zahl unter 10 ohne Einheit fragt nach', () => {
        assert.equal(LengthVariantSwitchPlugin.parseLengths('4'), null);
        assert.equal(LengthVariantSwitchPlugin.unitQuestion('4'), 4);
        assert.equal(LengthVariantSwitchPlugin.unitQuestion('4 m'), null);
        assert.deepEqual(LengthVariantSwitchPlugin.parseLengths('4 m'), [4000]);
    });

    test('Unlesbares ergibt null — wer noch tippt, wird nicht angesprungen', () => {
        for (const input of ['abc', '970; abc', '-970', '4,2 km', '0 m']) {
            assert.equal(
                LengthVariantSwitchPlugin.parseLengths(input),
                null,
                `unerwartet gelesen: ${input}`,
            );
        }
    });
});

describe('findRange — die Stufe zu einer Länge', () => {
    test('Renes Beispiel: 1350 mm gehört zu 130 – 140 cm', () => {
        assert.equal(LengthVariantSwitchPlugin.findRange(ranges, 1350, MM_PER_CM).label, '130 – 140 cm');
    });

    test('die Grenze gehört zur unteren Stufe', () => {
        // Genau 1300 mm ergeben 116 – 130 cm. Die Bereiche überlappen an ihren Grenzen; ohne diese
        // Festlegung entschiede die Reihenfolge im Markup.
        assert.equal(LengthVariantSwitchPlugin.findRange(ranges, 1300, MM_PER_CM).label, '116 – 130 cm');
        assert.equal(LengthVariantSwitchPlugin.findRange(ranges, 1301, MM_PER_CM).label, '130 – 140 cm');
    });

    test('die kleinste und die größte Stufe', () => {
        assert.equal(LengthVariantSwitchPlugin.findRange(ranges, 960, MM_PER_CM).label, '96 – 116 cm');
        assert.equal(LengthVariantSwitchPlugin.findRange(ranges, 3000, MM_PER_CM).label, '290 – 300 cm');
    });

    test('außerhalb gibt es keine Stufe', () => {
        assert.equal(LengthVariantSwitchPlugin.findRange(ranges, 959, MM_PER_CM), null);
        assert.equal(LengthVariantSwitchPlugin.findRange(ranges, 3001, MM_PER_CM), null);
        // Ein naheliegender Tippfehler: 135 sind 13,5 cm, nicht 135 cm.
        assert.equal(LengthVariantSwitchPlugin.findRange(ranges, 135, MM_PER_CM), null);
    });
});

describe('evaluate — was die Eingabe insgesamt bedeutet', () => {
    test('eine Länge trifft eine Stufe', () => {
        const result = LengthVariantSwitchPlugin.evaluate(ranges, [1350], MM_PER_CM);

        assert.equal(result.status, 'matched');
        assert.equal(result.range.label, '130 – 140 cm');
        assert.equal(result.count, 1);
    });

    test('mehrere Längen derselben Stufe zählen als Menge', () => {
        // 970 und 1140 liegen beide in 96 – 116 cm, also zwei Zuschnitte aus derselben Größe.
        const result = LengthVariantSwitchPlugin.evaluate(ranges, [970, 1140], MM_PER_CM);

        assert.equal(result.status, 'matched');
        assert.equal(result.range.label, '96 – 116 cm');
        assert.equal(result.count, 2);
    });

    test('Längen aus verschiedenen Stufen führen zu keinem Sprung', () => {
        // Eine Position kann nur eine Variante haben.
        const result = LengthVariantSwitchPlugin.evaluate(ranges, [970, 1350], MM_PER_CM);

        assert.equal(result.status, 'mixed');
        assert.equal(result.range, null);
    });

    test('eine unerreichbare Länge unter mehreren kippt das Ganze', () => {
        const result = LengthVariantSwitchPlugin.evaluate(ranges, [970, 5000], MM_PER_CM);

        assert.equal(result.status, 'outOfRange');
    });

    test('das leere Feld bedeutet nichts', () => {
        assert.equal(LengthVariantSwitchPlugin.evaluate(ranges, [], MM_PER_CM).status, 'empty');
    });
});

describe('isComplete — wann eine Eingabe fertig genug ist', () => {
    test('vier Ziffern gelten als fertig', () => {
        // Die Stufen reichen von 960 bis 3000 mm; mehr als vier Ziffern gibt es dort nicht.
        assert.equal(LengthVariantSwitchPlugin.isComplete('1350'), true);
        assert.equal(LengthVariantSwitchPlugin.isComplete('970; 1140'), true);
    });

    test('drei Ziffern bleiben unentschieden', () => {
        // `970` könnte der Anfang von `9700` sein. Für sie greift das Verlassen des Feldes.
        assert.equal(LengthVariantSwitchPlugin.isComplete('970'), false);
        assert.equal(LengthVariantSwitchPlugin.isComplete('9'), false);
        assert.equal(LengthVariantSwitchPlugin.isComplete('13'), false);
        assert.equal(LengthVariantSwitchPlugin.isComplete('135'), false);
    });

    test('ein Semikolon am Ende kündigt die nächste Länge an', () => {
        // Die Seite darf nicht springen, solange die zweite Länge noch fehlt.
        assert.equal(LengthVariantSwitchPlugin.isComplete('970;'), false);
        assert.equal(LengthVariantSwitchPlugin.isComplete('1350; '), false);
    });

    test('das leere Feld ist nie fertig', () => {
        assert.equal(LengthVariantSwitchPlugin.isComplete(''), false);
        assert.equal(LengthVariantSwitchPlugin.isComplete('   '), false);
        assert.equal(LengthVariantSwitchPlugin.isComplete(null), false);
    });

    test('nach einem Semikolon zählt nur die neue Angabe', () => {
        // Die erste Länge ist fertig, die zweite noch nicht; also wird nicht gehandelt.
        assert.equal(LengthVariantSwitchPlugin.isComplete('1350; 9'), false);
        assert.equal(LengthVariantSwitchPlugin.isComplete('1350; 970'), false);
        assert.equal(LengthVariantSwitchPlugin.isComplete('1350; 9700'), true);
    });

    test('Buchstaben machen nicht fertig', () => {
        assert.equal(LengthVariantSwitchPlugin.isComplete('abcd'), false);
        assert.equal(LengthVariantSwitchPlugin.isComplete('13a0'), false);
    });
});

// Der Ablauf, nicht die Rechnung.
//
// Der Sprung hängt an zwei Ereignissen, `input` beim Tippen und `change` beim Verlassen des Feldes.
// Säße die Sperre gegen doppelte Beurteilung nur an einem davon, würde eine vierstellige Eingabe
// zweimal beurteilt und öffnete zwei Fenster; nach dem Bestätigen bliebe eine Abdunklung liegen
// und sperrte die Seite.

/** Ein Plugin mit den Teilen, die der Ablauf anfasst, ohne Browser und ohne Shopware. */
const makePlugin = (rangeList) => {
    const plugin = Object.create(LengthVariantSwitchPlugin.prototype);
    const firedEvents = [];

    plugin._input = {
        value: '',
        dispatchEvent(event) {
            firedEvents.push(event.type);
        },
    };
    plugin._ranges = rangeList.map((stufe) => ({
        ...stufe,
        isCurrent: () => false,
        apply: () => { plugin.applied = (plugin.applied || 0) + 1; },
    }));
    plugin._lastEvaluated = null;
    plugin.options = {
        millimetresPerCentimetre: MM_PER_CM,
        typingPause: 0,
        titleHint: 'Hinweis',
        titleAdjusted: 'Angepasst',
        textAdjusted: 'Umgestellt von %from% auf %to%.',
    };
    plugin.openedModals = [];
    plugin._openModal = (text, title) => { plugin.openedModals.push(title); };
    plugin._deliverableRangeMessage = () => 'lieferbar von bis';
    plugin._applyQuantity = () => {};
    plugin._currentLabel = () => '96 – 116 cm';
    plugin._lengthChosen = () => true;
    plugin._stash = (payload) => { plugin.stashed = payload; };
    plugin.steps = [];
    plugin._readSteps = () => plugin.steps;
    plugin._writeSteps = (steps) => { plugin.steps = steps; };
    plugin.firedEvents = firedEvents;

    return plugin;
};

describe('Der Ablauf — ein Fenster, nicht zwei', () => {
    test('eine fertige Angabe wartet nach der letzten Taste', async () => {
        const plugin = makePlugin(ranges);
        plugin.options.typingPause = 20;
        plugin._input.value = '1200';

        plugin._onInputTyping();
        assert.equal(plugin.applied, undefined, 'Sofort gesprungen, ginge „; 4200" verloren.');

        plugin._input.value = '1200; 4';
        plugin._onInputTyping();
        await new Promise((resolve) => setTimeout(resolve, 60));
        assert.equal(plugin.applied, undefined, 'Die neue Taste nimmt die wartende Beurteilung zurück.');
    });

    test('Tippen und Verlassen des Feldes beurteilen dieselbe Eingabe nur einmal', () => {
        const plugin = makePlugin(ranges);
        plugin._input.value = '9999';

        // So läuft es im Browser: Das vierte Zeichen löst `input` aus, das Verlassen `change`.
        plugin._onInputTyping();
        plugin._onInputChange();
        plugin._onInputChange();

        assert.equal(plugin.openedModals.length, 1,
            'Zwei Fenster heißen zwei Abdunklungen — und eine bleibt nach dem Bestätigen liegen.');
    });

    test('eine abgewiesene Länge lässt das Feld leer zurück', () => {
        const plugin = makePlugin(ranges);
        plugin._input.value = '9999';

        plugin._onInputChange();

        assert.equal(plugin._input.value, '', 'Die abgewiesene Zahl darf nicht stehen bleiben.');
        assert.deepEqual(plugin.firedEvents, ['input'],
            'Das Leeren muss dasselbe Ereignis auslösen wie eine Eingabe von Hand.');
    });

    test('dieselbe Zahl erneut eingetippt warnt wieder', () => {
        const plugin = makePlugin(ranges);

        plugin._input.value = '9999';
        plugin._onInputChange();

        plugin._input.value = '9999';
        plugin._onInputChange();

        assert.equal(plugin.openedModals.length, 2,
            'Nach dem Leeren ist die Sperre zurückgesetzt — sonst bliebe die zweite Eingabe stumm.');
    });

    test('gemischte Längen: erst die erste Größe, die übrigen warten', () => {
        const plugin = makePlugin(ranges);
        plugin._input.value = '1000; 2000; 1100';

        plugin._onInputChange();

        assert.deepEqual(plugin.openedModals, [], 'Kein Fenster: Der Ablauf erklärt sich unter dem Feld.');
        assert.equal(plugin._input.value, '1000;1100', 'Ins Feld kommen nur die Längen der ersten Größe.');
        assert.equal(plugin.stashed.to, '96 – 116 cm');
        assert.equal(plugin.applied, 1, 'Auf die erste Größe wird umgestellt.');
        assert.deepEqual(plugin.steps, [{ label: '190 – 200 cm', lengths: [2000] }]);
    });

    test('nach dem Warenkorb kommt die nächste Größe', () => {
        const plugin = makePlugin(ranges);
        plugin.steps = [{ label: '190 – 200 cm', lengths: [2000] }];

        plugin._onAddedToCart();

        assert.deepEqual(plugin.steps, [], 'Der Schritt ist verbraucht.');
        assert.equal(plugin.stashed.value, '2000');
        assert.equal(plugin.stashed.to, '190 – 200 cm');
        assert.equal(plugin.stashed.step, true, 'Nach dem Sprung kommt der Schritt-Hinweis, kein „passt nicht".');
        assert.equal(plugin.applied, 1);
    });

    test('ohne wartende Schritte tut der Warenkorb nichts', () => {
        const plugin = makePlugin(ranges);

        plugin._onAddedToCart();

        assert.equal(plugin.applied, undefined);
        assert.equal(plugin.stashed, undefined);
    });

    test('eine Eingabe mit Einheit steht danach in Millimetern im Feld', () => {
        const plugin = makePlugin(ranges);
        plugin._input.value = '1,4 m';

        plugin._onInputChange();

        assert.equal(plugin._input.value, '1400');
        assert.ok(plugin.firedEvents.includes('change'), 'TMMS speichert nur über change.');
    });

    test('eine einstellige Zahl fragt nach der Einheit und bleibt stehen', () => {
        const plugin = makePlugin(ranges);
        plugin._input.value = '4';

        plugin._onInputChange();

        assert.deepEqual(plugin.openedModals, ['Hinweis']);
        assert.equal(plugin._input.value, '4');
        assert.equal(plugin.applied, undefined);
    });

    test('eine Länge, die nur die Stufe wechselt, bleibt stehen', () => {
        const plugin = makePlugin(ranges);
        plugin._input.value = '1400';

        plugin._onInputChange();

        assert.equal(plugin._input.value, '1400',
            'Diese Eingabe war richtig — nur die Stufe war es nicht.');
        assert.equal(plugin.openedModals.length, 0, 'Der Hinweis kommt erst nach dem Neuladen.');
        assert.equal(plugin.applied, 1, 'Die Stufe muss umgestellt werden.');
    });
});

describe('Feste Maße — die Längen der Bausätze', () => {
    // Die Maße des Relinggeländers `EB270100-2-0`, gelesen am 2026-10-05 auf live-clone.
    const sizes = ['1,0 m', '1,2 m', '1,5 m', '2,0 m', '2,5 m', '3,0 m', '3,5 m', '4,0 m', '4,5 m', '5,0 m', '5,5 m', '6,0 m']
        .map((text) => LengthVariantSwitchPlugin.parseRange(text));
    const fixedSizeRanges = LengthVariantSwitchPlugin.completeFixedSizes(sizes);

    test('liest Meter, Zentimeter und Millimeter, mit Komma oder Punkt', () => {
        assert.deepEqual(LengthVariantSwitchPlugin.parseRange('1,5 m'), { min: null, max: 150, label: '1,5 m' });
        assert.equal(LengthVariantSwitchPlugin.parseRange('1.5 m').max, 150);
        assert.equal(LengthVariantSwitchPlugin.parseRange('150 cm').max, 150);
        assert.equal(LengthVariantSwitchPlugin.parseRange('1500 mm').max, 150);
        assert.equal(LengthVariantSwitchPlugin.parseRange('10,0 m').max, 1000);
    });

    test('ein Maß ohne Zahl oder mit null ist keines', () => {
        for (const text of ['m', '0 m', '1,5', 'ca. 1,5 m']) {
            assert.equal(LengthVariantSwitchPlugin.parseRange(text), null, `unerwartet gelesen: ${text}`);
        }
    });

    test('die Untergrenze ist das nächstkleinere Maß, beim kleinsten null', () => {
        assert.equal(fixedSizeRanges[0].min, 0);
        assert.equal(fixedSizeRanges[2].min, 120);
        assert.equal(fixedSizeRanges[11].min, 550);
    });

    test('die Untergrenze hängt nicht an der Reihenfolge im Markup', () => {
        // Bei den Balkongeländern steht 10,0 m in der Datenbank vor 2,0 m.
        const unsorted = LengthVariantSwitchPlugin.completeFixedSizes(['10,0 m', '2,0 m', '3,0 m']
            .map((text) => LengthVariantSwitchPlugin.parseRange(text)));
        assert.deepEqual(unsorted.map((range) => range.min), [300, 0, 200]);
    });

    test('die vereinbarten Rauchproben: 1,37 m wird 1,5 m, 3,20 m wird 3,5 m, 6,20 m gibt es nicht', () => {
        assert.equal(LengthVariantSwitchPlugin.findRange(fixedSizeRanges, 1370, MM_PER_CM).label, '1,5 m');
        assert.equal(LengthVariantSwitchPlugin.findRange(fixedSizeRanges, 3200, MM_PER_CM).label, '3,5 m');
        assert.equal(LengthVariantSwitchPlugin.findRange(fixedSizeRanges, 6200, MM_PER_CM), null);
    });

    test('genau ein Maß ist dieses Maß, und eine Länge darunter das kleinste', () => {
        assert.equal(LengthVariantSwitchPlugin.findRange(fixedSizeRanges, 1500, MM_PER_CM).label, '1,5 m');
        assert.equal(LengthVariantSwitchPlugin.findRange(fixedSizeRanges, 400, MM_PER_CM).label, '1,0 m');
    });

    test('der Zusatz „nicht verfügbar" des Kerns macht eine Größe nicht unlesbar', () => {
        // Mitgelesen schaltete er die Automatik für die ganze Seite ab.
        const text = LengthVariantSwitchPlugin.withoutUnavailableNote('2,5 m (Nicht verfügbar)', true);
        assert.equal(LengthVariantSwitchPlugin.parseRange(text).max, 250);
        assert.equal(LengthVariantSwitchPlugin.withoutUnavailableNote('96 - 116 cm (Sonderbreite)', false), '96 - 116 cm (Sonderbreite)');
    });

    test('bei Stufen bis 15 m sind vier Ziffern noch nicht fertig', () => {
        assert.equal(LengthVariantSwitchPlugin.isComplete('1500', 5), false);
        assert.equal(LengthVariantSwitchPlugin.isComplete('15000', 5), true);
        assert.equal(LengthVariantSwitchPlugin.isComplete('15000;', 5), false);
    });
});

describe('groupByRange — Längen verschiedener Größen nacheinander', () => {
    const ranges = LengthVariantSwitchPlugin.completeFixedSizes(['1,5 m', '4,5 m', '6,0 m']
        .map((text) => LengthVariantSwitchPlugin.parseRange(text)));

    test('teilt nach Größe in der Reihenfolge der Eingabe', () => {
        assert.deepEqual(
            LengthVariantSwitchPlugin.groupByRange(ranges, [1200, 4200, 1300, 4300], MM_PER_CM),
            [{ label: '1,5 m', lengths: [1200, 1300] }, { label: '4,5 m', lengths: [4200, 4300] }],
        );
    });

    test('eine Größe ergibt eine Gruppe', () => {
        assert.deepEqual(
            LengthVariantSwitchPlugin.groupByRange(ranges, [4200, 4300], MM_PER_CM),
            [{ label: '4,5 m', lengths: [4200, 4300] }],
        );
    });
});
