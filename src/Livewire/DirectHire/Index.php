<?php

namespace Platform\Recruiting\Livewire\DirectHire;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPosition;

/**
 * Direkteinstellungen-Uebersicht: listet aktive Direct-Hire-Stellen mit ihren
 * Bewerbern, gruppiert je Stelle. HR kann pro Bewerber die Datenerfassung
 * starten (Phase 1 -> Phase 2 + Portal-Link senden), parken oder oeffnen.
 * Bewusst einfach gehalten — kein Phasen-Board.
 */
class Index extends Component
{
    public bool $onlyMine = false;

    #[Computed]
    public function positions()
    {
        $q = RecPosition::forTeam((int) Auth::user()->currentTeam->id)
            ->directHire()
            ->where('is_active', true)
            ->with([
                'ownedByUser',
                'phases' => fn ($q) => $q->where('is_active', true)->orderBy('order'),
                'postings.externalRefs.sourcePlatform',
                'postings.commsChannels',
            ])
            ->orderBy('title');

        if ($this->onlyMine) {
            $q->where('owned_by_user_id', Auth::id());
        }

        return $q->get();
    }

    #[Computed]
    public function applicantsByPosition(): array
    {
        $positionIds = $this->positions->pluck('id')->all();

        if (empty($positionIds)) {
            return [];
        }

        return RecApplicant::query()
            ->forTeam((int) Auth::user()->currentTeam->id)
            ->where('is_active', true)
            ->where('is_parked', false)
            ->whereHas('postings.position', fn ($q) => $q->whereIn('rec_positions.id', $positionIds))
            ->with([
                'crmContactLinks.contact.emailAddresses',
                'crmContactLinks.contact.phoneNumbers',
                'phase',
                'postings.position',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (RecApplicant $a) => $a->postings->first()?->position?->id)
            ->all();
    }

    public function startDataCollection(int $applicantId): void
    {
        $applicant = RecApplicant::query()
            ->forTeam((int) Auth::user()->currentTeam->id)
            ->with('postings.position.phases')
            ->find($applicantId);
        if (!$applicant) {
            return;
        }

        $position = $applicant->postings->first()?->position;
        if (!$position || !$position->is_direct_hire) {
            return;
        }

        $phase2 = $position->phases()->where('order', 2)->where('is_active', true)->first();
        if (!$phase2) {
            session()->flash('message', 'Datenerfassung konnte nicht gestartet werden: keine Phase 2 auf der Stelle.');
            return;
        }

        $applicant->update([
            'rec_phase_id' => $phase2->id,
            'progress' => 0,
        ]);

        // Phase-2-Datenfelder aus dem CRM-Kontakt vorbefuellen, damit der
        // Kandidat im Portal Name/E-Mail/Telefon angereichert sieht und
        // ueberschreiben kann. NUR wo das Extra-Field noch leer ist, damit
        // bereits erfasste Werte nicht ueberschrieben werden (idempotent).
        $this->prefillContactFields($applicant);

        $result = $applicant->sendContractPortalNotification();

        session()->flash('message', ($result['ok'] ?? false)
            ? 'Datenerfassung gestartet — Portal-Link wurde gesendet.'
            : 'Datenerfassung gestartet. Portal-Link bitte manuell senden — automatischer Versand fehlgeschlagen: ' . ($result['message'] ?? 'unbekannter Fehler'));

        unset($this->applicantsByPosition, $this->positions);
        $this->dispatch('sidebar-refresh');
    }

    /**
     * Befuellt die Phase-2-Extra-Fields vorname/nachname/email/telefonnummer
     * aus dem primaeren CRM-Kontakt des Bewerbers — aber nur dort, wo das
     * jeweilige Extra-Field aktuell leer ist. Der Kandidat sieht die Werte
     * im Portal vorbefuellt und kann sie ueberschreiben; sein Wert gewinnt
     * spaeter in der Fallback-Kette von CreateEmployeeFromApplicantService
     * (extra_field ?? contact).
     */
    private function prefillContactFields(RecApplicant $applicant): void
    {
        $applicant->loadMissing([
            'crmContactLinks.contact.emailAddresses',
            'crmContactLinks.contact.phoneNumbers',
        ]);

        $contact = $applicant->crmContactLinks->first()?->contact;
        if (!$contact) {
            return;
        }

        $phone = $contact->phoneNumbers->first(fn ($p) => $p->is_active)?->international
            ?: $contact->phoneNumbers->first(fn ($p) => $p->is_active)?->raw_input
            ?: $contact->phoneNumbers->first()?->international
            ?: $contact->phoneNumbers->first()?->raw_input;

        $candidates = [
            'vorname' => $contact->first_name,
            'nachname' => $contact->last_name,
            'email' => $contact->emailAddresses->first()?->email_address,
            'telefonnummer' => $phone,
        ];

        foreach ($candidates as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $current = $applicant->getExtraField($name);
            if ($current === null || $current === '') {
                $applicant->setExtraField($name, $value);
            }
        }
    }

    public function parkApplicant(int $applicantId): void
    {
        $applicant = RecApplicant::query()
            ->forTeam((int) Auth::user()->currentTeam->id)
            ->find($applicantId);
        if (!$applicant) {
            return;
        }

        $applicant->update([
            'is_parked' => true,
            'parked_at' => now(),
            'auto_pilot' => false,
        ]);

        session()->flash('message', 'Bewerber geparkt.');

        unset($this->applicantsByPosition, $this->positions);
        $this->dispatch('sidebar-refresh');
    }

    public function render()
    {
        return view('recruiting::livewire.direct-hire.index')
            ->layout('platform::layouts.app');
    }
}
