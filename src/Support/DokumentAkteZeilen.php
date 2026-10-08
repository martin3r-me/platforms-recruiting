<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecDocumentRecipient;

/**
 * Die Zeilen der HR-Sicht (Akte und Seite Dokumente, Spec §3.2, §8): Status,
 * Benachrichtigungsstand, was HR damit darf. Rein — die Abfrage liegt beim
 * Aufrufer, hier nur die Form.
 */
final class DokumentAkteZeilen
{
    /**
     * @param  list<RecDocumentRecipient> $zustellungen mit geladener Relation document
     * @return list<array{recipient_id:int, document_id:int, document_uuid:string, recipient_uuid:string, employee_id:int, title:string, category_label:string, action:string, action_label:string, status:string, status_label:string, benachrichtigt:?string, fehler:?string, versand_laeuft:bool, versand_text:string, signed_at:?string, kann_zurueckziehen:bool, hat_nachweis:bool, provided_at:string}>
     */
    public static function fuer(array $zustellungen): array
    {
        $out = [];
        foreach ($zustellungen as $z) {
            $d = $z->document;
            if ($d === null) {
                continue;
            }
            $aktion = (string) $d->action;
            $status = DokumentStatus::fuer($z->zeitstempel(), $aktion);
            // Versandstand in Worten — eine Stelle fuer Akte und Seite Dokumente.
            $versandLaeuft = DokumentKategorie::brauchtHandlung($aktion)
                && $z->withdrawn_at === null && $z->notified_at === null && $z->notify_error === null;
            $versandText = match (true) {
                !DokumentKategorie::brauchtHandlung($aktion) => 'ohne Nachricht',
                $z->notified_at !== null                     => 'WhatsApp ' . $z->notified_at->format('d.m. H:i'),
                $z->notify_error !== null                    => 'nicht erreicht (' . $z->notify_error . ')',
                $z->withdrawn_at !== null                    => 'nicht gesendet',
                default                                      => 'wird verschickt …',
            };
            $out[] = [
                'recipient_id'       => (int) $z->id,
                'document_id'        => (int) $d->id,
                'document_uuid'      => (string) $d->uuid,
                'recipient_uuid'     => (string) $z->uuid,
                'employee_id'        => (int) $z->rec_employee_id,
                'title'              => (string) $d->title,
                'category_label'     => DokumentKategorie::label((string) $d->category),
                'action'             => $aktion,
                'action_label'       => DokumentKategorie::aktionLabel($aktion),
                'status'             => $status,
                'status_label'       => DokumentStatus::label($status),
                'benachrichtigt'     => $z->notified_at?->format('Y-m-d H:i:s'),
                'fehler'             => $z->notify_error,
                'versand_laeuft'     => $versandLaeuft,
                'versand_text'       => $versandText,
                'signed_at'          => $z->signed_at?->format('Y-m-d H:i:s'),
                'kann_zurueckziehen' => $z->signed_at === null && $z->withdrawn_at === null,
                'hat_nachweis'       => $z->signed_at !== null,
                'provided_at'        => $z->created_at?->format('Y-m-d H:i:s') ?? '',
            ];
        }

        return $out;
    }
}
