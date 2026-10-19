<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\EntgeltgruppeEins;
use Platform\Recruiting\Support\LohnMa;
use Platform\Recruiting\Support\ZuschlagWert;

/**
 * Findet zu einer MA-Anstellung die RG-Schwester und die Quelle ihres
 * Zuschlags, und rechnet daraus den MA-Lohn (Spec Lohn MA 2026-10-19 §6).
 *
 * Lese-Service, Query Builder. Die RG-Schwester ist die AKTIVE RG-Anstellung
 * derselben Person (rec_person_id, sonst person_key ueber PersonScopeResolver);
 * eine ausgeschiedene RG-Anstellung zaehlt nicht — wer bei RG weg ist, ist
 * "nur MA". Bei mehreren die juengste.
 */
final class LohnMaErmittlung
{
    public function __construct(private readonly ?PersonScopeResolver $scope = null)
    {
    }

    public function ermitteln(RecEmployee $ma, ?string $datum = null): LohnMa
    {
        return $this->ermittelnMitSchwester($ma, $this->rgSchwester($ma), $datum);
    }

    /**
     * Wie ermitteln(), aber mit bereits gefundener RG-Schwester — Listen
     * (HR-Lohn-Reiter) sparen sich so die zweite Suche je Zeile.
     */
    public function ermittelnMitSchwester(RecEmployee $ma, ?object $rg, ?string $datum = null): LohnMa
    {
        $tag = $datum ?? now()->toDateString();

        // Ruling R4 (Final-Review F2): "nur MA" (0,00) nur, wenn wir die Person
        // wirklich kennen und sie bei RG nicht aktiv ist. Fehlt die Zuordnung
        // oder zeigt die Kennung auf eine RG-Anstellung einer anderen Person,
        // waere 0,00 ein Wert aus fehlenden Daten — dann bleibt der Zuschlag
        // leer und die Person landet bei HR.
        if ($rg !== null && ($rg->zuordnung_unklar ?? false)) {
            return LohnMa::unbekanntWeil(EntgeltgruppeEins::satz($tag), self::GRUND_GLEICHE_KENNUNG);
        }
        if ($rg === null && $ma->rec_person_id === null && trim((string) $ma->person_key) === '') {
            return LohnMa::unbekanntWeil(EntgeltgruppeEins::satz($tag), self::GRUND_OHNE_PERSON);
        }

        $quelle = $rg === null ? ['wert' => null, 'quelle' => null] : $this->rgZuschlag($rg);

        return LohnMa::rechne(
            EntgeltgruppeEins::satz($tag),
            self::mindestlohn((int) $ma->team_id),
            $rg !== null,
            $quelle['wert'],
            $quelle['quelle'],
            $ma->tax_class !== null ? (string) $ma->tax_class : null,
            ($rg->tax_class ?? null) !== null ? (string) $rg->tax_class : null,
        );
    }

    public const GRUND_GLEICHE_KENNUNG = 'RG-Zuordnung unklar (gleiche Kennung, andere Person)';
    public const GRUND_OHNE_PERSON     = 'keine Personen-Zuordnung';

    /**
     * RG-Schwester derselben Person; findet die Personen-Suche keine, aber
     * eine aktive RG-Anstellung im selben Team traegt denselben person_key
     * (andere oder keine Person — Audit-Gruppen, neue RG-Anstellung aus einer
     * Bewerbung mit eigener Person), kommt DIESE Zeile mit
     * zuordnung_unklar = true zurueck (Ruling R4, Fall A): Ihr Zuschlag wird
     * nicht verwendet, die HR-Liste braucht aber ihre Id.
     *
     * @return object|null Zeile mit id, rec_applicant_id, zuschlag_manuell, zas_grundlohn, zas_zuschlag, tax_class[, zuordnung_unklar]
     */
    public function rgSchwester(RecEmployee $ma): ?object
    {
        $rg = $this->rgSchwesterDerPerson($ma);
        if ($rg !== null) {
            return $rg;
        }
        $key = trim((string) $ma->person_key);
        if ($key === '') {
            return null;
        }
        $fremd = $this->rgAbfrage()
            ->where('e.id', '!=', (int) $ma->id)
            ->where('e.team_id', (int) $ma->team_id)
            ->where('e.person_key', $key)
            ->orderByDesc('e.id')
            ->first();
        if ($fremd !== null) {
            $fremd->zuordnung_unklar = true;
        }

        return $fremd;
    }

