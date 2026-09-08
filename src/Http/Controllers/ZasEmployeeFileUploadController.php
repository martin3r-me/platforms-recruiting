<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Platform\Recruiting\Services\Zas\ZasEmployeeFileIntake;

/**
 * Datei-Eingang: ZAS schickt uns eine Mitarbeiter-Datei (Push-Richtung).
 *
 *   POST /recruiting/zas/employee-files/{personalnummer}/{slot}
 *   Authorization: Bearer <RECRUITING_ZAS_TOKEN>
 *
 * Gegenstueck zum Abruf `GET /employee-files/{employeeUuid}/{slot}`. Der
 * Schluessel ist hier die PERSONALNUMMER, nicht die UUID: fuer die
 * Bestands-Mitarbeiter kennt ZAS unsere UUID nicht.
 *
 * Der Inhalt kommt als Raw-Body (so wie ZAS auch die CSV schickt) oder als
 * Multipart-Feld `file`. Der Originalname darf per `?filename=` mitgegeben
 * werden; er dient der Wiederholungserkennung.
 *
 * Entscheidungen und Guards liegen im ZasEmployeeFileIntake — hier nur
 * Request-Auspacken und Antwort.
 */
class ZasEmployeeFileUploadController extends Controller
{
    public function __construct(private ZasEmployeeFileIntake $intake) {}

    public function __invoke(Request $request, string $personnelNumber, string $slot): JsonResponse
    {
        $bytes    = $this->extractContent($request, $originalName);
        $filename = $request->query('filename') ?: $originalName;

        $result = $this->intake->receive(
            $personnelNumber,
            $slot,
            $bytes,
            is_string($filename) ? $filename : null
        );

        return response()->json([
            'status'           => $result['status'],
            'message'          => $result['message'],
            'personnel_number' => $personnelNumber,
            'slot'             => $slot,
            'employee_id'      => $result['employee_id'],
            'file_id'          => $result['file_id'],
            'matched_via'      => $result['matched_via'],
        ], $result['http'])->header('Cache-Control', 'no-store');
    }

    /**
     * Bytes aus Multipart-Feld `file` oder dem Raw-Body. Setzt den
     * Originalnamen per Referenz, falls die Datei einen mitbringt.
     */
    private function extractContent(Request $request, ?string &$originalName): string
    {
        $originalName = null;

        $uploaded = $request->file('file');
        if ($uploaded instanceof UploadedFile && $uploaded->isValid()) {
            $originalName = $uploaded->getClientOriginalName();
            $bytes        = file_get_contents($uploaded->getRealPath());

            return $bytes === false ? '' : $bytes;
        }

        return (string) $request->getContent();
    }
}
