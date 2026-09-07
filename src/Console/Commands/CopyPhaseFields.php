<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Recruiting\Models\RecPhase;

/**
 * Kopiert die Extra-Feld-Definitionen einer Phase in eine ANDERE, bestehende
 * Phase — abgeglichen ueber den Feldnamen.
 *
 * Gebaut fuer die Sammel-Stelle "Sonstiges": deren Phase hat keine Felder, also
 * hat weder das Enrichment noch der deterministische CRM-Sync einen Platz, um
 * Name, Telefon, Mail oder Wunschort abzulegen — beide schreiben ins Nichts
 * (im Lauf von Bewerber #3342 nachweisbar: core.extra_fields.GET lieferte
 * `fields: []`). Mit denselben Feldnamen wie die echte Stelle ziehen die Werte
 * beim Umschluesseln automatisch mit, weil
 * RecApplicant::remapExtraFieldValuesToPosition() ueber den `name` abbildet.
 *
 * Warum ein Kommando und nicht Handarbeit: die Lookup-Felder
 * (beschaftigungsort, art_der_tatigkeit, umfang_der_tatigkeit, ich_bin) tragen
 * ihre Konfiguration in `options`, und `visibility_config` referenziert andere
 * Felder per Name. Beides ist klon-stabil (dieselbe Eigenschaft, auf der schon
 * recruiting:duplicate-position aufbaut), per Hand aber kaum fehlerfrei
 * nachzubauen.
 *
 * Idempotent: ein Feld gleichen Namens in der Ziel-Phase wird AKTUALISIERT,
 * nicht ein zweites Mal angelegt. Damit ist das Kommando nach jeder Aenderung
 * an der Quell-Phase erneut ausfuehrbar, und die Sammel-Stelle driftet nicht weg.
 * Felder, die es NUR in der Ziel-Phase gibt, bleiben unangetastet — geloescht
 * wird hier nichts.
 *
 * Aufruf:
 *   php artisan recruiting:copy-phase-fields --from-phase=25 --to-phase=45 --dry-run
 *   php artisan recruiting:copy-phase-fields --from-phase=25 --to-phase=45
 */
class CopyPhaseFields extends Command
{
    protected $signature = 'recruiting:copy-phase-fields
        {--from-phase= : ID der Quell-Phase (ERFORDERLICH)}
        {--to-phase= : ID der Ziel-Phase (ERFORDERLICH)}
        {--dry-run : Nur anzeigen was passieren wuerde, keine DB-Writes}';

    protected $description = 'Kopiert die Extra-Feld-Definitionen einer Phase in eine andere bestehende Phase (Abgleich per Feldname).';

    /** Die Attribute, die eine Feld-Definition ausmachen — Liste aus DuplicatePosition. */
    private const KOPIERTE_ATTRIBUTE = [
        'label',
        'description',
        'type',
        'is_required',
        'is_mandatory',
        'is_encrypted',
        'order',
        'options',
        'visibility_config',
        'verify_by_llm',
        'verify_instructions',
        'auto_fill_source',
        'auto_fill_prompt',
    ];

    public function handle(): int
    {
        $fromId = (int) $this->option('from-phase');
        $toId = (int) $this->option('to-phase');
        $dryRun = (bool) $this->option('dry-run');

        if ($fromId <= 0 || $toId <= 0) {
            $this->error('--from-phase und --to-phase sind erforderlich.');
            return Command::FAILURE;
        }
        if ($fromId === $toId) {
            $this->error('Quell- und Ziel-Phase sind identisch.');
            return Command::FAILURE;
        }

        $from = RecPhase::find($fromId);
        $to = RecPhase::find($toId);

        if (!$from) {
            $this->error("Quell-Phase #{$fromId} nicht gefunden.");
            return Command::FAILURE;
        }
        if (!$to) {
            $this->error("Ziel-Phase #{$toId} nicht gefunden.");
            return Command::FAILURE;
        }

        $this->info("Quelle: Phase #{$from->id} \"{$from->name}\" (Stelle {$from->rec_position_id}, Team {$from->team_id})");
        $this->info("Ziel:   Phase #{$to->id} \"{$to->name}\" (Stelle {$to->rec_position_id}, Team {$to->team_id})");

        if ((int) $from->team_id !== (int) $to->team_id) {
            // Kein Abbruch: teamuebergreifendes Kopieren ist denkbar. Aber die
            // neuen Definitionen bekommen das Team der ZIEL-Phase, sonst waeren
            // sie fuer das Zielteam nicht sichtbar (forTeam-Scope).
            $this->warn('Achtung: verschiedene Teams — die Definitionen werden dem Team der Ziel-Phase zugeordnet.');
        }

        try {
            $ergebnis = $this->kopiere($from, $to, $dryRun);
        } catch (\Throwable $e) {
            $this->error("Fehler beim Kopieren: {$e->getMessage()}");
            return Command::FAILURE;
        }

        if ($ergebnis['quelleLeer']) {
            $this->warn('Die Quell-Phase hat keine Felder — nichts zu kopieren.');
            return Command::SUCCESS;
        }

        $liste = fn (array $namen) => empty($namen) ? '—' : implode(', ', $namen);

        $this->info('');
        $this->line('  Neu angelegt:  ' . $liste($ergebnis['neu']));
        $this->line('  Aktualisiert:  ' . $liste($ergebnis['aktualisiert']));
        $this->line('  Nur im Ziel (bleibt unberuehrt): ' . $liste($ergebnis['nurImZiel']));

        if ($dryRun) {
            $this->warn('');
            $this->warn('DRY-RUN — keine DB-Writes.');
            return Command::SUCCESS;
        }

        $this->info('');
        $this->info('✓ Fertig. Neu: ' . count($ergebnis['neu']) . ' | Aktualisiert: ' . count($ergebnis['aktualisiert']));

        return Command::SUCCESS;
    }

