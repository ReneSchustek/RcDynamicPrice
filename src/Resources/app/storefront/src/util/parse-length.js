/**
 * Eine Längenangabe in Millimetern, mit oder ohne Einheit.
 *
 * Der Kunde soll nicht in Millimetern denken müssen. Mit Einheit ist eine Angabe eindeutig: „4,2 m",
 * „420 cm", „4200 mm", auch mit Punkt und ohne Leerzeichen. Ohne Einheit gilt eine Zahl mit Komma oder
 * Punkt als Meter, denn Millimeter gibt niemand mit Nachkommastellen an, und eine ganze Zahl als
 * Millimeter, wie es am Feld steht. Eine ganze Zahl unter 10 ist als Millimeter sinnlos und als Meter
 * geraten; dafür kommt `{ask}` zurück, und die Seite fragt nach der Einheit.
 *
 * Ein Tausenderpunkt geht dabei nicht schief: „4.200" als Meter gelesen sind 4200 mm, genau wie gemeint.
 *
 * Gemeinsam für das Meterpreis-Feld und den Längenschalter, damit „4,2" überall dasselbe heißt.
 *
 * @return {{mm: number}|{ask: number}|null}
 */
export function parseLength(text) {
    const match = String(text || '').trim().match(/^(\d+(?:[.,]\d+)?)\s*(mm|cm|m)?$/i);

    if (!match) {
        return null;
    }

    const number = Number(match[1].replace(',', '.'));
    const unit = match[2] ? match[2].toLowerCase() : '';
    const hasDecimals = /[.,]/.test(match[1]);

    if (number === 0) {
        return null;
    }

    if (unit === '' && !hasDecimals && number < 10) {
        return { ask: number };
    }

    const millimetresPerUnit = { mm: 1, cm: 10, m: 1000, '': hasDecimals ? 1000 : 1 };
    const mm = Math.round(number * millimetresPerUnit[unit]);

    return mm > 0 ? { mm } : null;
}