    /**
     * Ist diese RG-Zeile fuer eine aktive MA-Anstellung nur der Fall-A-Anker
     * (gleiche Kennung, keine bestaetigte Person)? Dann darf HR dort keinen
     * Zuschlag aus der Lohn-Liste eintragen: er loeste den Fall nicht und
     * landete evtl. an der RG-Zeile einer anderen Person (Ruling zu R4).
     */
    public function istUnklarerAnker(RecEmployee $rg): bool
    {
        $key = trim((string) $rg->person_key);
        if ($key === '') {
            return false;
        }
        $mas = RecEmployee::query()->where('team_id', (int) $rg->team_id)->where('is_active', 1)
            ->whereRaw('UPPER(TRIM(company)) = ?', ['MA'])->where('person_key', $key)->get();
        foreach ($mas as $ma) {
            $s = $this->rgSchwester($ma);
            if ($s !== null && (int) $s->id === (int) $rg->id && ($s->zuordnung_unklar ?? false)) {
                return true;
            }
        }

        return false;
    }

    private function rgSchwesterDerPerson(RecEmployee $ma): ?object
    {
        $q = $this->rgAbfrage()
            ->where('e.id', '!=', (int) $ma->id);

        if ($ma->rec_person_id !== null) {
            $q->where('e.rec_person_id', (int) $ma->rec_person_id);
        } else {
            $ids = array_values(array_diff(($this->scope ?? new PersonScopeResolver())->forEmployee($ma)['ids'], [(int) $ma->id]));
            if ($ids === []) {
                return null;
            }
            $q->whereIn('e.id', $ids);
        }

        return $q->orderByDesc('e.id')->first();
    }

    /** Dieselbe Zeile fuer eine RG-Anstellung selbst (RG-Export ohne Bewerbung). */
    public function rgZeile(int $employeeId): ?object
    {
        return $this->rgAbfrage()->where('e.id', $employeeId)->first();
    }

    /** Aktive RG-Anstellungen mit ihren ZAS-Lohnwerten (Left Join, hr_data kann fehlen). */
    private function rgAbfrage(): \Illuminate\Database\Query\Builder
    {
        return DB::table('rec_employees as e')
            ->leftJoin('rec_employee_hr_data as h', 'h.rec_employee_id', '=', 'e.id')
            ->where('e.is_active', 1)
            ->where(fn ($w) => $w->whereNull('e.company')->orWhere('e.company', '')->orWhereRaw('UPPER(TRIM(e.company)) = ?', ['RG']))
            ->select(['e.id', 'e.rec_applicant_id', 'e.zuschlag_manuell', 'h.zas_grundlohn', 'h.zas_zuschlag', 'e.tax_class']);
    }

