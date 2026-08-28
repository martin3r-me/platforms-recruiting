<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecDispoAttachment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoAttachmentAccess;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

/**
 * Oeffentlicher Anhang-Download von der Einsatz-Seite (Runde 3, #8): token-only
 * wie die Seite selbst, Ownership ueber die Dispo-Identitaets-Gruppe (alle
 * Datensaetze der Person, Spec 2026-08-28), Portalsperre -> 403.
 * Entscheidung in DispoAttachmentAccess (pur, getestet).
 */
class DispoAttachmentController extends Controller
{
    public function __invoke(string $token, string $uuid)
    {
        $employee = RecEmployee::query()->where('portal_token', $token)->first();
        $ids = $employee ? app(DispoIdentityResolver::class)->groupFor((int) $employee->id) : [];

        $attachment = $employee
            ? RecDispoAttachment::query()->where('uuid', $uuid)->whereIn('rec_employee_id', $ids)->first()
            : null;

        // Gesperrt, wenn irgendein Datensatz der Gruppe gesperrt ist.
        $portalLocked = $employee !== null
            && RecEmployee::query()->whereIn('id', $ids)->whereNotNull('portal_locked_at')->exists();

        $decision = DispoAttachmentAccess::decide(
            $employee !== null,
            $portalLocked,
            $attachment !== null
        );
        abort_if($decision !== 200, $decision);

        return Storage::disk($attachment->disk)->response(
            $attachment->stored_path,
            $attachment->original_filename,
            ['Cache-Control' => 'private, no-store']
        );
    }
}
