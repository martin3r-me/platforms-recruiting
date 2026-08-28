<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoReconfirmMarker;

/**
 * Bucht einen (Test-)Mitarbeiter in eine frisch angelegte Test-Veranstaltung,
 * damit der komplette Bestaetigungs-Flow (Sende-Modal → WhatsApp-Template →
 * oeffentliche Einsatz-Seite → Bestaetigen) an echten Daten durchgespielt
 * werden kann — OHNE echte ZAS-Lieferung.
 *
 * Bewusst KEIN Auto-Versand: die WhatsApp wird ganz normal ueber das
 * Sende-Modal in „Disposition → Veranstaltungen" ausgeloest, damit der echte
 * Ablauf (Empfaenger-Vorschau, Vorlaufzeit, Kanal, Template) geprueft wird.
 *
 * Die VA ist an einem festen einsatz_ref (Default TEST-VA) erkennbar und mit
 * --remove restlos wieder loeschbar (im Gegensatz zu dispo-reset, das ALLES
 * leert). Nummer + portal_token bleiben MA-Sache (wie im echten ZAS-Ablauf):
 * die Nummer zieht der Sender zur Sendezeit aus rec_employees.phone; --phone
 * setzt sie nur fuer den Test auf dein eigenes Handy.
 *
 * @see self::createTestVa() / self::removeTestVa() — reine Logik nur auf den
 *      zwei Dispo-Tabellen, per Probe-Muster testbar (DispoTestVaCommandTest).
 */
class DispoTestVaCommand extends Command
{
    protected $signature = 'recruiting:dispo-test-va
        {--employee= : Test-MA: numerische ID, exakte Personalnummer oder Namensfragment}
        {--phone= : Optional die Handynummer des MA fuer den Test setzen (dein Handy). Leer = vorhandene Nummer aus dem MA-Datensatz}
        {--days=1 : Anzahl Einsatztage (1 = Eintages-VA, >1 = Mehrtages mit Tagesliste)}
        {--ref=TEST-VA : einsatz_ref der Test-VA (wiederverwendbar / mit --remove loeschbar)}
        {--remove : Test-VA + zugehoerige Buchungen wieder loeschen und beenden}
        {--lead : Buchung(en) mit der Taetigkeit Teamleitung anlegen (Ansprechpartner-Vorbelegung testen)}
        {--von= : Schichtbeginn HH:MM fuer Tag 1 (Zeit aendern -> Rebestaetigung testen)}';

    protected $description = 'Legt eine Test-Veranstaltung an und bucht einen MA hinein (Bestaetigungs-Flow end-to-end pruefen)';

