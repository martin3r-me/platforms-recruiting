<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Platform\Core\Models\ContextFile;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\EmployeeFileSlots;

/**
 * "Dokument anzeigen" im HR-Backend: liefert das Dokument einer
 * RecEmployee-Dateispalte aus, indem auf die frisch signierte Core-URL
 * redirected wird (Streaming + Content-Disposition inline macht der
 * core.context-files.show-Endpoint).
 *
 * Warum Redirect statt signierter URL direkt im Blade: die Signatur
 * entsteht erst beim Klick — kein 60-min-TTL-Problem bei lange
 * offenen Tabs, und die URL im HTML bleibt stabil.
 *
 * Zugriffsschutz: Session-Auth (Route-Middleware wie alle HR-Seiten)
 * + Team-Scope + Spalten-Whitelist (EmployeeFileSlots). Die file_id
 * kommt ausschliesslich aus der MA-Spalte — kein frei waehlbarer
 * Parameter.
 */
class EmployeeFileController extends Controller
{
    public function __invoke(int $employee, string $slot): RedirectResponse
    {
        abort_unless(EmployeeFileSlots::isAllowed($slot), 404);

        $emp = RecEmployee::query()
            ->where('team_id', auth()->user()->currentTeam->id)
            ->findOrFail($employee);

        $fileId = $emp->getAttribute($slot);
        abort_unless((bool) $fileId, 404);

        $file = ContextFile::findOrFail((int) $fileId);

        return redirect($file->url);
    }
}
