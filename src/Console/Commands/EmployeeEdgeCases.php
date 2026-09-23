<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Zaehlt Grenzfaelle im Mitarbeiterbestand.
 *
 * Zweck: Grundlage fuer den Demo-Seeder (echte Verteilung, erfundene
 * Menschen) und fuer die Frage, wie viele Leute am ersten Portal-Tag
 * ueberhaupt hineinkommen — ohne dass ein Personendatensatz die
 * Produktion verlaesst.
 *
 * Gibt ausschliesslich ZAHLEN aus. Mit --fall=<schluessel> lassen sich
 * die Kennungen eines einzelnen Falls nachziehen, wenn HR ihn bearbeiten
 * soll; Namen kommen auch dann nicht mit.
 *
 * Jeder Fall ist EINE Einschraenkung auf derselben Grundabfrage — Zaehlung
 * und Auflistung koennen deshalb nicht auseinanderlaufen.
 */
final class EmployeeEdgeCases extends Command
{
    protected $signature = 'recruiting:mitarbeiter-grenzfaelle
        {--team= : Nur Mitarbeiter dieses Teams}
        {--fall= : Kennungen dieses Falls ausgeben}
        {--vorlauf=60 : Tage Vorlauf fuer "laeuft bald ab"}';

    protected $description = 'Grenzfaelle im Mitarbeiterbestand zaehlen (Portal-Tauglichkeit, Nachweise, Paarung)';

    public function handle(): int
    {
        $vorlauf = max(1, (int) $this->option('vorlauf'));
        $faelle = $this->faelle($vorlauf);

        if ($fall = $this->option('fall')) {
            if (!isset($faelle[$fall])) {
                $this->error("Unbekannter Fall '{$fall}'. Moeglich: " . implode(', ', array_keys($faelle)));
                return self::FAILURE;
            }
            $ids = $this->basis()->tap($faelle[$fall]['filter'])->orderBy('id')->pluck('id');
            $this->info("{$faelle[$fall]['label']} — {$ids->count()} Kennungen:");
            $this->line($ids->implode(', '));
            return self::SUCCESS;
        }

        $gesamt = (int) $this->basis()->count();
        if ($gesamt === 0) {
            $this->warn('Keine Mitarbeiter gefunden — falsches Team oder leere Datenbank?');
            return self::SUCCESS;
        }

        $rows = [];
        $gruppe = null;
        foreach ($faelle as $schluessel => $fallDef) {
            if ($gruppe !== null && $fallDef['gruppe'] !== $gruppe) {
                $rows[] = new \Symfony\Component\Console\Helper\TableSeparator();
            }
            $gruppe = $fallDef['gruppe'];

            $anzahl = (int) $this->basis()->tap($fallDef['filter'])->count();
            $rows[] = [
                $schluessel,
                $fallDef['label'],
                $anzahl,
                $anzahl > 0 ? sprintf('%.1f %%', $anzahl / $gesamt * 100) : '—',
            ];
        }

        $this->info("Mitarbeiter gesamt: {$gesamt}");
        $this->table(['Schluessel', 'Fall', 'Anzahl', 'Anteil'], $rows);
        $this->line('');
        $this->line('Kennungen eines Falls: --fall=<schluessel>');

        return self::SUCCESS;
    }

    private function basis(): Builder
    {
        $q = DB::table('rec_employees');
        if ($this->option('team') !== null) {
            $q->where('team_id', (int) $this->option('team'));
        }
        return $q;
    }

