// Liest Bild-Rezepte aus Anleitungs-Markdown (gleiches Format wie AnleitungParser::rezept in PHP).

export function rezeptLesen(text) {
    const out = {};
    for (const m of text.matchAll(/([a-z]+)(?:=(?:"([^"]*)"|(\S+)))?/gu)) {
        out[m[1]] = m[2] !== undefined ? m[2] : (m[3] !== undefined ? m[3] : true);
    }
    return out;
}

export function rezepteAus(markdown) {
    const text = markdown.replace(/\r\n?/g, '\n');
    const bilder = [];
    const re = /^!\[([^\]]*)\]\(([^)\s]+)\)[ \t]*(?:\n<!--\s*shot:([\s\S]*?)-->)?/gm;
    for (const m of text.matchAll(re)) {
        bilder.push({ datei: m[2], alt: m[1].trim(), rezept: m[3] !== undefined ? rezeptLesen(m[3]) : {} });
    }
    return bilder;
}