    public function handle(): int
    {
        $ref = trim((string) $this->option('ref')) ?: 'TEST-VA';

        if ($this->option('remove')) {
            $removed = $this->removeTestVa($ref);
            $this->info(sprintf(
                'Test-VA "%s" entfernt: %d Veranstaltung(en), %d Buchung(en).',
                $ref,
                $removed['events'],
                $removed['assignments']
            ));

            return self::SUCCESS;
        }

        $identifier = trim((string) $this->option('employee'));
        if ($identifier === '') {
            $this->error('Bitte --employee angeben (ID, Personalnummer oder Namensfragment).');

            return self::FAILURE;
        }

        $employee = $this->resolveEmployee($identifier);
        if ($employee === null) {
            return self::FAILURE;
        }

        // Nummer optional fuer den Test setzen; sonst die bestehende MA-Nummer
        // nutzen (wie im echten Ablauf). Token bei Bedarf sicherstellen.
        $phoneOverride = trim((string) $this->option('phone'));
        if ($phoneOverride !== '') {
            $old = (string) ($employee->phone ?? '');
            $employee->phone = $phoneOverride;
            $employee->save();
            $this->line(sprintf('Handynummer gesetzt: %s → %s', $old !== '' ? $old : '(leer)', $phoneOverride));
        }
        if (empty($employee->portal_token)) {
            // Der uuid-Hook feuert nur beim Anlegen, nicht beim Update → explizit setzen.
            $employee->portal_token = (string) \Symfony\Component\Uid\UuidV7::generate();
            $employee->save();
        }

        $currentPhone = trim((string) ($employee->phone ?? ''));
        if ($currentPhone === '') {
            $this->warn('Achtung: Der MA hat KEINE Handynummer — der Versand wuerde ihn ueberspringen (Skip-Grund no_phone). Mit --phone=<Nummer> setzen.');
        }

        $days = max(1, (int) $this->option('days'));
        $leadTaetigkeit = (string) (((array) config('recruiting.zas.dispo_lead_taetigkeiten', ['Teamleitung']))[0] ?? 'Teamleitung');
        $vonOverride = trim((string) $this->option('von')) ?: null;
        $result = $this->createTestVa(
            (int) $employee->id,
            (string) ($employee->personnel_number ?: 'TEST'),
            $days,
            $ref,
            $this->option('lead') ? $leadTaetigkeit : 'Service',
            $vonOverride
        );

        $link = rtrim((string) config('app.url'), '/') . '/recruiting/einsaetze/' . $employee->portal_token;

        $this->info('Test-VA angelegt und MA gebucht.');
        $this->table(['Feld', 'Wert'], [
            ['MA', trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')) . ' (#' . $employee->id . ')'],
            ['Personalnummer', (string) ($employee->personnel_number ?: '—')],
            ['Handynummer', $currentPhone !== '' ? $currentPhone : '— (Versand wuerde skippen!)'],
            ['VA', $ref . ' (#' . $result['event_id'] . ')'],
            ['Einsatztage', (string) $result['days']],
            ['Portal-Link', $link],
        ]);
        if ($result['reconfirm_marked'] > 0) {
            $this->info('Rebestätigung markiert: ' . $result['reconfirm_marked']);
        }
        $this->newLine();
        $this->line('<comment>Naechste Schritte:</comment>');
        $this->line('  1. Disposition → Einstellungen: Bestaetigungs-Template gewaehlt? (sonst inert)');
        $this->line('  2. Disposition → Veranstaltungen → "' . $ref . '" → Senden (Vorlaufzeit eintragen, Empfaenger pruefen).');
        $this->line('  3. WhatsApp auf dem Handy → Link → Seite → Bestaetigen.');
        $this->line('  Aufraeumen: recruiting:dispo-test-va --remove --ref=' . $ref);

        return self::SUCCESS;
    }

    /**
     * Legt die Test-VA (updateOrCreate auf einsatz_ref) an und bucht den MA mit
     * $days aufeinanderfolgenden Auftrags-Einbuchungen ab morgen. Upsert auf
     * ds_ref — Bestaetigungen bleiben erhalten; `--von` aendert Tag 1 (fuer den
     * Rebestaetigungs-Test: gleicher Weg wie der echte Import ueber den Marker).
     * Ueberzaehlige Test-Buchungen dieses MA in dieser VA (kleineres --days im
     * Folgelauf) werden entfernt. Reine Logik — nur Dispo-Tabellen.
     *
     * @return array{event_id: int, assignment_ids: list<int>, days: int, reconfirm_marked: int}
     */
    public function createTestVa(int $employeeId, string $personnelNumber, int $days, string $ref, string $taetigkeit = 'Service', ?string $vonOverride = null): array
    {
        $days = max(1, $days);
        $start = now()->addDay()->startOfDay();

        $event = RecDispoEvent::updateOrCreate(
            ['einsatz_ref' => $ref],
            [
                'name'            => 'TEST — Dispo-Bestaetigung',
                'venue_text'      => 'Messe Duesseldorf, Stockumer Kirchstr. 61, 40474 Duesseldorf',
                'ort'             => 'Eingang Nord, Tor 3. Am Personaleingang melden, Ausweis mitbringen.',
                'dresscode'       => 'Schwarze Stoffhose, schwarze geschlossene Schuhe. Hemd bekommst du vor Ort.',
                'ansprechpartner' => 'Sheran (0170 1234567)',
                'vorlauf_minuten' => 30,
                'starts_on'       => $start->toDateString(),
                'ends_on'         => $start->copy()->addDays($days - 1)->toDateString(),
            ]
        );

        // Schichtzeiten pro Tag (fuer Mehrtages verschiedene Zeiten, damit die
        // Tagesliste sichtbar unterschiedliche Zeiten zeigt).
        $schedule = [
            ['von' => '16:00', 'bis' => '22:00'],
            ['von' => '10:00', 'bis' => '18:00'],
            ['von' => '10:00', 'bis' => '17:00'],
        ];

        $marker = app(DispoReconfirmMarker::class);
        $today = now()->toDateString();

        $incoming = [];
        $attrsByRef = [];
        for ($i = 0; $i < $days; $i++) {
            $slot = $schedule[$i] ?? ['von' => '10:00', 'bis' => '18:00'];
            if ($i === 0 && $vonOverride !== null) {
                $slot['von'] = $vonOverride;
            }
            $dsRef = $ref . '-' . $employeeId . '-' . $i;
            $attrsByRef[$dsRef] = [
                'rec_dispo_event_id' => $event->id,
                'pnr_raw'            => $personnelNumber,
                'rec_employee_id'    => $employeeId,
                'datum'              => $start->copy()->addDays($i)->toDateString(),
                'von'                => $slot['von'],
                'bis'                => $slot['bis'],
                'status_id'          => RecDispoAssignment::STATUS_AUFTRAG,
                'taetigkeit'         => $taetigkeit,
                'individual_note'    => $i === 0 ? 'Bitte am ersten Tag 15 Min frueher da sein — kurze Einweisung am Stand.' : null,
                'last_seen_at'       => now(),
                'missing_since'      => null,
            ];
            $incoming[$dsRef] = ['datum' => $attrsByRef[$dsRef]['datum'], 'von' => $slot['von'], 'bis' => $slot['bis']];
        }

        // Gleicher Weg wie der echte Import: Zeitaenderung an bestaetigter Einbuchung -> Marker.
        $reconfirm = $marker->plan($incoming, $today);

        $assignmentIds = [];
        foreach ($attrsByRef as $dsRef => $attrs) {
            $a = RecDispoAssignment::updateOrCreate(['ds_ref' => $dsRef], array_merge($attrs, $reconfirm['overrides'][$dsRef] ?? []));
            $assignmentIds[] = (int) $a->id;
        }

        // Idempotenz bei kleinerem --days: ueberzaehlige Test-Buchungen dieses MA in dieser VA weg.
        RecDispoAssignment::query()
            ->where('rec_dispo_event_id', $event->id)
            ->where('rec_employee_id', $employeeId)
            ->whereNotIn('ds_ref', array_keys($attrsByRef))
            ->delete();

        return ['event_id' => (int) $event->id, 'assignment_ids' => $assignmentIds, 'days' => $days, 'reconfirm_marked' => $reconfirm['count']];
    }

    /**
     * Loescht die Test-VA(s) mit diesem einsatz_ref samt aller Buchungen.
     * Reine Logik — nur Dispo-Tabellen.
     *
     * @return array{events: int, assignments: int}
     */
    public function removeTestVa(string $ref): array
    {
        $eventIds = RecDispoEvent::query()->where('einsatz_ref', $ref)->pluck('id')->all();
        if ($eventIds === []) {
            return ['events' => 0, 'assignments' => 0];
        }

        $assignmentCount = RecDispoAssignment::query()->whereIn('rec_dispo_event_id', $eventIds)->count();
        RecDispoAssignment::query()->whereIn('rec_dispo_event_id', $eventIds)->delete();
        $eventCount = RecDispoEvent::query()->whereIn('id', $eventIds)->count();
        RecDispoEvent::query()->whereIn('id', $eventIds)->delete();

        return ['events' => $eventCount, 'assignments' => $assignmentCount];
    }

    /**
     * MA aufloesen: numerisch → ID; sonst exakte Personalnummer; sonst
     * Namensfragment (first/last). Mehrdeutig oder leer → Fehlermeldung + null.
     */
    private function resolveEmployee(string $identifier): ?RecEmployee
    {
        if (ctype_digit($identifier)) {
            $byId = RecEmployee::find((int) $identifier);
            if ($byId !== null) {
                return $byId;
            }
        }

        $byPnr = RecEmployee::query()->where('personnel_number', $identifier)->get();
        if ($byPnr->count() === 1) {
            return $byPnr->first();
        }

        $byName = RecEmployee::query()
            ->where(function ($q) use ($identifier) {
                $q->where('first_name', 'like', '%' . $identifier . '%')
                    ->orWhere('last_name', 'like', '%' . $identifier . '%');
            })
            ->limit(10)
            ->get();

        if ($byName->count() === 1) {
            return $byName->first();
        }

        if ($byName->count() > 1) {
            $this->error('Mehrdeutig — bitte per ID/Personalnummer angeben. Treffer:');
            foreach ($byName as $e) {
                $this->line(sprintf('  #%d  %s %s  (PNr %s)', $e->id, $e->first_name, $e->last_name, $e->personnel_number ?: '—'));
            }

            return null;
        }

        $this->error('Kein MA gefunden fuer: ' . $identifier);

        return null;
    }
}