    /**
     * Die reine Arbeit, ohne Artisan-Lebenszyklus — damit sie ohne
     * $this->option()/$this->info() testbar ist (Probe-Muster des Moduls,
     * siehe DispoEscalateCommandTest).
     *
     * @return array{quelleLeer:bool, neu:string[], aktualisiert:string[], nurImZiel:string[]}
     */
    protected function kopiere(RecPhase $from, RecPhase $to, bool $dryRun): array
    {
        $quellFelder = CoreExtraFieldDefinition::query()
            ->where('context_type', RecPhase::class)
            ->where('context_id', $from->id)
            ->orderBy('order')
            ->get();

        if ($quellFelder->isEmpty()) {
            return ['quelleLeer' => true, 'neu' => [], 'aktualisiert' => [], 'nurImZiel' => []];
        }

        $zielFelder = CoreExtraFieldDefinition::query()
            ->where('context_type', RecPhase::class)
            ->where('context_id', $to->id)
            ->get()
            ->keyBy('name');

        $neu = [];
        $aktualisiert = [];

        foreach ($quellFelder as $feld) {
            if ($zielFelder->has($feld->name)) {
                $aktualisiert[] = $feld->name;
            } else {
                $neu[] = $feld->name;
            }
        }

        $ergebnis = [
            'quelleLeer' => false,
            'neu' => $neu,
            'aktualisiert' => $aktualisiert,
            'nurImZiel' => $zielFelder->keys()->diff($quellFelder->pluck('name'))->values()->all(),
        ];

        if ($dryRun) {
            return $ergebnis;
        }

        DB::transaction(function () use ($quellFelder, $zielFelder, $to) {
            foreach ($quellFelder as $feld) {
                // Nur Attribute, die das Quell-Feld wirklich mitbringt: die
                // Tabelle core_extra_field_definitions gehoert dem Kern, nicht
                // diesem Modul, und ihr Spaltenbestand unterscheidet sich je
                // nach Core-Stand (z.B. `description`). Ein Kern ohne die
                // Spalte darf den Klon nicht sprengen. Gelesen wird ueber den
                // Modell-Zugriff, damit Casts greifen (options/visibility_config
                // sind JSON) — die Liste der Kandidaten kommt aus den tatsaechlich
                // geladenen Attributen.
                $vorhandeneSpalten = array_keys($feld->getAttributes());

                $werte = [];
                foreach (self::KOPIERTE_ATTRIBUTE as $attribut) {
                    if (in_array($attribut, $vorhandeneSpalten, true)) {
                        $werte[$attribut] = $feld->{$attribut};
                    }
                }

                $vorhanden = $zielFelder->get($feld->name);

                if ($vorhanden) {
                    // Bewusst NUR die kopierten Attribute: team_id, context und
                    // created_by_user_id des bestehenden Felds bleiben, wie sie
                    // sind — und seine ID bleibt es auch, sonst verwaisten
                    // bereits erfasste Werte.
                    $vorhanden->fill($werte)->save();
                    continue;
                }

                CoreExtraFieldDefinition::create($werte + [
                    'team_id' => $to->team_id,
                    'created_by_user_id' => $feld->created_by_user_id,
                    'context_type' => RecPhase::class,
                    'context_id' => $to->id,
                    'name' => $feld->name,
                ]);
            }
        });

        return $ergebnis;
    }
}
