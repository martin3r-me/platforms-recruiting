<?php

namespace Platform\Recruiting\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecHrVermerk;
use Platform\Recruiting\Support\Bote;
use Platform\Recruiting\Support\DokumentKategorie;
use Platform\Recruiting\Support\DokumentStatus;
use Platform\Recruiting\Support\EinsatzBezug;
use Platform\Recruiting\Support\HrEinstellungen;
use Platform\Recruiting\Support\KontoStatus;
use Platform\Recruiting\Support\LohnMa;
use Platform\Recruiting\Support\NummernwechselTafel;
use Platform\Recruiting\Support\PhoneE164;
use Platform\Recruiting\Support\PilotKreis;
use Platform\Recruiting\Support\ProofChecklist;
use Platform\Recruiting\Support\ProofTypes;
use Platform\Recruiting\Support\SperrGruende;
use Platform\Recruiting\Support\VertragErinnerungsPause;
use Platform\Recruiting\Support\VertragsHerkunft;

/**
 * Die Lese-Abfragen des HR-Bereichs "Mitarbeiter" (Spec 2026-10-12 §3) —
 * reine Leser, kein Schreibzugriff. Leitsatz: EIN MENSCH = EINE ZEILE
 * (Personen-Klammer rec_persons; Akten ohne Personen-Zeile je eine Zeile).
 * Team-Scope: Personen ueber rec_persons.team_id UND Akte im Team;
 * Nachweise/Dokumente/Vertraege ueber rec_employees.team_id.
 *
 * Eine Akte, deren rec_person_id auf eine teamfremde oder stillgelegte
 * Person zeigt, zaehlt als Akte ohne Person ('e:<id>') — dieselbe Regel wie
 * HrKontoAktionen::personDerAkteImTeam(), damit z. B. ein "kontaktiert"-
 * Vermerk (dort an der Akte gespeichert) hier auch greift (Fix-Runde 1, M3).
 */
final class HrMitarbeiterListen
{
    private const AKTEN_SPALTEN = [
        'id', 'rec_person_id', 'first_name', 'last_name', 'company', 'is_active', 'phone',
        'portal_v2_since', 'portal_verified_at', 'portal_last_seen_at', 'portal_locked_at', 'portal_locked_reason',
    ];

    /** Zustand eines Antrags, dessen Person keine Akte im Team hat — nur stoppbar. */
    public const OHNE_AKTE = 'keine Akte im Team';

    public function __construct(
        private readonly ?PortalAuth $auth = null,
        private readonly ?OffenePunkte $offenePunkte = null,
        private readonly ?\Closure $nachrichtGescheitert = null,
        private readonly ?LohnMaErmittlung $lohnErmittlung = null,
    ) {
    }

    /**
     * @param  iterable<object>  $akten  nach id sortiert
     * @return array<string, array{schluessel:string, person_id:?int, employee_id:?int, name:string, akten:list<array{id:int, firma:string, aktiv:bool}>, employee_ids:list<int>}>
     */
    public static function menschen(iterable $akten): array
    {
        $menschen = [];
        foreach ($akten as $a) {
            $schluessel = self::schluessel($a);
            if (!isset($menschen[$schluessel])) {
                $name = trim(((string) ($a->first_name ?? '')) . ' ' . ((string) ($a->last_name ?? '')));
                $menschen[$schluessel] = [
                    'schluessel'   => $schluessel,
                    'person_id'    => $a->rec_person_id !== null ? (int) $a->rec_person_id : null,
                    'employee_id'  => (int) $a->id,
                    'name'         => $name !== '' ? $name : 'Akte #' . (int) $a->id,
                    'akten'        => [],
                    'employee_ids' => [],
                ];
            }
            $firma = trim((string) ($a->company ?? ''));
            $menschen[$schluessel]['akten'][] = ['id' => (int) $a->id, 'firma' => $firma !== '' ? $firma : 'Akte', 'aktiv' => (bool) $a->is_active];
            $menschen[$schluessel]['employee_ids'][] = (int) $a->id;
        }

        return $menschen;
    }

    // ------------------------------------------------------------ 3.1 Konto-Antraege