    /**
     * Zuschlag der RG-Anstellung in dieser Reihenfolge: Bewerbung (Datenfeld,
     * sonst AV-Code), sonst ZAS (zas_zuschlag, aber nur bei zas_grundlohn > 0:
     * Grundlohn 0 heisst bei ZAS "Zeile nicht gepflegt", der Zuschlag daneben
     * ist keine Angabe — Befund 19.10.2026, 125 solcher Zeilen), sonst
     * zuschlag_manuell, sonst unbekannt.
     *
     * Ruling R3' (ersetzt R3, Final-Review F5): Eine Bewerbung OHNE Zuschlag
     * und ohne lesbaren AV-Code ist keine Angabe — die Kette laeuft weiter
     * zu ZAS und manuell. Erst wenn auch dort nichts steht, gilt 0,00 mit
     * Quelle Bewerbung (Bewerbung vorhanden heisst bekannt, wie bisher).
     * Der RG-Export MIT Bewerbung liest den Zuschlag weiter selbst
     * (ZasEmployeeFieldResolver::getZuschlag) und bleibt unveraendert.
     *
     * @return array{wert:?float, quelle:?string}
     */
    public function rgZuschlag(object $rg): array
    {
        $ausBewerbung = null;
        if ($rg->rec_applicant_id !== null) {
            $ausBewerbung = DB::table('rec_applicants')->where('id', (int) $rg->rec_applicant_id)->value('zuschlag');
            if ($ausBewerbung === null) {
                $code = DB::table('rec_applicants')
                    ->join('rec_contract_templates', 'rec_applicants.contract_template_id', '=', 'rec_contract_templates.id')
                    ->where('rec_applicants.id', (int) $rg->rec_applicant_id)
                    ->value('rec_contract_templates.code');
                $ausBewerbung = ZuschlagWert::ausAvCode($code !== null ? (string) $code : null);
            }
            if ($ausBewerbung !== null) {
                return ['wert' => (float) $ausBewerbung, 'quelle' => LohnMa::QUELLE_BEWERBUNG];
            }
        }
        if ($rg->zas_zuschlag !== null && (float) $rg->zas_grundlohn > 0) {
            return ['wert' => (float) $rg->zas_zuschlag, 'quelle' => LohnMa::QUELLE_ZAS];
        }
        if ($rg->zuschlag_manuell !== null) {
            return ['wert' => (float) $rg->zuschlag_manuell, 'quelle' => LohnMa::QUELLE_MANUELL];
        }
        if ($rg->rec_applicant_id !== null) {
            return ['wert' => 0.0, 'quelle' => LohnMa::QUELLE_BEWERBUNG];
        }

        return ['wert' => null, 'quelle' => null];
    }

    /**
     * Aktive MA-Anstellungen derselben Person, ohne die eigene — fuer den
     * Re-Export-Marker, wenn sich der RG-Zuschlag aendert.
     *
     * Bewusst weiter als die Lookup-Richtung (rgSchwester): Person ODER
     * person_key. Die MA-Zeile kann nur den person_key tragen, waehrend die
     * RG-Zeile schon eine Person hat — sie rechnet dann mit diesem RG-Zuschlag
     * (Suche ueber person_key), muss also auch markiert werden (Final-Review
     * F1). Ein Marker zu viel ist nur ein Re-Export mit gleichem Inhalt, einer
     * zu wenig ein falscher Lohn bei ZAS.
     *
     * @return list<int>
     */
    public static function maSchwesternIds(int $employeeId, ?int $personId, ?string $personKey): array
    {
        $key = trim((string) $personKey);
        if ($personId === null && $key === '') {
            return [];
        }
        $q = DB::table('rec_employees')->where('id', '!=', $employeeId)->where('is_active', 1)
            ->whereRaw('UPPER(TRIM(company)) = ?', ['MA'])
            ->where(function ($w) use ($personId, $key): void {
                if ($personId !== null) {
                    $w->orWhere('rec_person_id', $personId);
                }
                if ($key !== '') {
                    $w->orWhere('person_key', $key);
                }
            });

        return $q->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** @var array<int,float> Mindestlohn je Team — je Prozess/Request gemerkt, er gilt fuer jede Zeile gleich. */
    private static array $mindestlohnCache = [];

    public static function mindestlohn(int $teamId): float
    {
        if (isset(self::$mindestlohnCache[$teamId])) {
            return self::$mindestlohnCache[$teamId];
        }
        $wage = RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting('minimum_wage_hourly');

        return self::$mindestlohnCache[$teamId] = $wage !== null ? (float) $wage : 13.90;
    }

    /** Merker leeren (Tests, lange Queue-Worker nach Einstellungsaenderung). */
    public static function vergessen(): void
    {
        self::$mindestlohnCache = [];
    }

    public static function istMa(?string $company): bool
    {
        return strtoupper(trim((string) $company)) === 'MA';
    }
}
