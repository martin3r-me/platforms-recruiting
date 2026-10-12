// Wie ein Rezept-Wert ein Element findet: sichtbarer Text oder "css:"-Selektor.
// Immer nur SICHTBARE Treffer: die VA-Seiten rendern die (versteckte) Handy-Liste vor der Tabelle.

export function ziel(page, wert) {
    const roh = wert.startsWith('css:') ? page.locator(wert.slice(4)) : page.getByText(wert, { exact: false });
    return roh.filter({ visible: true }).first();
}

// Ausschnitt per Text: der naechste umschliessende Kasten (Karte, Tabelle, Fenster).
export async function ausschnittElement(page, wert) {
    if (wert.startsWith('css:')) {
        return ziel(page, wert);
    }
    return ziel(page, wert)
        .locator('xpath=ancestor-or-self::*[self::table or self::section or @role="dialog" or contains(@class,"rounded-lg") or contains(@class,"rounded-xl")][1]');
}
