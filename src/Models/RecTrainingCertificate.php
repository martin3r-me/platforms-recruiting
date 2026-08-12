<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

/**
 * Ein ausgestelltes Schulungszertifikat.
 *
 * Bewusst KEIN RecContract: eine Contract-Zeile wuerde hasAnyContractSent()
 * wahr machen (worauf die Versand-Guards des Nicht-EU-Umbaus aufsetzen) und in
 * Portal-, Employees-Show- und ZAS-Vertragslisten auftauchen.
 *
 * Und bewusst KEINE Vorlagen-Referenz: der Inhalt steht als festes HTML in
 * Support/TrainingCertificateContent, nicht als Zeile in
 * rec_contract_templates. Die Dedup-Dimension, die vorher die Vorlagen-ID war,
 * traegt jetzt `kind` — ein Zertifikat pro Person pro Schulungsart. Auf
 * unique(rec_applicant_id) allein runterzugehen waere der naheliegende Reflex
 * und wuerde die zweite Schulungsart verbauen; sie soll ein Deploy mit einem
 * zweiten HTML-Block kosten, keinen Schemawechsel. Vollstaendige Begruendung im
 * Docblock der Migration 2026_08_12_000002.
 *
 * `kind` kommt aus einer Konstante dieser Klasse, nicht aus einem Formular:
 * die Spalte ist NOT NULL ohne Default, damit keine Zeile ohne Art entsteht.
 *
 * personalized_content ist der Snapshot des fertigen Inhalts. Er ist NICHT
 * redundant, obwohl der Text fest ist: er haelt die drei variablen Werte zum
 * Zeitpunkt der Ausstellung fest. Ohne ihn wuerde bei jedem Download neu
 * aufgeloest, und ein im August ausgestelltes Zertifikat zeigte im Dezember ein
 * anderes Ausstellungsdatum und womoeglich einen anderen Schulungsleiter
 * (Interviewer koennen an einer Buchung nachgetragen werden).
 * Die Huelle (Layout, Schrift, Bilder) steckt NICHT darin — sie wird beim
 * Rendern aufgeloest, wie der Firmenstempel bei Vertraegen. Sonst lagen ~550 KB
 * Base64 pro ausgestelltem Zertifikat in der Spalte.
 */
class RecTrainingCertificate extends Model
{
    /** Die eine ausgelieferte Schulungsart. Wert wandert in die Spalte `kind`. */
    public const KIND_SERVICE_BASIS = 'service-basis';

    protected $table = 'rec_training_certificates';

    protected $fillable = [
        'uuid',
        'team_id',
        'rec_applicant_id',
        'kind',
        'personalized_content',
        'issued_at',
        'issued_by_user_id',
        'wa_sent_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'wa_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                do {
                    $uuid = UuidV7::generate();
                } while (self::where('uuid', $uuid)->exists());
                $model->uuid = $uuid;
            }
        });
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }
}
