<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecDispoAttachment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoAttachmentAccess;

/**
 * Oeffentlicher Anhang-Download von der Einsatz-Seite (Runde 3, #8): token-only
 * wie die Seite selbst, Ownership ueber rec_employee_id, Portalsperre -> 403.
 * Entscheidung in DispoAttachmentAccess (pur, getestet).
 */
class DispoAttachmentController extends Controller
{
    public function __invoke(string $token, string $uuid)
    {
        $employee = RecEmployee::query()->where('portal_token', $token)->first();
        $attachment = $employee
            ? RecDispoAttachment::query()->where('uuid', $uuid)->where('rec_employee_id', $employee->id)->first()
            : null;

        $decision = DispoAttachmentAccess::decide(
            $employee !== null,
            $employee !== null && $employee->portal_locked_at !== null,
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
