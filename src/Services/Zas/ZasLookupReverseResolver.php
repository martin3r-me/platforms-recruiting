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

    public function resolve(string $lookupName, ?string $incoming, bool $allowPrefix = false): array
    {
        return self::matchValue($this->loadPairs($lookupName), $incoming, $allowPrefix);
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
