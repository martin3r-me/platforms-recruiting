<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Zeigt die Legacy-Spalte eines Threads auf einen Bewerber oder Mitarbeiter,
 * den es nicht mehr gibt? Dann findet die Kommunikation keinen Namen (nur die
 * Nummer), und "first context wins" liess den Chat nie umhaengen
 * (Fall Markus 30.10.2026: Bewerber #1504 geloescht, Chat blieb dort).
 * Fremde Kontexte (HCM, Helpdesk …) gelten nie als tot — die prueft hier niemand.
 */
final class ToteVerknuepfung
{
    public static function ist(?string $contextModel, $contextModelId): bool
    {
        if ($contextModel === null || $contextModel === '' || (int) $contextModelId <= 0) {
            return false;
        }

        $applicant = new RecApplicant;
        if ($contextModel === $applicant->getMorphClass() || $contextModel === RecApplicant::class) {
            return !RecApplicant::query()->withoutGlobalScopes()->whereKey((int) $contextModelId)->exists();
        }
        if ($contextModel === RecEmployee::class) {
            return !RecEmployee::query()->withoutGlobalScopes()->whereKey((int) $contextModelId)->exists();
        }

        return false;
    }

    /**
     * Bestand heilen (recruiting:tote-chat-verknuepfungen): jeder Thread des
     * Teams mit toter Legacy-Verknuepfung bekommt den juengsten LEBENDEN
     * Bewerber-/Mitarbeiter-Kontext aus seinem Pivot. Ohne lebenden Ersatz
     * bleibt er stehen (nur gezaehlt) — raten waere schlimmer als die Nummer.
     *
     * @return array{tot: int, umgehaengt: list<array{thread: int, von: string, nach: string}>, ohne_ersatz: list<int>}
     */
    public static function heilen(int $teamId, bool $schreiben): array
    {
        $applicantMorph = (new RecApplicant)->getMorphClass();
        $modelle = array_values(array_unique([$applicantMorph, RecApplicant::class, RecEmployee::class]));

        $threads = CommsWhatsAppThread::query()
            ->where('team_id', $teamId)
            ->whereIn('context_model', $modelle)
            ->whereNotNull('context_model_id')
            ->with('contexts')
            ->orderBy('id')
            ->get();

        $out = ['tot' => 0, 'umgehaengt' => [], 'ohne_ersatz' => []];
        foreach ($threads as $thread) {
            if (!self::ist($thread->context_model, $thread->context_model_id)) {
                continue;
            }
            $out['tot']++;

            $ersatz = $thread->contexts
                ->filter(fn ($c) => in_array($c->context_model, $modelle, true)
                    && !self::ist($c->context_model, $c->context_model_id))
                ->sortByDesc('id')
                ->first();

            if ($ersatz === null) {
                $out['ohne_ersatz'][] = (int) $thread->id;
                continue;
            }

            $out['umgehaengt'][] = [
                'thread' => (int) $thread->id,
                'von' => $thread->context_model . '#' . $thread->context_model_id,
                'nach' => $ersatz->context_model . '#' . $ersatz->context_model_id,
            ];
            if ($schreiben) {
                $thread->updateQuietly([
                    'context_model' => $ersatz->context_model,
                    'context_model_id' => (int) $ersatz->context_model_id,
                ]);
            }
        }

        return $out;
    }
}
