<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Support\Facades\DB;

/**
 * Umkehrung von ZasLookupResolver: ZAS liefert Klartext-Labels, wir brauchen
 * unseren Lookup-Code. Hybrid: exakter (case-insensitiver) Match gegen value
 * ODER label; optional Praefix-Match; sonst roher Wert zurueck (matched=false),
 * damit der Aufrufer eine Warnung setzen kann.
 */
class ZasLookupReverseResolver
{
    /** Cache: lookupName → Liste [['value'=>..,'label'=>..], ...] */
    protected array $cache = [];

    /**
     * Bekannte ZAS-Schreibweisen → unsere Lookup-Codes (aus dem 100er-Testlauf).
     * Keys lowercase. Ein Alias greift NUR, wenn der Ziel-Code im Lookup
     * existiert (sonst Raw-Fallback wie bisher) — dadurch unabhaengig davon
     * deploybar, ob neue Lookup-Werte schon angelegt sind.
     */
    private const ALIASES = [
        'geburtsland' => [
            'deutsch' => 'de',
            'indisch' => 'in', 'indian' => 'in',
            'kosovarisch' => 'xk', 'kosov' => 'xk',
            'tuerkisch' => 'tr', 'türkisch' => 'tr',
            'ghanaisch' => 'gh',
            'lettisch' => 'lv',
            'tunesisch' => 'tn', 'tunesische' => 'tn', 'tunisien' => 'tn',
            'irakisch' => 'iq', 'irakische' => 'iq',
            'iranisch' => 'ir',
            'pakistani' => 'pk', 'pakistanisch' => 'pk',
            'bangladeshi' => 'bd', 'bangladesh' => 'bd', 'bangladeschisch' => 'bd',
            'griechisch' => 'gr',
            'libanesisch' => 'lb',
            'armenisch' => 'am',
            'venezolanisch' => 've',
            'kamerunisch' => 'cm',
        ],
        'beschaeftigung_art' => [
            'studentin' => 'student',
            'student, erwerbstätig' => 'student',
            'student erwerbst.' => 'student',
            'dualer student' => 'student',
            'angestellt' => 'erwerbstaetig',
            'hausfrau' => 'hausmann_frau', 'hausmann' => 'hausmann_frau', 'hausfrau / mann' => 'hausmann_frau',
        ],
        'krankenkasse' => [
            'technicker krankenkassen' => 'tk', 'techniker krankenkassen' => 'tk',
        ],
        'anstellungsart' => [
            '556,00 € basis' => 'minijob',
        ],
    ];

    public function resolve(string $lookupName, ?string $incoming, bool $allowPrefix = false): array
    {
        $pairs = $this->loadPairs($lookupName);
        $res = self::matchValue($pairs, $incoming, $allowPrefix);
        if ($res['matched'] || $res['value'] === null) {
            return $res;
        }

        // Alias-Stufe: bekannte ZAS-Schreibweisen — nur wenn der Ziel-Code
        // tatsaechlich als Lookup-Wert existiert.
        $alias = self::ALIASES[$lookupName][mb_strtolower(trim((string) $incoming))] ?? null;
        if ($alias !== null && in_array($alias, array_column($pairs, 'value'), true)) {
            return ['value' => $alias, 'matched' => true];
        }

        return $res;
    }

    /**
     * @param array<int,array{value:string,label:string}> $pairs
     * @return array{value: ?string, matched: bool}
     */
    public static function matchValue(array $pairs, ?string $incoming, bool $allowPrefix): array
    {
        $needle = $incoming === null ? '' : trim($incoming);
        if ($needle === '') {
            return ['value' => null, 'matched' => true];
        }
        $lc = mb_strtolower($needle);

        // 1. exakter Match gegen value oder label (case-insensitiv)
        foreach ($pairs as $p) {
            if (mb_strtolower((string) $p['value']) === $lc || mb_strtolower((string) $p['label']) === $lc) {
                return ['value' => $p['value'], 'matched' => true];
            }
        }
        // 2. Praefix-Match (nur wenn erlaubt) — z.B. "Vollzeit 172 Stunden" → vollzeit
        if ($allowPrefix) {
            foreach ($pairs as $p) {
                $lbl = mb_strtolower((string) $p['label']);
                $val = mb_strtolower((string) $p['value']);
                if (($lbl !== '' && str_starts_with($lc, $lbl)) || ($val !== '' && str_starts_with($lc, $val))) {
                    return ['value' => $p['value'], 'matched' => true];
                }
            }
        }
        // 3. kein Treffer → roher Wert (Aufrufer warnt)
        return ['value' => $needle, 'matched' => false];
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    protected function loadPairs(string $lookupName): array
    {
        if (!isset($this->cache[$lookupName])) {
            $lookupId = DB::table('core_lookups')->where('name', $lookupName)->value('id');
            $this->cache[$lookupName] = $lookupId
                ? DB::table('core_lookup_values')
                    ->where('lookup_id', $lookupId)
                    ->get(['value', 'label'])
                    ->map(fn ($r) => ['value' => (string) $r->value, 'label' => (string) $r->label])
                    ->all()
                : [];
        }
        return $this->cache[$lookupName];
    }
}