    /** @return array<string, array{gruppe:string, label:string, filter:\Closure}> */
    private function faelle(int $vorlauf): array
    {
        $leer = static fn (string $spalte) => static fn (Builder $q) => $q
            ->where(fn ($w) => $w->whereNull($spalte)->orWhere($spalte, ''));

        $abgelaufen = static fn (string $spalte) => static fn (Builder $q) => $q
            ->where('is_active', true)
            ->whereNotNull($spalte)
            ->whereDate($spalte, '<', now()->toDateString());

        return [
            'aktiv' => ['gruppe' => 'bestand', 'label' => 'aktiv', 'filter' =>
                static fn (Builder $q) => $q->where('is_active', true)],
            'inaktiv' => ['gruppe' => 'bestand', 'label' => 'inaktiv', 'filter' =>
                static fn (Builder $q) => $q->where('is_active', false)],

            // --- kommt der Mensch ueberhaupt ins Portal? ---
            'ohne_geburtsdatum' => ['gruppe' => 'login', 'label' => 'ohne Geburtsdatum (Login-Faktor 1)', 'filter' =>
                static fn (Builder $q) => $q->whereNull('birth_date')],
            'ohne_ausweisnummer' => ['gruppe' => 'login', 'label' => 'ohne Ausweisnummer (Login-Faktor 2)', 'filter' =>
                $leer('identity_card_number')],
            'login_unmoeglich' => ['gruppe' => 'login', 'label' => 'AKTIV, aber Anmeldung unmoeglich', 'filter' =>
                static fn (Builder $q) => $q->where('is_active', true)
                    ->where(fn ($w) => $w->whereNull('birth_date')
                        ->orWhereNull('identity_card_number')
                        ->orWhere('identity_card_number', ''))],

            // --- erreichen wir ihn? ---
            'ohne_telefon_akte' => ['gruppe' => 'erreichbar', 'label' => 'ohne Telefonnummer in der Akte', 'filter' =>
                $leer('phone')],
            'ohne_crm_kontakt' => ['gruppe' => 'erreichbar', 'label' => 'ohne verknuepften CRM-Kontakt', 'filter' =>
                static fn (Builder $q) => $q->whereNotExists(fn ($s) => $s->from('crm_contact_links')
                    ->whereColumn('crm_contact_links.linkable_id', 'rec_employees.id')
                    ->where('crm_contact_links.linkable_type', 'LIKE', '%RecEmployee'))],
            'crm_ohne_nummer' => ['gruppe' => 'erreichbar', 'label' => 'CRM-Kontakt da, aber ohne aktive Nummer (Versandweg!)', 'filter' =>
                static fn (Builder $q) => $q
                    ->whereExists(fn ($s) => $s->from('crm_contact_links')
                        ->whereColumn('crm_contact_links.linkable_id', 'rec_employees.id')
                        ->where('crm_contact_links.linkable_type', 'LIKE', '%RecEmployee'))
                    ->whereNotExists(fn ($s) => $s->from('crm_contact_links')
                        ->join('crm_phone_numbers', function ($j) {
                            $j->on('crm_phone_numbers.phoneable_id', '=', 'crm_contact_links.contact_id')
                              ->where('crm_phone_numbers.is_active', true)
                              ->whereNotNull('crm_phone_numbers.international');
                        })
                        ->whereColumn('crm_contact_links.linkable_id', 'rec_employees.id')
                        ->where('crm_contact_links.linkable_type', 'LIKE', '%RecEmployee'))],

            // --- Nachweise ---
            'nicht_eu' => ['gruppe' => 'nachweise', 'label' => 'Nicht-EU-Buerger', 'filter' =>
                static fn (Builder $q) => $q->where('is_eu_citizen', false)],
            'nicht_eu_ohne_titel' => ['gruppe' => 'nachweise', 'label' => 'Nicht-EU ohne Aufenthaltstitel UND ohne Fiktionsbescheinigung', 'filter' =>
                static fn (Builder $q) => $q->where('is_eu_citizen', false)->where('is_active', true)
                    ->whereNull('aufenthaltstitel_front_file_id')
                    ->whereNull('fiktionsbescheinigung_front_file_id')],
            'ausweis_abgelaufen' => ['gruppe' => 'nachweise', 'label' => 'Ausweis abgelaufen', 'filter' =>
                $abgelaufen('identity_card_valid_until')],
            'aufenthalt_abgelaufen' => ['gruppe' => 'nachweise', 'label' => 'Aufenthaltserlaubnis abgelaufen', 'filter' =>
                $abgelaufen('residence_permit_valid_until')],
            'arbeitsgenehmigung_abgelaufen' => ['gruppe' => 'nachweise', 'label' => 'Arbeitsgenehmigung abgelaufen', 'filter' =>
                $abgelaufen('work_permit_valid_until')],
            'schulbesch_abgelaufen' => ['gruppe' => 'nachweise', 'label' => 'Schul-/Immatrikulationsbescheinigung abgelaufen', 'filter' =>
                $abgelaufen('school_certificate_valid_until')],
            'ersthelfer_abgelaufen' => ['gruppe' => 'nachweise', 'label' => 'Ersthelferschein abgelaufen', 'filter' =>
                $abgelaufen('first_aider_valid_until')],
            'laeuft_bald_ab' => ['gruppe' => 'nachweise', 'label' => "irgendein Nachweis laeuft in {$vorlauf} Tagen ab", 'filter' =>
                static fn (Builder $q) => $q->where('is_active', true)->where(function ($w) use ($vorlauf) {
                    foreach ([
                        'identity_card_valid_until', 'residence_permit_valid_until',
                        'work_permit_valid_until', 'school_certificate_valid_until',
                        'first_aider_valid_until',
                    ] as $spalte) {
                        $w->orWhere(fn ($x) => $x->whereNotNull($spalte)
                            ->whereDate($spalte, '>=', now()->toDateString())
                            ->whereDate($spalte, '<=', now()->addDays($vorlauf)->toDateString()));
                    }
                })],

            // --- Datenqualitaet ---
            'geburtstag_1_januar' => ['gruppe' => 'qualitaet', 'label' => 'Geburtsdatum auf dem 1. Januar (Verdacht: konstruiert)', 'filter' =>
                static fn (Builder $q) => $q->whereNotNull('birth_date')
                    ->whereRaw("DATE_FORMAT(birth_date, '%m-%d') = '01-01'")],
            'unter_18' => ['gruppe' => 'qualitaet', 'label' => 'unter 18 Jahre', 'filter' =>
                static fn (Builder $q) => $q->whereNotNull('birth_date')
                    ->whereRaw('birth_date > DATE_SUB(CURDATE(), INTERVAL 18 YEAR)')],
            'ohne_bewerbung' => ['gruppe' => 'qualitaet', 'label' => 'ohne verknuepfte Bewerbung', 'filter' =>
                static fn (Builder $q) => $q->whereNull('rec_applicant_id')],
            'inaktiv_mit_einsaetzen' => ['gruppe' => 'qualitaet', 'label' => 'inaktiv, aber mit Einsaetzen', 'filter' =>
                static fn (Builder $q) => $q->where('is_active', false)
                    ->whereExists(fn ($s) => $s->from('rec_dispo_assignments')
                        ->whereColumn('rec_dispo_assignments.rec_employee_id', 'rec_employees.id')
                        ->where('rec_dispo_assignments.status_id', '!=', 3)
                        ->whereNull('rec_dispo_assignments.zas_removed_at'))],

            // --- Paarung RG/MA ---
            'person_key_gesetzt' => ['gruppe' => 'paarung', 'label' => 'Personen-Marker gesetzt', 'filter' =>
                static fn (Builder $q) => $q->whereNotNull('person_key')],
        ];
    }
}
