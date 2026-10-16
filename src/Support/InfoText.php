<?php

namespace Platform\Recruiting\Support;

use Illuminate\Support\Str;

/**
 * Markdown einer Info → sicheres HTML fuers Portal (Spec 2026-10-16 §5.3).
 *
 * Zwei Sicherungen hintereinander, absichtlich: (1) commonmark mit
 * html_input=strip und allow_unsafe_links=false, (2) eine Weissliste der
 * Elemente per DOMDocument. Faellt eine Sicherung durch eine Bibliotheks-
 * aenderung weg, haelt die andere. Links werden zu Knoepfen (info-link,
 * target=_blank, rel=noopener), Telefonnummern im Fliesstext zu tel:-Links
 * (info-tel) — HR muss dafuer nichts tun. h1 wird h2: der Titel ist die h1.
 */
final class InfoText
{
    public const ERLAUBT = ['h2', 'h3', 'p', 'strong', 'em', 'ul', 'ol', 'li', 'a', 'br', 'hr'];

    public static function html(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }
        $roh = (string) Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $roh . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $dom->getElementById('root');
        if ($root === null) {
            return '';
        }

        self::h1ZuH2($dom, $root);
        self::nurErlaubte($dom, $root);
        self::linksZuKnoepfen($root);
        self::telefonnummernVerlinken($dom, $root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $kind) {
            $out .= $dom->saveHTML($kind);
        }

        return trim($out);
    }

    private static function h1ZuH2(\DOMDocument $dom, \DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('h1')) as $h1) {
            $h2 = $dom->createElement('h2');
            while ($h1->firstChild) {
                $h2->appendChild($h1->firstChild);
            }
            $h1->parentNode->replaceChild($h2, $h1);
        }
    }

    /** Unerlaubte Elemente: Inhalt bleibt (als Text/Kinder), das Element faellt; Attribute nur href an a. */
    private static function nurErlaubte(\DOMDocument $dom, \DOMElement $root): void
    {
        $alle = iterator_to_array($root->getElementsByTagName('*'));
        foreach (array_reverse($alle) as $el) {
            $name = strtolower($el->nodeName);
            if (!in_array($name, self::ERLAUBT, true)) {
                $eltern = $el->parentNode;
                while ($el->firstChild) {
                    $eltern->insertBefore($el->firstChild, $el);
                }
                $eltern->removeChild($el);
                continue;
            }
            foreach (iterator_to_array($el->attributes) as $attr) {
                if (!($name === 'a' && $attr->nodeName === 'href')) {
                    $el->removeAttribute($attr->nodeName);
                }
            }
            if ($name === 'a') {
                $href = trim((string) $el->getAttribute('href'));
                if (!preg_match('~^(https?://|tel:|mailto:)~i', $href)) {
                    $eltern = $el->parentNode;
                    while ($el->firstChild) {
                        $eltern->insertBefore($el->firstChild, $el);
                    }
                    $eltern->removeChild($el);
                }
            }
        }
    }

    private static function linksZuKnoepfen(\DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('a')) as $a) {
            if (str_starts_with(strtolower($a->getAttribute('href')), 'tel:')) {
                $a->setAttribute('class', 'info-tel');
                continue;
            }
            $a->setAttribute('class', 'info-link');
            $a->setAttribute('target', '_blank');
            $a->setAttribute('rel', 'noopener');
        }
    }

    /** +49 1523 4079291 / 0176 60450196 im Fliesstext → <a href="tel:+49…" class="info-tel">. Nicht innerhalb von <a>. */
    private static function telefonnummernVerlinken(\DOMDocument $dom, \DOMElement $root): void
    {
        $xpath = new \DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('.//text()[not(ancestor::a)]', $root)) as $text) {
            $wert = $text->nodeValue;
            if (!preg_match_all('/(\+49[\d\s\/-]{7,}\d|0\d{2,4}[\s\/-]?\d[\d\s\/-]{5,}\d)/', $wert, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $frag = $dom->createDocumentFragment();
            $pos = 0;
            foreach ($m[1] as [$treffer, $offset]) {
                $e164 = PhoneE164::normalize($treffer);
                if ($e164 === null) {
                    continue;
                }
                $frag->appendChild($dom->createTextNode(substr($wert, $pos, $offset - $pos)));
                $a = $dom->createElement('a', $treffer);
                $a->setAttribute('href', 'tel:' . $e164);
                $a->setAttribute('class', 'info-tel');
                $frag->appendChild($a);
                $pos = $offset + strlen($treffer);
            }
            $frag->appendChild($dom->createTextNode(substr($wert, $pos)));
            $text->parentNode->replaceChild($frag, $text);
        }
    }
}
