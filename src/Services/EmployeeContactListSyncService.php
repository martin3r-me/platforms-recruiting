<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactList;
use Platform\Crm\Models\CrmContactListMember;
use Platform\Crm\Services\Comms\SubscriptionService;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Sync-verwaltete CRM-Kontaktliste "Aktive Mitarbeiter" (MA-Kontaktbuch).
 * Design: docs/superpowers/specs/2026-08-04-ma-kontaktbuch-design.md.
 *
 * Befuellen via SubscriptionService::subscribe(..., 'manual_admin') (idempotent,
 * kein DOI). Entfernen per Row-Delete + updateMemberCount() — CRM-eigenes Muster
 * (ContactList::removeMember); noetig, weil die CardDAV-Auslieferung NICHT auf
 * Member-Status filtert und unsubscribe() Abgemeldete im Telefonbuch liesse.
 *
 * Der Service wirft in den definierten Flows nie: not_configured, list_missing
 * und guard_tripped sind Report-Stati, keine Exceptions.
 */
class EmployeeContactListSyncService
{
    public const SETTING_LIST_ID = 'employee_contact_list_id';
    public const SETTING_LAST_SYNC = 'employee_contact_list_last_sync';
    public const GUARD_MAX_REMOVALS = 25;
    public const GUARD_MAX_REMOVAL_RATIO = 0.5;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    /**
     * Voll-Sync eines Teams. Soll = aktive MA mit auslieferbarem Kontakt,
     * Ist = ALLE Member-Zeilen der Liste (statusunabhaengig).
     */
    public function syncAll(int $teamId, bool $dryRun = false, bool $force = false): EmployeeContactListSyncReport
    {
        [$list, $settings, $error] = $this->resolveList($teamId);
        if ($error !== null) {
            return self::emptyReport($dryRun, $error);
        }

        $resolved = $this->resolveDesired($teamId);
        $ist = $this->currentMembers($list);

        $diff = self::computeDiff($resolved['contact_ids'], $ist, $force);
        $report = self::reportFrom($diff, $resolved['counters'], $dryRun);

        if ($dryRun || $report->status !== 'ok') {
            return $report;
        }

        $toSubscribe = array_merge($diff->toAdd, $diff->toNormalize);
        if ($toSubscribe !== []) {
            foreach (CrmContact::query()->whereIn('id', $toSubscribe)->get() as $contact) {
                try {
                    $this->subscriptions->subscribe($list, $contact, 'manual_admin');
                } catch (\Throwable $e) {
                    Log::error('[EmployeeContactListSync] subscribe fehlgeschlagen', [
                        'contact_id' => $contact->id,
                        'list_id' => $list->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($diff->toRemove !== []) {
            CrmContactListMember::query()
                ->where('contact_list_id', $list->id)
                ->whereIn('contact_id', $diff->toRemove)
                ->delete();
        }

        $list->updateMemberCount();

        // Nur syncAll schreibt last_sync (JSON Read-Modify-Write; Observer-Saves
        // wuerden parallele Aenderungen anderer Keys klobbern).
        $settings->setSetting(self::SETTING_LAST_SYNC, now()->toIso8601String());
        $settings->save();

        return $report;
    }

    /**
     * Einzel-Sync fuer den Observer. Wertet dieselbe Soll-Logik TEAM-WEIT aus,
     * beschraenkt auf die Kontakte dieses MA: der Kontakt des deaktivierten MA #1
     * kann der gewaehlte Kontakt eines aktiven MA #2 sein und darf dann bleiben.
     * Kein Guard (Blast-Radius = Kontakte eines einzelnen MA), kein last_sync.
     */
    public function syncEmployee(RecEmployee $employee): void
    {
        [$list, , $error] = $this->resolveList((int) $employee->team_id);
        if ($error !== null) {
            return;
        }

        $affected = $employee->crmContactLinks()
            ->pluck('contact_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($affected === []) {
            return;
        }

        $resolved = $this->resolveDesired((int) $employee->team_id, $affected);
        $desired = array_values(array_intersect($resolved['contact_ids'], $affected));

        $removedAny = false;

        foreach ($affected as $contactId) {
            $member = CrmContactListMember::query()
                ->where('contact_list_id', $list->id)
                ->where('contact_id', $contactId)
                ->first();

            if (in_array($contactId, $desired, true)) {
                if (!$member || $member->status !== 'subscribed') {
                    $contact = CrmContact::query()->find($contactId);
                    if ($contact) {
                        // subscribe() ruft updateMemberCount() selbst.
                        $this->subscriptions->subscribe($list, $contact, 'manual_admin');
                    }
                }
            } elseif ($member) {
                $member->delete();
                $removedAny = true;
            }
        }

        if ($removedAny) {
            $list->updateMemberCount();
        }
    }

    /**
     * Pure Funktion (keine DB): Diff + Hard-Delete-Guard.
     * $force uebersteuert NUR den Schwellen-Guard ('threshold');
     * 'empty_soll' ist nie uebersteuerbar (leere Soll-Menge wischt nie die Liste).
     *
     * @param int[]              $soll gewuenschte contact_ids (wird dedupliziert)
     * @param array<int, string> $ist  contact_id => status ALLER Member-Zeilen
     */
    public static function computeDiff(array $soll, array $ist, bool $force = false): DiffResult
    {
        $sollIds = array_values(array_unique(array_map('intval', $soll)));

        $toAdd = [];
        $toNormalize = [];
        $unchanged = 0;

        foreach ($sollIds as $id) {
            if (!array_key_exists($id, $ist)) {
                $toAdd[] = $id;
            } elseif ($ist[$id] !== 'subscribed') {
                $toNormalize[] = $id;
            } else {
                $unchanged++;
            }
        }

        $toRemove = array_values(array_diff(array_map('intval', array_keys($ist)), $sollIds));

        $guardTripped = false;
        $guardReason = null;

        if ($sollIds === [] && $ist !== []) {
            $guardTripped = true;
            $guardReason = 'empty_soll';
        } elseif (count($toRemove) > self::GUARD_MAX_REMOVALS
            || ($ist !== [] && count($toRemove) > self::GUARD_MAX_REMOVAL_RATIO * count($ist))) {
            $guardTripped = !$force;
            $guardReason = $guardTripped ? 'threshold' : null;
        }

        return new DiffResult(
            toAdd: $toAdd,
            toNormalize: $toNormalize,
            toRemove: $toRemove,
            unchanged: $unchanged,
            guardTripped: $guardTripped,
            guardReason: $guardReason,
        );
    }

    /**
     * Dry-Run inkl. Guard-Begruendung fuers Panel. Der SyncReport bleibt der
     * festgenagelte 9-Felder-Vertrag und traegt selbst keinen guardReason.
     *
     * @return array{report: EmployeeContactListSyncReport, guard_reason: ?string}
     */
    public function preview(int $teamId): array
    {
        [$list, , $error] = $this->resolveList($teamId);
        if ($error !== null) {
            return ['report' => self::emptyReport(true, $error), 'guard_reason' => null];
        }

        $resolved = $this->resolveDesired($teamId);
        $diff = self::computeDiff($resolved['contact_ids'], $this->currentMembers($list));

        return [
            'report' => self::reportFrom($diff, $resolved['counters'], true),
            'guard_reason' => $diff->guardReason,
        ];
    }

    /**
     * Baut den festgenagelten 9-Felder-Report aus DiffResult + Resolver-Zaehlern.
     */
    private static function reportFrom(DiffResult $diff, array $counters, bool $dryRun): EmployeeContactListSyncReport
    {
        return new EmployeeContactListSyncReport(
            added: count($diff->toAdd),
            removed: count($diff->toRemove),
            normalized: count($diff->toNormalize),
            unchanged: $diff->unchanged,
            skipped_without_contact: (int) ($counters['skipped_without_contact'] ?? 0),
            hidden_from_carddav: (int) ($counters['hidden_from_carddav'] ?? 0),
            ambiguous_multi_link: (int) ($counters['ambiguous_multi_link'] ?? 0),
            dry_run: $dryRun,
            status: $diff->guardTripped ? 'guard_tripped' : 'ok',
        );
    }

    /**
     * Einzige Stelle mit Link-Priorisierung. Auslieferbar (CardDAV) =
     * contact.is_active UND owned_by_user_id IS NULL; Tie-Break kleinste contact_id.
     *
     * @param  int[]|null  $restrictToContactIds  nur MA betrachten, die auf diese
     *                     Kontakte verlinken (Observer-Pfad); null = alle aktiven MA.
     * @return array{contact_ids: int[], counters: array{skipped_without_contact: int, hidden_from_carddav: int, ambiguous_multi_link: int}}
     */
    private function resolveDesired(int $teamId, ?array $restrictToContactIds = null): array
    {
        $query = RecEmployee::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->with('crmContactLinks.contact');

        if ($restrictToContactIds !== null) {
            $query->whereHas('crmContactLinks', fn ($q) => $q->whereIn('contact_id', $restrictToContactIds));
        }

        $counters = ['skipped_without_contact' => 0, 'hidden_from_carddav' => 0, 'ambiguous_multi_link' => 0];
        $desired = [];

        foreach ($query->get() as $employee) {
            $links = $employee->crmContactLinks;

            if ($links->isEmpty()) {
                $counters['skipped_without_contact']++;
                continue;
            }

            if ($links->count() > 1) {
                $counters['ambiguous_multi_link']++;
            }

            $deliverable = $links
                ->filter(fn ($link) => $link->contact
                    && $link->contact->is_active
                    && $link->contact->owned_by_user_id === null)
                ->sortBy('contact_id');

            if ($deliverable->isEmpty()) {
                $counters['hidden_from_carddav']++;
                continue;
            }

            $desired[(int) $deliverable->first()->contact_id] = true;
        }

        return ['contact_ids' => array_keys($desired), 'counters' => $counters];
    }

    /**
     * @return array{0: ?CrmContactList, 1: RecApplicantSettings, 2: ?string}
     */
    private function resolveList(int $teamId): array
    {
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $listId = $settings->getSetting(self::SETTING_LIST_ID);

        if (!$listId) {
            return [null, $settings, 'not_configured'];
        }

        $list = CrmContactList::query()
            ->where('id', (int) $listId)
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->first();

        return [$list, $settings, $list ? null : 'list_missing'];
    }

    /**
     * ALLE Member-Zeilen (statusunabhaengig): globalUnsubscribe() kann Zeilen von
     * aussen auf 'unsubscribed' setzen, CardDAV liefert sie trotzdem aus.
     *
     * @return array<int, string> contact_id => status
     */
    private function currentMembers(CrmContactList $list): array
    {
        return CrmContactListMember::query()
            ->where('contact_list_id', $list->id)
            ->pluck('status', 'contact_id')
            ->all();
    }

    private static function emptyReport(bool $dryRun, string $status): EmployeeContactListSyncReport
    {
        return new EmployeeContactListSyncReport(0, 0, 0, 0, 0, 0, 0, $dryRun, $status);
    }
}
