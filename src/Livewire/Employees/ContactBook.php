<?php

namespace Platform\Recruiting\Livewire\Employees;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Crm\Models\CrmContactList;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\EmployeeContactListSyncService;

/**
 * Settings-Panel des MA-Kontaktbuchs: Liste anlegen, Status sehen,
 * zweistufiger Sync (Dry-Run -> Bestaetigen; zweiter Klick wirkt bei
 * guard_tripped als Force-Override — ausser bei leerer Soll-Menge).
 */
class ContactBook extends Component
{
    /**
     * Dry-Run des ersten Klicks: ['report' => Array der 9 Report-Props,
     * 'guard_reason' => 'empty_soll'|'threshold'|null]. null = kein Sync angestossen.
     * Die beiden Guard-Gruende MUESSEN unterschieden werden: 'threshold' ist per
     * zweitem Klick uebersteuerbar, 'empty_soll' nie (gleiche Meldung fuer beide
     * waere eine Klick-Falle).
     */
    public ?array $pendingDryRun = null;

    public function createList(): void
    {
        // Bewusst gegen die Computed-Property statt gegen das Setting geprueft:
        // ist die konfigurierte Liste geloescht/inaktiv, muss "Neu anlegen"
        // moeglich sein (Setting wird dann ueberschrieben).
        if ($this->list && $this->list->is_active) {
            return;
        }

        $settings = RecApplicantSettings::getOrCreateForTeam($this->teamId());

        $list = CrmContactList::create([
            'name' => 'Aktive Mitarbeiter',
            'description' => '⚙️ Automatisch verwaltet durch Recruiting (MA-Kontaktbuch). Manuelle Änderungen werden beim nächsten Sync überschrieben.',
            'team_id' => $this->teamId(),
            'is_active' => true,
            'requires_doi' => false,
            'owned_by_user_id' => null,
            'created_by_user_id' => auth()->id(),
        ]);

        $settings->setSetting(EmployeeContactListSyncService::SETTING_LIST_ID, $list->id);
        $settings->save();

        $report = app(EmployeeContactListSyncService::class)->syncAll($this->teamId());

        unset($this->list, $this->lastSync);

        // partial = gelbe Warnung, nie gruen: mindestens ein Write ist fehlgeschlagen
        // (analog confirmSync() — die Erstbefuellung ist ein syncAll()-Aufruf wie jeder
        // andere und unterliegt derselben partial-Regel).
        if ($report->status === 'partial') {
            session()->flash('warning', "Kontaktbuch angelegt, aber Erstbefüllung unvollständig: +{$report->added} aufgenommen"
                . ($report->skipped_without_contact > 0 ? ", {$report->skipped_without_contact} ohne CRM-Kontakt übersprungen" : '')
                . ' — mindestens ein Schreibvorgang ist fehlgeschlagen (Details im Log unter [EmployeeContactListSync]). last_sync wurde nicht aktualisiert.');

            return;
        }

        if ($report->status !== 'ok') {
            // Defensiv: auf einer frisch angelegten Liste kann syncAll() hier eigentlich
            // nur 'ok' oder 'partial' liefern (guard_tripped braeuchte bereits vorhandene
            // Ist-Mitglieder). Falls doch ein anderer Status auftaucht, lieber neutral
            // warnen als faelschlich gruen zu melden.
            session()->flash('warning', "Kontaktbuch angelegt, aber Erstbefüllung nicht möglich (Status: {$report->status}).");

            return;
        }

        session()->flash('message', "Kontaktbuch angelegt — {$report->added} Mitarbeiter aufgenommen"
            . ($report->skipped_without_contact > 0 ? ", {$report->skipped_without_contact} ohne CRM-Kontakt übersprungen" : '')
            . '.');
    }

    public function startSync(): void
    {
        $preview = app(EmployeeContactListSyncService::class)->preview($this->teamId());
        $this->pendingDryRun = [
            'report' => get_object_vars($preview['report']),
            'guard_reason' => $preview['guard_reason'],
        ];
    }

    public function confirmSync(): void
    {
        // 'empty_soll' ist nie uebersteuerbar — Button ist im Blade ausgeblendet,
        // das hier ist die zweite Absicherung gegen direkte Livewire-Calls.
        if (($this->pendingDryRun['guard_reason'] ?? null) === 'empty_soll') {
            return;
        }

        $force = ($this->pendingDryRun['guard_reason'] ?? null) === 'threshold';
        $report = app(EmployeeContactListSyncService::class)->syncAll($this->teamId(), force: $force);
        $this->pendingDryRun = null;

        unset($this->list, $this->lastSync);

        // partial = gelbe Warnung, nie gruen: mindestens ein Write ist fehlgeschlagen.
        if ($report->status === 'partial') {
            session()->flash('warning', "Sync unvollständig: +{$report->added} hinzugefügt, -{$report->removed} entfernt, {$report->normalized} renormalisiert — mindestens ein Schreibvorgang ist fehlgeschlagen (Details im Log). last_sync wurde nicht aktualisiert.");

            return;
        }

        session()->flash('message', match ($report->status) {
            'ok' => "Sync ausgeführt: +{$report->added} hinzugefügt, -{$report->removed} entfernt, {$report->normalized} renormalisiert.",
            // Generisch, weil syncAll() den Guard-Grund NICHT im Report trägt (Vertrag
            // bleibt 9-Felder-Report ohne guard_reason) und der Guard seit dem Dry-Run-Klick
            // aus einem ANDEREN Grund als im Preview angezeigt getrippt sein kann (z. B.
            // 'threshold' statt 'empty_soll', wenn sich die Ist-Menge inzwischen geaendert
            // hat) — eine fest verdrahtete Diagnose koennte hier falsch liegen.
            'guard_tripped' => "Sync abgebrochen: Schutz ausgelöst. Bitte erneut „Jetzt synchronisieren\" klicken — der neue Prüflauf zeigt den Grund.",
            default => "Sync nicht möglich (Status: {$report->status}).",
        });
    }

    public function cancelSync(): void
    {
        $this->pendingDryRun = null;
    }

    #[Computed]
    public function list(): ?CrmContactList
    {
        $listId = RecApplicantSettings::getOrCreateForTeam($this->teamId())
            ->getSetting(EmployeeContactListSyncService::SETTING_LIST_ID);

        if (!$listId) {
            return null;
        }

        return CrmContactList::query()
            ->where('id', (int) $listId)
            ->where('team_id', $this->teamId())
            ->first();
    }

    #[Computed]
    public function lastSync(): ?string
    {
        $iso = RecApplicantSettings::getOrCreateForTeam($this->teamId())
            ->getSetting(EmployeeContactListSyncService::SETTING_LAST_SYNC);

        return $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d.m.Y H:i') : null;
    }

    public function render()
    {
        return view('recruiting::livewire.employees.contact-book')
            ->layout('platform::layouts.app');
    }

    private function teamId(): int
    {
        return (int) auth()->user()->currentTeam->id;
    }
}