    /**
     * Eine Zeile je offenem Antrag. ACHTUNG: employee_id ist null und akten
     * leer, wenn die Person keine Akte im Team hat (Zustand OHNE_AKTE, Name
     * "Person #id") — daraus nie einen Akten-Link bauen; nur stoppbar.
     *
     * @return list<array{schluessel:string, person_id:int, employee_id:?int, name:string, akten:list<array{id:int, firma:string, aktiv:bool}>, employee_ids:list<int>, alt:string, neu:string, beantragt_at:string, beantragt:string, wirksam:string, quelle:string, quelle_text:string, zustand:string, kann_freigeben:bool}>
     */
    public function antraege(int $teamId, string $suche = ''): array
    {
        $offene = KontoWriter::offeneNummernwechsel($teamId);
        if ($offene === []) {
            return [];
        }

        // Stillgelegte Zeilen zeigen Name und Akten der Zielzeile (dort haengen die Akten jetzt).
        $anker = [];
        foreach ($offene as $z) {
            $anker[(int) $z->id] = $z->merged_into_person_id !== null ? (int) $z->merged_into_person_id : (int) $z->id;
        }
        $menschen = self::menschen($this->aktenFuerPersonen($teamId, array_values(array_unique($anker))));
        $jetzt = now()->getTimestamp();
        // Alle Zeilen stammen aus DIESEM Team (offeneNummernwechsel($teamId)) — die eine
        // Regel notfallWegAn() deshalb einmal fragen statt je Zeile (kein N+1).
        $wegAn = KontoWriter::notfallWegAn((int) $offene[0]->id);

        $zeilen = [];
        foreach ($offene as $z) {
            // M2: ohne Akte im Team verschwindet der Antrag NICHT (KontoWriter: nie
            // "unsichtbar UND unstoppbar") — Zeile ohne Akten, nur stoppbar.
            $m = $menschen['p:' . $anker[(int) $z->id]] ?? self::aktenloserMensch((int) $z->id);
            $zustand = NummernwechselTafel::zustand($z, $wegAn);
            if ($m['employee_ids'] === [] && in_array($zustand, [NummernwechselTafel::ZUSTAND_OFFEN, NummernwechselTafel::ZUSTAND_NOTFALLWEG_AUS], true)) {
                $zustand = self::OHNE_AKTE;
            }
            $wirksam = Carbon::parse((string) $z->wechsel_wirksam_ab);
            $stunden = (int) ceil(($wirksam->getTimestamp() - $jetzt) / 3600);
            $notfall = $z->wechsel_quelle === KontoWriter::ZWECK_NOTFALL;

            $zeilen[] = array_merge($m, [
                // M1: Schluessel = die Antrags-Zeile selbst. Stillgelegte Zeile und Zielzeile
                // koennen beide einen Antrag haben — zwei Zeilen, nie derselbe Schluessel.
                'schluessel'     => 'p:' . (int) $z->id,
                'person_id'      => (int) $z->id,
                'alt'          => '…' . NummernwechselTafel::kurz((string) ($z->phone ?? '')),
                'neu'            => '…' . NummernwechselTafel::kurz((string) $z->wechsel_neue_nummer),
                'beantragt_at'   => (string) $z->wechsel_beantragt_at,
                'beantragt'      => $z->wechsel_beantragt_at !== null ? Carbon::parse((string) $z->wechsel_beantragt_at)->format('d.m., H:i') : '—',
                'wirksam'        => $wirksam->format('d.m., H:i') . ($stunden > 0 ? " (noch {$stunden} Std.)" : ' (fällig)'),
                'quelle'         => (string) $z->wechsel_quelle,
                'quelle_text'    => $notfall ? 'Nummer + Passwort verloren' : (string) $z->wechsel_quelle,
                'zustand'        => $zustand,
                'kann_freigeben' => $notfall && in_array($zustand, [NummernwechselTafel::ZUSTAND_OFFEN, NummernwechselTafel::ZUSTAND_NOTFALLWEG_AUS], true),
            ]);
        }

        return self::gefiltert($zeilen, $suche);
    }

    public function gestoppte(int $teamId): array
    {
        $gestoppt = DB::table('rec_hr_vermerke as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.user_id')
            ->where('v.team_id', $teamId)
            ->where('v.art', 'antrag_gestoppt')
            ->where('v.created_at', '>=', now()->subDays(30)->format('Y-m-d H:i:s'))
            ->orderByDesc('v.created_at')
            ->get(['v.rec_person_id', 'v.notiz', 'v.created_at', 'u.name as benutzer']);
        $sperren = DB::table('rec_persons')
            ->where('team_id', $teamId)
            ->where('notfall_gesperrt_bis', '>', now()->format('Y-m-d H:i:s'))
            ->orderBy('notfall_gesperrt_bis')
            ->get(['id', 'notfall_gesperrt_bis']);

        $ids = array_values(array_unique(array_merge(
            $gestoppt->pluck('rec_person_id')->filter()->map(fn ($v) => (int) $v)->all(),
            $sperren->pluck('id')->map(fn ($v) => (int) $v)->all(),
        )));
        // M2: spaeter stillgelegte Zeilen zeigen Name und Akten der Zielzeile;
        // ohne Akte im Team bleibt der Eintrag sichtbar ("Person #id").
        $anker = $ids === [] ? [] : DB::table('rec_persons')->whereIn('id', $ids)->where('team_id', $teamId)
            ->get(['id', 'merged_into_person_id'])
            ->mapWithKeys(fn ($p) => [(int) $p->id => $p->merged_into_person_id !== null ? (int) $p->merged_into_person_id : (int) $p->id])
            ->all();
        $menschen = self::menschen($this->aktenFuerPersonen($teamId, array_values(array_unique($anker))));
        $mensch = fn (int $id) => $menschen['p:' . ($anker[$id] ?? $id)] ?? self::aktenloserMensch($id);

        $aus = ['gestoppt' => [], 'notfallsperren' => []];
        foreach ($gestoppt as $v) {
            if ($v->rec_person_id === null) {
                continue;
            }
            $m = $mensch((int) $v->rec_person_id);
            $aus['gestoppt'][] = ['name' => $m['name'], 'akten' => $m['akten'], 'am' => Carbon::parse((string) $v->created_at)->format('d.m.Y, H:i'),
                'von' => (string) ($v->benutzer ?? '—'), 'notiz' => (string) $v->notiz];
        }
        foreach ($sperren as $p) {
            $m = $mensch((int) $p->id);
            $aus['notfallsperren'][] = ['person_id' => (int) $p->id, 'name' => $m['name'], 'akten' => $m['akten'],
                'bis' => Carbon::parse((string) $p->notfall_gesperrt_bis)->format('d.m.Y')];
        }

        return $aus;
    }

    // ------------------------------------------------------------ 3.2 Gesperrt

    public function gesperrt(int $teamId, string $suche = '', bool $mitBremse = true): array
    {
        $akten = $this->geklammert($teamId, DB::table('rec_employees')->where('team_id', $teamId)->where('is_active', 1)->orderBy('id')->get(self::AKTEN_SPALTEN));
        $menschen = self::menschen($akten);
        $gruende = $this->sperrGruende($teamId, $menschen, $akten, $mitBremse);

        $zeilen = [];
        foreach ($menschen as $schluessel => $m) {
            if (($gruende[$schluessel] ?? []) !== []) {
                $zeilen[] = $m + ['gruende' => $gruende[$schluessel]];
            }
        }

        return self::gefiltert($zeilen, $suche);
    }

