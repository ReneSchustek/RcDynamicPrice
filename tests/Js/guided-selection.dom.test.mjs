// Testet das Verhalten der geführten Auswahl am Markup: Name und Nummer des Elternartikels, die Auswahlliste mit
// Platzhalter, die Kennzeichnung „nicht kombinierbar", das Abfangen des Variantenwechsels und das Zwischenlager.
// Die Rechenregeln stehen in `guided-selection.test.mjs`. Der Quelltext wird wie dort in einer Stub-Umgebung
// evaluiert; Dokument und Speicher sind kleine Nachbauten, die nur bieten, was das Skript nutzt.

import { describe, test, beforeEach } from 'node:test';
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

/** Eine Klassenliste wie `DOMTokenList`, soweit das Skript sie nutzt. */
function classList(initial = []) {
    const set = new Set(initial);

    return {
        add: (...names) => names.forEach((name) => set.add(name)),
        remove: (...names) => names.forEach((name) => set.delete(name)),
        toggle: (name, force) => (force ? set.add(name) : set.delete(name)),
        contains: (name) => set.has(name),
    };
}

/** Ein Element, das nur Text trägt. */
function textElement(text) {
    return { textContent: text, classList: classList() };
}

function makeDocument({ title = '', names = [], numbers = [] } = {}) {
    return {
        title,
        body: { classList: classList() },
        querySelectorAll(selector) {
            if (selector === GuidedSelectionPlugin.options.nameSelector) {
                return names;
            }
            if (selector === GuidedSelectionPlugin.options.numberSelector) {
                return numbers;
            }

            return [];
        },
    };
}

function makePlugin(options = {}) {
    const plugin = Object.create(GuidedSelectionPlugin.prototype);
    plugin.options = { ...GuidedSelectionPlugin.options, ...options };
    plugin.el = { dataset: { rcGuidedSelection: 'parent-1' } };

    return plugin;
}

describe('_showParent — Name und Nummer des Elternartikels', () => {
    const options = {
        parentName: 'Relinggeländer',
        parentNumber: 'EB270100',
        variantName: 'Relinggeländer (3m)',
        variantNumber: 'EB270100-2-0',
    };

    test('solange nichts gewählt ist, stehen Name, Nummer und Seitentitel des Elternartikels da', () => {
        const name = textElement('Relinggeländer (3m)');
        const number = textElement('EB270100-2-0');
        globalThis.document = makeDocument({ title: 'Relinggeländer (3m) | Shop', names: [name], numbers: [number] });

        makePlugin(options)._showParent(true);

        assert.equal(name.textContent, 'Relinggeländer');
        assert.equal(number.textContent, 'EB270100');
        assert.equal(document.title, 'Relinggeländer | Shop');
    });

    test('nach der ersten Wahl kehren Name, Nummer und Seitentitel der Variante zurück', () => {
        const name = textElement('Relinggeländer (3m)');
        const number = textElement('EB270100-2-0');
        globalThis.document = makeDocument({ title: 'Relinggeländer (3m) | Shop', names: [name], numbers: [number] });
        const plugin = makePlugin(options);

        plugin._showParent(true);
        plugin._showParent(false);

        assert.equal(name.textContent, 'Relinggeländer (3m)');
        assert.equal(number.textContent, 'EB270100-2-0');
        assert.equal(document.title, 'Relinggeländer (3m) | Shop');
    });

    test('eine Stelle ohne eine der beiden Nummern bleibt unberührt', () => {
        const other = textElement('Lieferzeit 3 Tage');
        globalThis.document = makeDocument({ numbers: [other] });

        makePlugin(options)._showParent(true);

        assert.equal(other.textContent, 'Lieferzeit 3 Tage');
    });

    test('ohne Angaben zum Elternartikel wird nichts getauscht', () => {
        const name = textElement('Relinggeländer (3m)');
        globalThis.document = makeDocument({ title: 'Titel', names: [name] });

        makePlugin({ ...options, parentName: '' })._showParent(true);

        assert.equal(name.textContent, 'Relinggeländer (3m)');
        assert.equal(document.title, 'Titel');
    });
});

/** Eine Auswahlliste mit `Option`-Nachbauten; `prepend` und `remove` halten die Liste aktuell. */
function makeSelect(entries) {
    const select = { value: '', options: [] };
    const toOption = ({ value, text, title = '' }) => {
        const option = {
            value,
            textContent: text,
            title,
            hidden: false,
            disabled: false,
            removeAttribute(name) {
                this[name] = '';
            },
            remove() {
                select.options = select.options.filter((candidate) => candidate !== option);
            },
        };

        return option;
    };
    select.options = entries.map(toOption);
    select.prepend = (option) => {
        option.remove = () => {
            select.options = select.options.filter((candidate) => candidate !== option);
        };
        select.options.unshift(option);
    };
    select.querySelector = (selector) => (selector === 'option[value=""]'
        ? select.options.find((option) => option.value === '') || null
        : null);

    return select;
}

