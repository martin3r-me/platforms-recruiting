#!/usr/bin/env node
// Erzeugt die Screenshots der Kundenanleitungen aus ihren <!-- shot: … -->-Rezepten.
// Aufruf: node tools/anleitungen-screenshots <slug|--alle|--anmelden>
// Laeuft gegen die Demo (nur Testdaten). Sitzung liegt ausserhalb des Repos.
import { chromium } from 'playwright-core';
import { readFileSync, readdirSync, existsSync, mkdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { rezepteAus } from './rezepte.mjs';
import { ziel, ausschnittElement } from './ziele.mjs';

const BASIS = (process.env.RG_ANLEITUNGEN_BASIS || 'https://demo.bhgdigital.de').replace(/\/$/, '');
// Eine Sitzung je Umgebung — die Demo-Anmeldung ueberschreibt nicht die von Prod.
const HOST = new URL(BASIS).host;
const SITZUNG = join(homedir(), '.config', 'rg-anleitungen', HOST.startsWith('demo.') ? 'sitzung.json' : `sitzung-${HOST}.json`);
const WURZEL = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', 'docs', 'anleitungen');
const BLAU = '#1273DE';
const RAND = 16;

async function anmelden() {
    mkdirSync(dirname(SITZUNG), { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: false });
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(`${BASIS}/recruiting`);
    console.log('Bitte im Chrome-Fenster anmelden … (bis zu 5 Minuten)');
    await page.waitForURL(u => u.href.startsWith(`${BASIS}/recruiting`), { timeout: 300_000 });
    await ctx.storageState({ path: SITZUNG });
    await browser.close();
    console.log(`Sitzung gespeichert: ${SITZUNG}`);
}

async function aufnehmen(browser, slug, bild) {
    const r = bild.rezept;
    const ctx = await browser.newContext({
        storageState: SITZUNG,
        viewport: r.handy ? { width: 390, height: 844 } : { width: 1440, height: 900 },
        deviceScaleFactor: 2,
        colorScheme: 'light',
        isMobile: Boolean(r.handy),
    });
    const page = await ctx.newPage();
    try {
        await page.goto(BASIS + r.seite, { waitUntil: 'networkidle' });
        if (/\/login|microsoftonline/.test(page.url())) {
            throw new Error('Sitzung abgelaufen — node tools/anleitungen-screenshots --anmelden');
        }
        for (const klick of String(r.klicks || '').split(';').map(s => s.trim()).filter(Boolean)) {
            await ziel(page, klick).click();
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(1500);
        }
        // Schutz echter Daten (Prod): Fotos immer unscharf, dazu je Rezept
        // `verdecken="css:…; css:…"` fuer Namen, Nummern o. ae. — Text bleibt
        // als Form erkennbar, ist aber nicht lesbar.
        if (!BASIS.includes('demo.')) {
            await page.addStyleTag({ content: 'img{filter:blur(10px)!important}' });
        }
        for (const v of String(r.verdecken || '').split(';').map(s => s.trim()).filter(Boolean)) {
            const sel = v.startsWith('css:') ? v.slice(4) : v;
            await page.locator(sel).evaluateAll(els => els.forEach(e => {
                e.style.filter = 'blur(8px)';
                e.style.userSelect = 'none';
            }));
        }
        if (typeof r.markieren === 'string') {
            await ziel(page, r.markieren).evaluate((el, farbe) => {
                el.style.outline = `3px solid ${farbe}`;
                el.style.outlineOffset = '3px';
                el.style.borderRadius = el.style.borderRadius || '4px';
            }, BLAU);
        }
        const datei = join(WURZEL, 'bilder', slug, bild.datei);
        mkdirSync(dirname(datei), { recursive: true });
        if (typeof r.ausschnitt === 'string') {
            const el = await ausschnittElement(page, r.ausschnitt);
            await el.scrollIntoViewIfNeeded();
            const box = await el.evaluate(e => {
                const b = e.getBoundingClientRect();
                return { x: b.x + window.scrollX, y: b.y + window.scrollY, width: b.width, height: b.height };
            });
            await page.screenshot({
                path: datei,
                fullPage: true,
                clip: {
                    x: Math.max(0, box.x - RAND),
                    y: Math.max(0, box.y - RAND),
                    width: box.width + 2 * RAND,
                    height: box.height + 2 * RAND,
                },
            });
        } else {
            await page.screenshot({ path: datei });
        }
        console.log(`  ✓ ${bild.datei}`);
    } finally {
        await ctx.close();
    }
}

async function main() {
    const arg = process.argv[2];
    if (!arg) {
        console.error('Aufruf: node tools/anleitungen-screenshots <slug|--alle|--anmelden>');
        process.exit(1);
    }
    if (arg === '--anmelden' || !existsSync(SITZUNG)) {
        await anmelden();
        if (arg === '--anmelden') return;
    }
    const slugs = arg === '--alle'
        ? readdirSync(WURZEL).filter(f => f.endsWith('.md') && f !== 'README.md').map(f => f.slice(0, -3))
        : [arg];

    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    let fehler = 0;
    try {
        for (const slug of slugs) {
            const pfad = join(WURZEL, `${slug}.md`);
            if (!existsSync(pfad)) {
                console.error(`Anleitung ${slug} gibt es nicht.`);
                fehler++;
                continue;
            }
            console.log(slug);
            for (const bild of rezepteAus(readFileSync(pfad, 'utf8'))) {
                if (bild.rezept.manuell || !bild.rezept.seite) {
                    console.log(`  – ${bild.datei} (manuell)`);
                    continue;
                }
                try {
                    await aufnehmen(browser, slug, bild);
                } catch (e) {
                    fehler++;
                    console.error(`  ✗ ${bild.datei}: ${e.message}`);
                }
            }
        }
    } finally {
        await browser.close();
    }
    process.exit(fehler ? 1 : 0);
}

main();