    /**
     * @param  array<string, array>  $menschen
     * @param  iterable<object>  $akten  die Akten dieser Menschen (AKTEN_SPALTEN)
     * @return array<string, list<array>>
     */
    public function sperrGruende(int $teamId, array $menschen, iterable $akten, bool $mitBremse): array
    {
        $jetzt = now()->format('Y-m-d H:i:s');
        $personIds = array_values(array_filter(array_column($menschen, 'person_id')));

        $personen = $personIds === [] ? collect() : DB::table('rec_persons')
            ->whereIn('id', $personIds)->where('team_id', $teamId)
            ->get(['id', 'phone', 'locked_at', 'notfall_gesperrt_bis'])->keyBy('id');

        // GD-2: dieselbe Nummer an einer anderen lebenden Zeile (anderes Team) — ohne sie zu nennen.
        $mehrdeutig = $personIds === [] ? [] : DB::table('rec_persons as p')
            ->whereIn('p.id', $personIds)
            ->whereNotNull('p.phone')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('rec_persons as p2')
                ->whereColumn('p2.phone', 'p.phone')
                ->whereColumn('p2.id', '<>', 'p.id')
                ->whereNull('p2.merged_into_person_id'))
            ->pluck('p.id')->mapWithKeys(fn ($v) => [(int) $v => true])->all();

        $bremsen = $mitBremse
            ? $this->portalAuth()->gebremsteNummern($personen->pluck('phone')->filter()->map(fn ($n) => (string) $n)->values()->all())
            : [];

        $dispo = [];
        foreach ($akten as $a) {
            if ((bool) $a->is_active && $a->portal_locked_at !== null) {
                $dispo[self::schluessel($a)] ??= ['seit' => (string) $a->portal_locked_at, 'grund' => $a->portal_locked_reason, 'employee_id' => (int) $a->id];
            }
        }

        $aus = [];
        foreach ($menschen as $schluessel => $m) {
            $p = $m['person_id'] !== null ? $personen->get($m['person_id']) : null;
            $nummer = $p !== null && $p->phone !== null ? (string) $p->phone : null;
            $aus[$schluessel] = SperrGruende::fuer([
                'dispo'          => $dispo[$schluessel] ?? null,
                'konto_gesperrt' => $p !== null && $p->locked_at !== null,
                'bremse'         => $nummer !== null && array_key_exists($nummer, $bremsen) ? ['frei_ab' => $bremsen[$nummer]] : null,
                'notfall_bis'    => $p?->notfall_gesperrt_bis,
                'mehrdeutig'     => $p !== null && isset($mehrdeutig[(int) $p->id]),
            ], $jetzt);
        }

