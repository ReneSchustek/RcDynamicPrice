// Unit-Tests für die extrahierte HintModal-Klasse.
// Zero-dependency: node:test plus ein minimaler Fake-DOM, der genau die von
// HintModal genutzten DOM-APIs abbildet (createElement, appendChild, textContent,
// setAttribute). Direkter ESM-Import ist möglich, weil hint-modal.js keine
// Shopware-Aliase importiert.

import { describe, test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const modulePath = join(
    __dirname, '..', '..',
    'src', 'Resources', 'app', 'storefront', 'src', 'util', 'hint-modal.js',
);

// pathToFileURL statt roher Pfad: der ESM-Loader akzeptiert unter Windows nur
// file://-URLs — ein absoluter Pfad wie F:\... wird sonst als Protokoll "f:" gelesen.
const { default: HintModal } = await import(pathToFileURL(modulePath).href);

// --- Minimaler Fake-DOM ---------------------------------------------------

class FakeStyle {
    constructor() {
        this._props = {};
    }

    setProperty(key, value) {
        this._props[key] = value;
    }

    getPropertyValue(key) {
        return this._props[key] ?? '';
    }
}

class FakeElement {
    constructor(tag, doc) {
        this.tagName = tag.toUpperCase();
        this._doc = doc;
        this.className = '';
        this.id = '';
        this.attributes = {};
        this.children = [];
        this.parentNode = null;
        this.style = new FakeStyle();
        this._listeners = {};
        this._textContent = '';
        this.focusCount = 0;
    }

    setAttribute(key, value) {
        this.attributes[key] = value;
        if (key === 'id') {
            this.id = value;
        }
    }

    getAttribute(key) {
        return this.attributes[key] ?? null;
    }

    addEventListener(type, fn) {
        (this._listeners[type] ||= []).push(fn);
    }

    removeEventListener(type, fn) {
        const list = this._listeners[type];
        if (!list) {
            return;
        }
        const idx = list.indexOf(fn);
        if (idx >= 0) {
            list.splice(idx, 1);
        }
    }

    dispatch(type, event) {
        (this._listeners[type] || []).slice().forEach((fn) => fn(event));
    }

    appendChild(child) {
        child.parentNode = this;
        this.children.push(child);
        return child;
    }

    remove() {
        if (this.parentNode) {
            const siblings = this.parentNode.children;
            const idx = siblings.indexOf(this);
            if (idx >= 0) {
                siblings.splice(idx, 1);
            }
            this.parentNode = null;
        }
    }

    focus() {
        this.focusCount++;
        this._doc.activeElement = this;
    }

    // textContent setzt einen reinen Textknoten: vorhandene Kindelemente entfallen,
    // Markup im Wert wird nie geparst. Genau darauf stützt sich der XSS-Schutz.
    set textContent(value) {
        this._textContent = String(value);
        this.children = [];
    }

    get textContent() {
        return this._textContent;
    }

    _descendants() {
        const out = [];
        const walk = (node) => {
            node.children.forEach((child) => {
                out.push(child);
                walk(child);
            });
        };
        walk(this);
        return out;
    }

    querySelector(selector) {
        return this._descendants().find((el) => el._matches(selector)) || null;
    }

    querySelectorAll(selector) {
        return this._descendants().filter((el) => el._matches(selector));
    }

    _matches(selector) {
        return selector.split(',').some((rawPart) => {
            const part = rawPart.trim();
            if (part.startsWith('.')) {
                return this.className.split(/\s+/).includes(part.slice(1));
            }
            // 'button:not([disabled])' / 'a[href]' -> Tag-Anteil vor : [ Leerzeichen
            const tag = part.split(/[:[\s]/)[0];
            return tag !== '' && this.tagName === tag.toUpperCase();
        });
    }
}

function createFakeDocument() {
    const doc = {
        activeElement: null,
        _listeners: {},
        createElement(tag) {
            return new FakeElement(tag, doc);
        },
        addEventListener(type, fn) {
            (doc._listeners[type] ||= []).push(fn);
        },
        removeEventListener(type, fn) {
            const list = doc._listeners[type];
            if (!list) {
                return;
            }
            const idx = list.indexOf(fn);
            if (idx >= 0) {
                list.splice(idx, 1);
            }
        },
        dispatch(type, event) {
            (doc._listeners[type] || []).slice().forEach((fn) => fn(event));
        },
    };
    doc.body = new FakeElement('body', doc);
    return doc;
}

function keyEvent(key, shiftKey = false) {
    return {
        key,
        shiftKey,
        defaultPrevented: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
    };
}

// --- Tests ----------------------------------------------------------------

describe('HintModal', () => {
    let doc;
    let trigger;

    beforeEach(() => {
        doc = createFakeDocument();
        trigger = doc.createElement('input');
        doc.activeElement = trigger;
    });

    test('open() hängt Backdrop und Modal an den Body und setzt ARIA-Attribute', () => {
        const modal = new HintModal({ text: 'Hinweis', titleId: 'title-1', document: doc });
        modal.open();

        assert.equal(doc.body.children.length, 2);
        const modalEl = doc.body.querySelector('.rc-dynamic-price-modal');
        assert.ok(modalEl, 'Modal muss im Body hängen');
        assert.equal(modalEl.getAttribute('role'), 'dialog');
        assert.equal(modalEl.getAttribute('aria-modal'), 'true');
        assert.equal(modalEl.getAttribute('aria-labelledby'), 'title-1');
        // aria-labelledby muss auf den tatsächlich vorhandenen Titelknoten zeigen.
        assert.equal(doc.body.querySelector('p').getAttribute('id'), 'title-1');
    });

    test('open() legt den Fokus auf den Schließen-Button', () => {
        const modal = new HintModal({ text: 'Hinweis', document: doc });
        modal.open();

        const closeButton = doc.body.querySelector('.rc-dynamic-price-modal__close');
        assert.equal(doc.activeElement, closeButton);
    });

    test('close() entfernt den Dialog und stellt den vorherigen Fokus wieder her', () => {
        const modal = new HintModal({ text: 'Hinweis', document: doc });
        modal.open();
        modal.close();

        assert.equal(doc.body.children.length, 0);
        assert.equal(doc.activeElement, trigger, 'Fokus zurück auf das auslösende Element');
    });

    test('Escape-Taste schließt den Dialog', () => {
        const modal = new HintModal({ text: 'Hinweis', document: doc });
        modal.open();

        doc.dispatch('keydown', keyEvent('Escape'));

        assert.equal(doc.body.children.length, 0);
        assert.equal(doc.activeElement, trigger);
    });

    test('Klick auf den Backdrop schließt den Dialog', () => {
        const modal = new HintModal({ text: 'Hinweis', document: doc });
        modal.open();

        const backdrop = doc.body.querySelector('.rc-dynamic-price-backdrop');
        backdrop.dispatch('click', {});

        assert.equal(doc.body.children.length, 0);
    });

    test('Klick auf den Schließen-Button schließt den Dialog', () => {
        const modal = new HintModal({ text: 'Hinweis', document: doc });
        modal.open();

        const closeButton = doc.body.querySelector('.rc-dynamic-price-modal__close');
        closeButton.dispatch('click', {});

        assert.equal(doc.body.children.length, 0);
    });

    test('Focus-Trap zykelt Tab und Shift+Tab am einzigen fokussierbaren Knoten', () => {
        const modal = new HintModal({ text: 'Hinweis', document: doc });
        modal.open();

        const modalEl = doc.body.querySelector('.rc-dynamic-price-modal');
        const closeButton = doc.body.querySelector('.rc-dynamic-price-modal__close');
        // Close ist der einzige fokussierbare Knoten -> first === last === closeButton.
        doc.activeElement = closeButton;

        const tab = keyEvent('Tab', false);
        modalEl.dispatch('keydown', tab);
        assert.equal(tab.defaultPrevented, true, 'Tab am letzten Knoten muss umgeleitet werden');

        const shiftTab = keyEvent('Tab', true);
        modalEl.dispatch('keydown', shiftTab);
        assert.equal(shiftTab.defaultPrevented, true, 'Shift+Tab am ersten Knoten muss umgeleitet werden');
    });

    test('applyThemeVariables setzt die theme-abhängigen Inline-Styles', () => {
        const modal = new HintModal({ text: 'Hinweis', document: doc });
        modal.open();

        const modalEl = doc.body.querySelector('.rc-dynamic-price-modal');
        assert.equal(modalEl.style.getPropertyValue('background'), 'var(--bs-body-bg, #fff)');
        assert.equal(modalEl.style.getPropertyValue('color'), 'var(--bs-body-color, #212529)');
        assert.match(modalEl.style.getPropertyValue('border'), /var\(--bs-border-color/);
    });

    test('Hinweis-Text landet als Textknoten, nicht als Markup (XSS-Schutz)', () => {
        const modal = new HintModal({ text: '<b>x</b>', document: doc });
        modal.open();

        const title = doc.body.querySelector('p');
        assert.equal(title.textContent, '<b>x</b>', 'Text bleibt unverändert erhalten');
        assert.equal(title.children.length, 0, 'Markup im Text darf kein Element erzeugen');
    });

    test('Button-Beschriftung landet als Textknoten, nicht als Markup (XSS-Schutz)', () => {
        const modal = new HintModal({ text: 'Hinweis', buttonLabel: '<img src=x>', document: doc });
        modal.open();

        const closeButton = doc.body.querySelector('.rc-dynamic-price-modal__close');
        assert.equal(closeButton.textContent, '<img src=x>');
        assert.equal(closeButton.children.length, 0);
    });

    test('titleId kann nicht aus dem id-Attribut ausbrechen', () => {
        // Regression: früher wurde titleId roh in einen id="..."-String interpoliert.
        const modal = new HintModal({ text: 'Hinweis', titleId: '" onload="alert(1)', document: doc });
        modal.open();

        const title = doc.body.querySelector('p');
        assert.equal(title.getAttribute('id'), '" onload="alert(1)', 'Wert bleibt reiner Attributwert');
        assert.equal(title.getAttribute('onload'), null, 'Kein zusätzliches Attribut entstanden');
    });

    test('die Quelle verwendet kein innerHTML (Regressions-Gate gegen DOM-XSS)', () => {
        // Der Fake-DOM oben modelliert innerHTML bewusst nicht. Ohne diese Prüfung
        // würde eine Rückkehr zur String-Konkatenation von keinem Test bemerkt.
        // Kommentare werden entfernt, damit die Doku das Wort nennen darf.
        const code = readFileSync(modulePath, 'utf8')
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/\/\/.*$/gm, '');

        assert.doesNotMatch(code, /innerHTML/, 'DOM-Aufbau muss über createElement/textContent laufen');
        assert.doesNotMatch(code, /insertAdjacentHTML/);
    });
});