describe('_renderSelect — die Auswahlliste eines Schritts', () => {
    beforeEach(() => {
        globalThis.Option = function Option(text, value) {
            this.textContent = text;
            this.value = value;
            this.disabled = false;
        };
    });

    function render(select, possible, answered, confirmed = {}) {
        const plugin = makePlugin({ textPlaceholder: 'Bitte wählen' });
        plugin._field = () => select;
        plugin._confirmed = confirmed;
        plugin._renderSelect('dimensions', possible, answered);
    }

    test('nicht kaufbare Optionen verschwinden und sind gesperrt', () => {
        const select = makeSelect([{ value: 'm-1', text: '1,0 m' }, { value: 'm-2', text: '2,0 m' }]);

        render(select, ['m-2'], false);

        const [one, two] = select.options.filter((option) => option.value !== '');
        assert.equal(one.hidden, true);
        assert.equal(one.disabled, true);
        assert.equal(two.hidden, false);
    });

    test('der Zusatz „(nicht verfügbar)" und der Titel des Kerns fallen bei kaufbaren Optionen weg', () => {
        const select = makeSelect([{ value: 'm-2', text: '2,0 m (nicht verfügbar)', title: 'nicht verfügbar' }]);

        render(select, ['m-2'], false);

        const option = select.options.find((candidate) => candidate.value === 'm-2');
        assert.equal(option.textContent, '2,0 m');
        assert.equal(option.title, '');
    });

    test('unbeantwortet steht ein gesperrter Platzhalter oben, und nichts ist gewählt', () => {
        const select = makeSelect([{ value: 'm-2', text: '2,0 m' }]);
        select.value = 'm-2';

        render(select, ['m-2'], false);

        assert.equal(select.options[0].value, '');
        assert.equal(select.options[0].textContent, 'Bitte wählen');
        assert.equal(select.options[0].disabled, true);
        assert.equal(select.value, '');
    });

    test('beantwortet verschwindet der Platzhalter, und die Antwort ist gewählt', () => {
        const select = makeSelect([{ value: '', text: 'Bitte wählen' }, { value: 'm-2', text: '2,0 m' }]);

        render(select, ['m-2'], true, { dimensions: 'm-2' });

        assert.equal(select.options.some((option) => option.value === ''), false);
        assert.equal(select.value, 'm-2');
    });
});

describe('clearUnavailableMark — gezeigte Optionen sind kaufbar', () => {
    test('nimmt die Kennzeichnung des Kerns und den versteckten Hinweis weg', () => {
        const label = { classList: classList(['not-combinable', 'disabled']) };
        let noteRemoved = false;
        const note = { remove: () => { noteRemoved = true; } };
        const option = {
            querySelectorAll: (selector) => (selector === '.not-combinable' ? [label] : selector === 'small.visually-hidden' ? [note] : []),
        };

        GuidedSelectionPlugin.clearUnavailableMark(option);

        assert.equal(label.classList.contains('not-combinable'), false);
        assert.equal(label.classList.contains('disabled'), false);
        assert.equal(label.classList.contains('is-combinable'), true);
        assert.equal(noteRemoved, true);
    });
});

describe('_onChange — vor dem Variantenwechsel des Kerns', () => {
    function plugin() {
        const instance = makePlugin();
        instance._groups = [{ id: 'dimensions' }, { id: 'design' }];
        instance._current = { dimensions: 'm-2', design: 'p-3' };
        instance._confirmed = {};
        instance._configurator = { contains: () => true };
        instance.calls = [];
        instance._confirm = (index, optionId) => instance.calls.push(['confirm', index, optionId]);
        instance._stash = (answers) => instance.calls.push(['stash', answers]);
        instance._restoreShownVariant = (groupId) => instance.calls.push(['restore', groupId]);

        return instance;
    }

    function event(name, value) {
        return { target: { name, value }, stopped: false, stopPropagation() { this.stopped = true; } };
    }

    test('die Option der gezeigten Variante wird nur bestätigt, und das Ereignis endet hier', () => {
        const instance = plugin();
        const change = event('dimensions', 'm-2');

        instance._onChange(change);

        assert.equal(change.stopped, true);
        assert.deepEqual(instance.calls, [['confirm', 0, 'm-2']]);
    });

    test('eine andere Option merkt die Antworten und überlässt den Wechsel dem Kern', () => {
        const instance = plugin();
        instance._confirmed = { dimensions: 'm-2' };
        const change = event('design', 'p-2');

        instance._onChange(change);

        assert.equal(change.stopped, false);
        assert.deepEqual(instance.calls, [['stash', { dimensions: 'm-2', design: 'p-2' }], ['restore', 'design']]);
    });

    test('ein Feld außerhalb der Gruppen geht die Auswahl nichts an', () => {
        const instance = plugin();
        const change = event('quantity', '2');

        instance._onChange(change);

        assert.equal(change.stopped, false);
        assert.deepEqual(instance.calls, []);
    });
});

describe('_stash und _takeStash — das Zwischenlager', () => {
    function storage(initial = {}) {
        const data = { ...initial };

        return {
            data,
            getItem: (key) => (key in data ? data[key] : null),
            setItem: (key, value) => { data[key] = String(value); },
        };
    }

    test('gemerkte Antworten kommen je Elternartikel zurück', () => {
        globalThis.window = { sessionStorage: storage() };
        const instance = makePlugin();

        instance._stash({ dimensions: 'm-2' });

        assert.deepEqual(instance._takeStash(), { dimensions: 'm-2' });
        assert.ok('rcGuidedSelection:parent-1' in window.sessionStorage.data);
    });

    test('ein unlesbares Zwischenlager zählt als leer', () => {
        globalThis.window = { sessionStorage: storage({ 'rcGuidedSelection:parent-1': '{kaputt' }) };

        assert.deepEqual(makePlugin()._takeStash(), {});
    });

    test('ohne Speicher wirft keiner der beiden Zugriffe', () => {
        globalThis.window = {
            sessionStorage: {
                getItem: () => { throw new Error('gesperrt'); },
                setItem: () => { throw new Error('gesperrt'); },
            },
        };
        const instance = makePlugin();

        assert.doesNotThrow(() => instance._stash({ dimensions: 'm-2' }));
        assert.deepEqual(instance._takeStash(), {});
    });
});