        return $aus;
    }

    // ------------------------------------------------------------ 3.3 Erinnert, aber keine Reaktion

    public function erinnert(int $teamId, string $suche = '', bool $ruhendeZeigen = false): array
    {
        $grenze = now()->subDays(HrEinstellungen::erinnertTage($teamId))->format('Y-m-d H:i:s');
        $heute = now()->toDateString();
        // Nur AKTIVE Akten des Teams (Ruling zu I3): Dokumente und Vertraege an einer
        // beendeten Anstellung sind keine Chips — Ausgeschiedene erscheinen nie unter "Erinnert".
        $teamAkten = fn ($q) => $q->select('id')->from('rec_employees')->where('team_id', $teamId)->where('is_active', 1);

        $aufgaben = DB::table('rec_persons')
            ->where('team_id', $teamId)
            ->whereNotNull('aufgaben_signatur')
            ->whereNotNull('aufgaben_gemeldet_at')
            ->where('aufgaben_gemeldet_at', '<=', $grenze)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('rec_employees')
                ->whereColumn('rec_employees.rec_person_id', 'rec_persons.id')->where('rec_employees.team_id', $teamId))
            ->orderBy('id')
            ->get(['id', 'aufgaben_gemeldet_at', 'aufgaben_nachricht_id']);

        $nachweise = DB::table('rec_employee_proofs as p')
            ->join('rec_employees as e', 'e.id', '=', 'p.rec_employee_id')
            ->where('e.team_id', $teamId)->where('e.is_active', 1)
            ->whereNull('p.superseded_at')
            ->whereNotNull('p.reminded_at')->where('p.reminded_at', '<=', $grenze)
            ->orderBy('p.id')
            ->get(['p.id', 'p.rec_employee_id', 'p.proof_type_code', 'p.valid_until', 'p.reminded_at'])
            ->filter(fn ($n) => self::nachweisNochFaellig($n, $heute))->values();

        $dokumente = RecDocumentRecipient::query()->with('document')
            ->whereNull('withdrawn_at')->whereNotNull('notified_at')->where('notified_at', '<=', $grenze)
            ->whereIn('rec_employee_id', $teamAkten)->orderBy('id')->get()
            ->filter(function (RecDocumentRecipient $z) {
                $aktion = (string) ($z->document?->action ?? '');

                return $z->document !== null && DokumentKategorie::brauchtHandlung($aktion) && !DokumentStatus::istErledigt($z->zeitstempel(), $aktion);
            })->values();

        $vertraege = RecContract::query()->with('contractTemplate')
            ->where('status', 'sent')->whereNotNull('sent_at')->where('sent_at', '<=', $grenze)
            ->whereIn('rec_employee_id', $teamAkten)->orderBy('id')->get()
            ->filter(fn (RecContract $v) => VertragsHerkunft::ausAkte($v))->values();

        $aktenIds = array_values(array_unique(array_merge(
            $nachweise->pluck('rec_employee_id')->map(fn ($v) => (int) $v)->all(),
            $dokumente->pluck('rec_employee_id')->map(fn ($v) => (int) $v)->all(),
            $vertraege->pluck('rec_employee_id')->map(fn ($v) => (int) $v)->all(),
        )));
        $personIds = $aufgaben->pluck('id')->map(fn ($v) => (int) $v)->all();
        $akten = $this->aktenMitSchwestern($teamId, $aktenIds, $personIds);
        $menschen = self::menschen($akten);
        $aktenById = $akten->keyBy('id');
        $schluesselJeAkte = $akten->mapWithKeys(fn ($a) => [(int) $a->id => self::schluessel($a)])->all();

        // I2: Ruhe und naechster Einsatz VOR der teuren Live-Pruefung — OffenePunkte
        // laeuft nur fuer Zeilen, die auch angezeigt werden.
        $naechster = $this->naechsteEinsaetze($menschen, $heute);
        $ruhe = $this->ruheBis($teamId);
        $ruht = [];
        foreach ($ruhe as $schluessel => $ruheBis) {
            $einsatz = $naechster[$schluessel] ?? null;
            // Review Focus 5: eine Ruhe, die ueber den naechsten Einsatz reicht, versteckt nichts.
            $ruht[$schluessel] = $einsatz === null || $einsatz > substr($ruheBis, 0, 10);
        }

        $gescheitert = $this->gescheiterteNachrichten(
            $aufgaben->pluck('aufgaben_nachricht_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all(),
        );

        $chips = [];
        foreach ($aufgaben as $p) {
            $schluessel = 'p:' . (int) $p->id;
            if (($ruht[$schluessel] ?? false) && !$ruhendeZeigen) {
                continue;
            }
            $rot = $p->aufgaben_nachricht_id !== null && isset($gescheitert[(int) $p->aufgaben_nachricht_id]);
            if (isset($menschen[$schluessel]) && ($chip = $this->aufgabenChip($p, $menschen[$schluessel], $aktenById, $rot)) !== null) {
                $chips[$schluessel][] = $chip;
            }
        }
        foreach ($nachweise as $n) {
            $schluessel = $schluesselJeAkte[(int) $n->rec_employee_id] ?? null;
            if ($schluessel !== null) {
                $chips[$schluessel][] = $this->chip('nachweis', 'nachweis:' . (int) $n->id, 'Nachweis: ' . ProofTypes::label((string) $n->proof_type_code),
                    (string) $n->reminded_at, $aktenById[(int) $n->rec_employee_id]->portal_v2_since !== null, (string) $n->reminded_at);
            }
        }
        foreach ($dokumente as $z) {
            $schluessel = $schluesselJeAkte[(int) $z->rec_employee_id] ?? null;
            if ($schluessel !== null) {
                $am = $z->notified_at->format('Y-m-d H:i:s');
                $chips[$schluessel][] = $this->chip('dokument', 'dokument:' . (int) $z->id, 'Dokument: ' . (string) $z->document->title,
                    $am, $aktenById[(int) $z->rec_employee_id]->portal_v2_since !== null, $am);
            }
        }
        // Ruling R3: die Pausen-Regel fuer Vertraege steht NUR in VertragErinnerungsPause
        // (derselbe Leser wie der Versand in HrKontoAktionen) — keine zweite Fassung hier.
        $letzteVertragsErinnerung = VertragErinnerungsPause::letzteErinnerungen(
            $teamId, $vertraege->map(fn (RecContract $v) => (int) $v->id)->all(),
        );
        foreach ($vertraege as $v) {
            $schluessel = $schluesselJeAkte[(int) $v->rec_employee_id] ?? null;
            if ($schluessel !== null) {
                $am = $v->sent_at->format('Y-m-d H:i:s');
                $pause = (string) VertragErinnerungsPause::stempel($v->sent_at, $letzteVertragsErinnerung[(int) $v->id] ?? null);
                $chips[$schluessel][] = $this->chip('vertrag', VertragErinnerungsPause::bezug((int) $v->id),
                    'Vertrag: ' . VertragLeser::anzeigename($v->contractTemplate?->code, $v->contractTemplate?->name),
                    $am, $aktenById[(int) $v->rec_employee_id]->portal_v2_since !== null, $pause);
            }
        }

        $zeilen = [];
        foreach ($chips as $schluessel => $liste) {
            $m = $menschen[$schluessel];
            $einsatz = $naechster[$schluessel] ?? null;
            $ruheBis = $ruhe[$schluessel] ?? null;
            $ruhtHier = $ruht[$schluessel] ?? false;
            if ($ruhtHier && !$ruhendeZeigen) {
                continue;
            }
            $zeilen[] = array_merge($m, [
                'chips'             => $liste,
                'naechster_einsatz' => $einsatz,
                'naechster_text'    => $einsatz !== null ? Carbon::parse($einsatz)->format('d.m.Y') : 'kein Einsatz',
                'aeltest'           => min(array_column($liste, 'erinnert_at')),
                'ruhe_bis'          => $ruheBis,
                'ruht'              => $ruhtHier,
                'ruhe_hinweis'      => $ruheBis !== null && !$ruhtHier
                    ? sprintf('kontaktiert — ruht nicht, weil der nächste Einsatz (%s) vor dem Ruhe-Ende liegt', Carbon::parse((string) $einsatz)->format('d.m.Y'))
                    : null,
            ]);
        }

        usort($zeilen, fn (array $a, array $b) => [$a['naechster_einsatz'] ?? '9999-12-31', $a['aeltest']] <=> [$b['naechster_einsatz'] ?? '9999-12-31', $b['aeltest']]);

        return self::gefiltert($zeilen, $suche);
    }

    // ------------------------------------------------------------ 3.4 Pilot-Ueberblick

    public function pilot(int $teamId, string $suche = '', string $filter = 'alle'): array
    {
        $pilotAkten = $this->geklammert($teamId, DB::table('rec_employees')->where('team_id', $teamId)->whereNotNull('portal_v2_since')->orderBy('id')->get(self::AKTEN_SPALTEN));
        $pilotIds = $pilotAkten->pluck('id')->map(fn ($v) => (int) $v)->all();
        $personIds = $pilotAkten->pluck('rec_person_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $akten = $this->aktenMitSchwestern($teamId, $pilotIds, $personIds);
        $menschen = self::menschen($akten);
        // I1: einmal indizieren statt whereIn je Mensch (war O(n²), 0,4 s bei 1500 Akten).
        $aktenById = $akten->keyBy('id');
        $personen = $personIds === [] ? collect() : DB::table('rec_persons')->whereIn('id', $personIds)->where('team_id', $teamId)
            ->get(['id', 'password_hash', 'registered_at', 'invited_at', 'invite_expires_at', 'invite_used_at', 'letzte_anmeldung_at'])->keyBy('id');
        $jetzt = now()->format('Y-m-d H:i:s');
        $leer = (object) ['password_hash' => null, 'registered_at' => null, 'invited_at' => null, 'invite_expires_at' => null, 'invite_used_at' => null, 'letzte_anmeldung_at' => null];

        $zeilen = [];
        $zahlen = array_fill_keys(array_keys(KontoStatus::FILTER), 0);
        foreach ($menschen as $schluessel => $m) {
            if ($m['person_id'] !== null && !$personen->has($m['person_id'])) {
                continue;
            }
            $p = $m['person_id'] !== null ? $personen->get($m['person_id']) : $leer;
            $eigene = collect($m['employee_ids'])->map(fn (int $id) => $aktenById[$id]);
            $pilotSeit = $eigene->pluck('portal_v2_since')->filter()->min();
            $altesPortal = $eigene->flatMap(fn ($a) => [$a->portal_verified_at, $a->portal_last_seen_at])->filter()->max();
            $treffer = KontoStatus::filter($p, $jetzt);
            foreach ($treffer as $f) {
                $zahlen[$f]++;
            }
            $zeilen[] = array_merge($m, [
                'status_code'      => KontoStatus::code($p, $jetzt),
                'status_text'      => KontoStatus::text($p, $jetzt),
                'filter'           => $treffer,
                'pilot_seit'       => $pilotSeit !== null ? Carbon::parse((string) $pilotSeit)->format('d.m.Y') : '—',
                'registriert'      => $p->registered_at !== null ? Carbon::parse((string) $p->registered_at)->format('d.m.Y') : '—',
                'letzte_anmeldung' => $p->letzte_anmeldung_at !== null ? Carbon::parse((string) $p->letzte_anmeldung_at)->format('d.m.Y, H:i') : '—',
                'altes_portal'     => $altesPortal !== null ? Carbon::parse((string) $altesPortal)->format('d.m.Y, H:i') : '—',
                'ohne_person'      => $m['person_id'] === null,
                'link'             => route('recruiting.employees.show', ['employee' => $m['employee_id']]) . '?tab=stammdaten#konto',
            ]);
        }

        $zeilen = array_values(array_filter($zeilen, fn (array $z) => in_array($filter, $z['filter'], true)));

        return ['zeilen' => self::gefiltert($zeilen, $suche), 'zahlen' => $zahlen];
    }

    // ------------------------------------------------------------ Abzeichen

    public function abzeichen(int $teamId): int
    {
        $gesperrt = 0;
        foreach ($this->gesperrt($teamId, '', false) as $zeile) {
            foreach ($zeile['gruende'] as $grund) {
                if (SperrGruende::zaehltFuersAbzeichen($grund['code'])) {
                    $gesperrt++;
                    break;
                }
            }
        }

        return count($this->antraege($teamId)) + $gesperrt;
    }

    // ------------------------------------------------------------ Konto-Kasten (Akte)

    /** Daten des Konto-Kastens einer Akte (Spec HR-Bereich §4) — null, wenn die Akte nicht im Team ist. */
    public function kasten(int $teamId, int $employeeId): ?array
    {
        $akte = DB::table('rec_employees')->where('team_id', $teamId)->where('id', $employeeId)->first(self::AKTEN_SPALTEN);
        if ($akte === null) {
            return null;
        }
        $jetzt = now()->format('Y-m-d H:i:s');
        $person = $akte->rec_person_id !== null
            ? DB::table('rec_persons')->where('id', (int) $akte->rec_person_id)->where('team_id', $teamId)->first()
            : null;
        $akten = $person !== null
            ? DB::table('rec_employees')->where('team_id', $teamId)->where('rec_person_id', (int) $person->id)->orderBy('id')->get(self::AKTEN_SPALTEN)
            : collect([$akte]);
        $menschen = self::menschen($akten);
        $schluessel = $person !== null ? 'p:' . (int) $person->id : 'e:' . (int) $akte->id;
        $gruende = $this->sperrGruende($teamId, $menschen, $akten, true)[$schluessel] ?? [];

        $pilotSeit = $akten->pluck('portal_v2_since')->filter()->min();
        $altesPortal = $akten->flatMap(fn ($a) => [$a->portal_verified_at, $a->portal_last_seen_at])->filter()->max();
        $verlauf = RecHrVermerk::letzte($teamId, $person !== null ? (int) $person->id : null, $akten->pluck('id')->map(fn ($v) => (int) $v)->all(), 10)
            ->map(fn ($v) => [
                'wann'     => Carbon::parse((string) $v->created_at)->format('d.m.Y, H:i'),
                'was'      => RecHrVermerk::ART_LABELS[$v->art] ?? (string) $v->art,
                'wer'      => (string) ($v->benutzer ?? '—'),
                'notiz'    => $v->notiz,
                'ergebnis' => $v->ergebnis,
            ])->all();

        $stillgelegt = $person !== null && $person->merged_into_person_id !== null ? (int) $person->merged_into_person_id : null;
        $gesperrt = $person !== null && $person->locked_at !== null;
        $registriert = $person !== null && $person->password_hash !== null;
        $knoepfeAus = $person === null || $stillgelegt !== null;
        // Pilot-Sperre (Sebastian 19.10.2026): ohne neues Portal keine
        // Einladung — der Kasten zeigt den Grund statt des Knopfs;
        // HrKontoAktionen prueft beim Klick erneut. Zuruecksetzen bleibt.
        $ohneNeuesPortal = $person !== null && !PilotKreis::personHatNeuesPortal((int) $person->id);
        $einladungMoeglich = !$knoepfeAus && !$gesperrt;

        return [
            'ohne_person'        => $person === null,
            'stillgelegt_ziel'   => $stillgelegt,
            'person_id'          => $person !== null ? (int) $person->id : null,
            'status_code'        => $person !== null ? KontoStatus::code($person, $jetzt) : null,
            'status_text'        => $person !== null ? KontoStatus::text($person, $jetzt) : 'Kein Konto in diesem Team',
            'nummer'             => $person !== null && $person->phone !== null ? '…' . NummernwechselTafel::kurz((string) $person->phone) : '—',
            'letzte_anmeldung'   => $person !== null && $person->letzte_anmeldung_at !== null ? Carbon::parse((string) $person->letzte_anmeldung_at)->format('d.m.Y, H:i') : '—',
            'altes_portal'       => $altesPortal !== null ? Carbon::parse((string) $altesPortal)->format('d.m.Y, H:i') : '—',
            'pilot'              => $pilotSeit !== null ? 'im neuen Portal seit ' . Carbon::parse((string) $pilotSeit)->format('d.m.Y') : 'noch altes Portal',
            'gruende'            => $gruende,
            'antrag_offen'       => $person !== null && $person->wechsel_wirksam_ab !== null,
            'verlauf'            => $verlauf,
            'kann_einladen'      => $einladungMoeglich && !$ohneNeuesPortal && !$registriert,
            // Erste Einladung heisst "Einladen" und braucht keine Notiz (22.10.2026).
            'schon_eingeladen'   => $person !== null && $person->invited_at !== null,
            // Akte und Login-Nummer laufen auseinander (22.10.2026, LoginNummer).
            'nummer_hinweis'     => self::nummerHinweis($akte, $person),
            // Zuruecksetzen bleibt auch ohne neues Portal (Controller-Ruling
            // 19.10.2026) — dann ohne Einladung, der Hinweis sagt es.
            'kann_zuruecksetzen' => $einladungMoeglich && $registriert,
            'einladung_riegel'   => $einladungMoeglich && $ohneNeuesPortal
                ? ($registriert ? HrKontoAktionen::ZURUECKSETZEN_OHNE_EINLADUNG_HINWEIS : HrKontoAktionen::KEIN_NEUES_PORTAL_TEXT)
                : null,
            'knoepfe_aus'        => $knoepfeAus,
        ];
    }

    // ------------------------------------------------------------ intern

    /**
     * Hinweis, wenn die Handynummer der geoeffneten Akte nicht die
     * Login-Nummer der Person ist. Ohne Konto zieht LoginNummer sie bei jeder
     * Aktenaenderung nach — bleibt sie trotzdem abweichend, gehoert die
     * Nummer einer anderen Person. Mit Konto aendert der Mensch sie selbst.
     */
    private static function nummerHinweis(object $akte, ?object $person): ?string
    {
        $aktenNummer = PhoneE164::normalize($akte->phone ?? null);
        if ($person === null || $aktenNummer === null || (string) $person->phone === $aktenNummer) {
            return null;
        }
        $kurzAkte = '…' . NummernwechselTafel::kurz($aktenNummer);
        $kurzLogin = $person->phone !== null ? '…' . NummernwechselTafel::kurz((string) $person->phone) : 'keine';
        if ($person->password_hash !== null) {
            return "Die Akte hat die Nummer {$kurzAkte}, das Konto meldet sich mit {$kurzLogin} an. "
                . 'Der Mitarbeiter ändert seine Nummer im Portal, oder HR setzt das Konto zurück.';
        }
        if (LoginNummer::nummerVergeben($person->team_id !== null ? (int) $person->team_id : null, $aktenNummer, (int) $person->id)) {
            return "Die Nummer aus der Akte ({$kurzAkte}) gehört schon einer anderen Person — bitte klären, bevor eingeladen wird.";
        }

        return "Login-Nummer {$kurzLogin} weicht von der Akte ({$kurzAkte}) ab — sie wird beim nächsten Speichern der Akte übernommen. Eine offene Einladung wird dabei zurückgezogen, danach neu einladen.";
    }

    private static function schluessel(object $akte): string
    {
        return $akte->rec_person_id !== null ? 'p:' . (int) $akte->rec_person_id : 'e:' . (int) $akte->id;
    }

    /** @param list<int> $personIds */
    private function aktenFuerPersonen(int $teamId, array $personIds): Collection
    {
        return $personIds === [] ? collect() : $this->geklammert($teamId, DB::table('rec_employees')
            ->where('team_id', $teamId)->whereIn('rec_person_id', $personIds)->orderBy('id')->get(self::AKTEN_SPALTEN));
    }

    /**
     * M3: rec_person_id bleibt nur stehen, wenn die Person im Team liegt und
     * nicht stillgelegt ist (Regel von HrKontoAktionen::personDerAkteImTeam —
     * die Akte selbst liegt hier immer im Team). Sonst wird die Akte als Akte
     * ohne Person gefuehrt. EINE Abfrage fuer die ganze Menge; Kopien statt
     * Veraenderung der geladenen Zeilen.
     */
    private function geklammert(int $teamId, Collection $akten): Collection
    {
        $ids = $akten->pluck('rec_person_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        if ($ids === []) {
            return $akten;
        }
        $lebend = DB::table('rec_persons')->whereIn('id', $ids)->where('team_id', $teamId)->whereNull('merged_into_person_id')
            ->pluck('id')->mapWithKeys(fn ($v) => [(int) $v => true])->all();

        return $akten->map(function (object $a) use ($lebend) {
            if ($a->rec_person_id !== null && !isset($lebend[(int) $a->rec_person_id])) {
                $a = clone $a;
                $a->rec_person_id = null;
            }

            return $a;
        })->values();
    }

    /**
     * Die genannten Akten plus alle Schwester-Akten derselben (lebenden,
     * teameigenen) Personen im Team. Akten, die nur ueber eine fremde oder
     * stillgelegte Person mitkamen, fallen wieder heraus.
     */
    private function aktenMitSchwestern(int $teamId, array $aktenIds, array $personIds): Collection
    {
        if ($aktenIds !== []) {
            $personIds = array_values(array_unique(array_merge($personIds, DB::table('rec_employees')
                ->whereIn('id', $aktenIds)->whereNotNull('rec_person_id')->pluck('rec_person_id')->map(fn ($v) => (int) $v)->all())));
        }
        if ($aktenIds === [] && $personIds === []) {
            return collect();
        }

        $akten = $this->geklammert($teamId, DB::table('rec_employees')
            ->where('team_id', $teamId)
            ->where(fn ($q) => $q->whereIn('id', $aktenIds === [] ? [0] : $aktenIds)->orWhereIn('rec_person_id', $personIds === [] ? [0] : $personIds))
            ->orderBy('id')
            ->get(self::AKTEN_SPALTEN));
        $genannt = array_fill_keys($aktenIds, true);
        $personen = array_fill_keys($personIds, true);

        return $akten->filter(fn (object $a) => isset($genannt[(int) $a->id])
            || ($a->rec_person_id !== null && isset($personen[(int) $a->rec_person_id])))->values();
    }

    /** Zeile fuer eine Person ohne Akte im Team (M2): sichtbar statt verschluckt. */
    private static function aktenloserMensch(int $personId): array
    {
        return [
            'schluessel'   => 'p:' . $personId,
            'person_id'    => $personId,
            'employee_id'  => null,
            'name'         => 'Person #' . $personId,
            'akten'        => [],
            'employee_ids' => [],
        ];
    }

    /** M4: die Faelligkeits-Regel steht in ProofChecklist::laeuftBaldAb — hier nur die Vorbedingungen. */
    private static function nachweisNochFaellig(object $n, string $heute): bool
    {
        $code = (string) $n->proof_type_code;
        $bis = substr((string) $n->valid_until, 0, 10);
        if ($bis === '' || !ProofTypes::exists($code) || !ProofTypes::hasExpiry($code)) {
            return false;
        }

        return ProofChecklist::laeuftBaldAb($code, $bis, $heute);
    }

    private function aufgabenChip(object $p, array $mensch, Collection $aktenById, bool $rot): ?array
    {
        $aktiv = array_values(array_filter($mensch['employee_ids'], fn (int $id) => (bool) $aktenById[$id]->is_active));
        // I3: ohne aktive Anstellung keine Live-Pruefung an einer beendeten Akte —
        // Ausgeschiedene erscheinen nicht unter "Erinnert" (wie Gesperrt/Nachweis).
        if ($aktiv === []) {
            return null;
        }
        $bote = Bote::fuer($aktiv);
        $leser = $bote ?? RecEmployee::find($aktiv[0]);
        $punkte = $leser !== null ? ($this->offenePunkte ?? app(OffenePunkte::class))->fuer($leser)['punkte'] : [];
        if ($punkte === []) {
            return null;
        }
        return $this->chip('aufgaben', HrKontoAktionen::BEZUG_AUFGABEN, 'Aufgaben', (string) $p->aufgaben_gemeldet_at, $bote !== null,
            (string) $p->aufgaben_gemeldet_at, implode(', ', array_column($punkte, 'label')), $rot);
    }

    private function chip(string $quelle, string $bezug, string $titel, string $erinnertAt, bool $imNeuenPortal, string $pauseStempel, string $tooltip = '', bool $rot = false): array
    {
        $pause = HrKontoAktionen::pauseBis($pauseStempel);
        $inPause = $pause !== null && now()->lt($pause);
        $alter = (int) floor((now()->getTimestamp() - (int) strtotime($erinnertAt)) / 86400);

        return [
            'quelle'        => $quelle,
            'bezug'         => $bezug,
            'titel'         => $titel,
            'text'          => sprintf('%s · erinnert vor %d Tagen', $titel, $alter) . ($rot ? ' · nicht zugestellt' : ''),
            'erinnert_at'   => $erinnertAt,
            'alter_tage'    => $alter,
            'pause_bis'     => $pause?->format('Y-m-d'),
            'kann_erinnern' => $imNeuenPortal && !$inPause,
            'grau_text'     => !$imNeuenPortal ? 'nicht im neuen Portal' : ($inPause ? 'erneut erinnern ab ' . $pause->format('d.m.') : null),
            'rot'           => $rot,
            'tooltip'       => $tooltip,
        ];
    }

    /** @return array<string, string> Mensch-Schluessel => naechster Auftrag (Y-m-d), EINE Abfrage. */
    private function naechsteEinsaetze(array $menschen, string $heute): array
    {
        $jeAkte = [];
        foreach ($menschen as $schluessel => $m) {
            foreach ($m['employee_ids'] as $id) {
                $jeAkte[$id] = $schluessel;
            }
        }
        if ($jeAkte === []) {
            return [];
        }
        $einsaetze = [];
        foreach (DB::table('rec_dispo_assignments')->whereIn('rec_employee_id', array_keys($jeAkte))
            ->whereNull('missing_since')->where('datum', '>=', $heute)
            ->get(['rec_employee_id', 'datum', 'status_id']) as $e) {
            $einsaetze[$jeAkte[(int) $e->rec_employee_id]][] = ['datum' => substr((string) $e->datum, 0, 10), 'status_id' => (int) $e->status_id];
        }
        $aus = [];
        foreach ($einsaetze as $schluessel => $liste) {
            $n = EinsatzBezug::naechster($liste, $heute);
            if ($n !== null) {
                $aus[$schluessel] = (string) $n['datum'];
            }
        }

        return $aus;
    }

    /** @return array<string, string> Mensch-Schluessel => spaetestes laufendes ruhe_bis ('Y-m-d H:i:s'). */
    private function ruheBis(int $teamId): array
    {
        $aus = [];
        foreach (DB::table('rec_hr_vermerke')->where('team_id', $teamId)->where('art', 'kontaktiert')
            ->where('ruhe_bis', '>', now()->format('Y-m-d H:i:s'))
            ->get(['rec_person_id', 'rec_employee_id', 'ruhe_bis']) as $v) {
            $schluessel = $v->rec_person_id !== null ? 'p:' . (int) $v->rec_person_id : 'e:' . (int) $v->rec_employee_id;
            $aus[$schluessel] = max($aus[$schluessel] ?? '', (string) $v->ruhe_bis);
        }

        return $aus;
    }

    /**
     * Welche dieser Aufgaben-Nachrichten sind gescheitert? EINE Abfrage fuer
     * die ganze Liste (kein N+1); die Test-Attrappe wird je ID gefragt.
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function gescheiterteNachrichten(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        if ($this->nachrichtGescheitert !== null) {
            $aus = [];
            foreach ($ids as $id) {
                if ((bool) ($this->nachrichtGescheitert)($id)) {
                    $aus[$id] = true;
                }
            }

            return $aus;
        }

        return CommsWhatsAppMessage::query()->whereKey($ids)->where('status', 'failed')->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    // ---- 3.5 Lohn --------------------------------------------------------

    /**
     * Eine Zeile je Person, deren aktive MA-Anstellung keinen Zuschlag
     * bekommen kann, weil der RG-Zuschlag unbekannt ist (Spec Lohn MA §10,
     * Fall 2). Nur dieser Fall — angleichbar, manuell und "nur MA" sind
     * erledigt und stehen in der Akte. Dazu (Ruling R4) MA-Anstellungen mit
     * fehlender oder unklarer Personen-Zuordnung; rg_employee_id ist dann die
     * RG-Zeile mit gleicher Kennung bzw. null (keine RG-Zeile bekannt).
     * Seit 22.10.2026 (Markus, Variante A) zusaetzlich die Prueffaelle
     * (LohnMa::$pruefen): Wert geht raus, aber die Regel gilt nicht exakt.
     *
     * @return list<array{schluessel:string, person_id:?int, employee_id:?int, name:string, akten:list<array{id:int, firma:string, aktiv:bool}>, employee_ids:list<int>, rg_employee_id:?int, ma_employee_id:int, zuordnung_unklar:bool, begruendung:string, pruefen:?string}>
     */
    public function lohn(int $teamId, string $suche = ''): array
    {
        $e = $this->lohnErmittlung ?? new LohnMaErmittlung();
        $heute = now()->toDateString();
        $treffer = [];
        $maAkten = RecEmployee::query()->where('team_id', $teamId)->where('is_active', 1)
            ->where('is_test', false) // Test-MA nie in die Lohnliste
            ->whereRaw('UPPER(TRIM(company)) = ?', ['MA'])->orderBy('id')->get();
        foreach ($maAkten as $ma) {
            $rg = $e->rgSchwester($ma);
            $l = $e->ermittelnMitSchwester($ma, $rg, $heute);
            // Markus 22.10.2026: neben "RG-Zuschlag unbekannt" auch die Akten,
            // bei denen Variante A nicht exakt gilt (RG + 1 unter EG 1, oder
            // MA 1-5 / RG 6) — der Wert geht raus, HR prueft.
            if ($l->fall !== LohnMa::UNBEKANNT && $l->pruefen === null) {
                continue;
            }
            // Ohne RG-Id (Ruling R4 Fall B: keine Personen-Zuordnung) zeigt die
            // Liste die Zeile mit Hinweis, aber ohne Eingabefeld — es gibt
            // keine RG-Anstellung, an der ein Zuschlag stehen koennte.
            // Fall A (gleiche Kennung, andere Person): RG-Id als Anker, aber nicht
            // bearbeitbar — ein Zuschlag dort loeste den Fall nicht (Ruling zu R4).
            $treffer[(int) $ma->id] = [
                'rg' => $rg !== null ? (int) $rg->id : null,
                'unklar' => $rg !== null && ($rg->zuordnung_unklar ?? false),
                'begruendung' => $l->begruendung,
                'pruefen' => $l->pruefen,
            ];
        }
        if ($treffer === []) {
            return [];
        }
        $akten = $this->aktenMitSchwestern($teamId, array_keys($treffer), []);
        $zeilen = [];
        foreach (self::menschen($akten) as $m) {
            $maId = null;
            foreach ($m['employee_ids'] as $id) {
                if (isset($treffer[$id])) {
                    $maId = $id;
                    break;
                }
            }
            if ($maId === null || !self::passtSuche($m['name'], $suche)) {
                continue;
            }
            $zeilen[] = $m + ['rg_employee_id' => $treffer[$maId]['rg'], 'ma_employee_id' => $maId, 'zuordnung_unklar' => $treffer[$maId]['unklar'], 'begruendung' => $treffer[$maId]['begruendung'], 'pruefen' => $treffer[$maId]['pruefen']];
        }

        return $zeilen;
    }

    private static function passtSuche(string $name, string $suche): bool
    {
        $suche = mb_strtolower(trim($suche));

        return $suche === '' || str_contains(mb_strtolower($name), $suche);
    }

    private static function gefiltert(array $zeilen, string $suche): array
    {
        $suche = mb_strtolower(trim($suche));
        if ($suche === '') {
            return array_values($zeilen);
        }

        return array_values(array_filter($zeilen, fn (array $z) => str_contains(mb_strtolower($z['name']), $suche)));
    }

    private function portalAuth(): PortalAuth
    {
        return $this->auth ?? new PortalAuth();
    }
}
