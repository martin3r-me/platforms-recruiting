import { test } from 'node:test';
import assert from 'node:assert/strict';
import { rezeptLesen, rezepteAus } from './rezepte.mjs';

test('rezept: werte, anfuehrungszeichen, flags', () => {
    assert.deepEqual(
        rezeptLesen(' seite=/a klicks="Öffnen; Weiter" ausschnitt="css:[role=dialog]" handy '),
        { seite: '/a', klicks: 'Öffnen; Weiter', ausschnitt: 'css:[role=dialog]', handy: true },
    );
});

test('rezepte aus markdown: bild mit und ohne rezept, mehrzeiliger kommentar', () => {
    const md = [
        '## A | B', '',
        '![Bild eins](eins.png)',
        '<!-- shot: seite=/x',
        '     markieren="prüfen" -->', '',
        '1. a', '',
        '## C', '',
        '![Bild zwei](zwei.png)', '',
    ].join('\n');

    assert.deepEqual(rezepteAus(md), [
        { datei: 'eins.png', alt: 'Bild eins', rezept: { seite: '/x', markieren: 'prüfen' } },
        { datei: 'zwei.png', alt: 'Bild zwei', rezept: {} },
    ]);
});
