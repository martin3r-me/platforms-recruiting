import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import { ziel, ausschnittElement } from './ziele.mjs';

// Die VA-Seiten rendern die Handy-Liste (versteckt) VOR der Tabelle — das Rezept muss das sichtbare Element treffen.
const HTML = `
  <div style="display:none"><section><span>TEST VA</span><button class="bg-violet-100">mobil</button></section></div>
  <table id="tab"><tr><td><span>TEST VA</span></td><td><button class="bg-violet-100" id="sichtbar">desktop</button></td></tr></table>`;

test('ziel und ausschnitt treffen nur sichtbare elemente', async () => {
    const browser = await chromium.launch({ channel: 'chrome' });
    try {
        const page = await browser.newPage();
        await page.setContent(HTML);
        assert.equal(await ziel(page, 'TEST VA').isVisible(), true);
        assert.equal(await ziel(page, 'css:.bg-violet-100').getAttribute('id'), 'sichtbar');
        assert.equal(await (await ausschnittElement(page, 'TEST VA')).getAttribute('id'), 'tab');
    } finally {
        await browser.close();
    }
});
