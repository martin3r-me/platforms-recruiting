<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Verlauf der HR-Handlungen an Konto, Sperre und Erinnerung (Spec HR-Bereich
 * §7.1). NUR ANFUEGEN: geschrieben wird ausschliesslich ueber anfuegen()
 * (Query Builder). Eloquent-Instanzen werfen bei save()/delete() (booted()).
 * Query-Builder-/DB-Wege (DB::table(...)->update/delete) umgehen Modell-Events,
 * sind nicht vorgesehen und duerfen in diesem Modul nicht geschrieben werden;
 * das sichert HrVermerkNurAnfuegenQuellTest. Das Modell existiert zum Lesen;
 * $guarded = ['*'] schliesst Massenzuweisung aus.
 */
class RecHrVermerk extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'rec_hr_vermerke';

    protected $guarded = ['*'];

    protected $casts = [
        'ruhe_bis'   => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Echtes "nur anfuegen": auch save()/delete() an einer geladenen Zeile scheitern.
        static::updating(function () {
            throw new \LogicException('HR-Vermerke sind nur anfuegbar und lassen sich nicht aendern.');
        });
        static::deleting(function () {
            throw new \LogicException('HR-Vermerke sind nur anfuegbar und lassen sich nicht loeschen.');
        });
    }

    /** @var list<string> */
    public const ARTEN = [
        'antrag_gestoppt', 'antrag_freigegeben', 'konto_entsperrt', 'portal_entsperrt', 'bremse_geloest',
        'notfallsperre_aufgehoben', 'einladung_neu', 'konto_zurueckgesetzt', 'kontaktiert', 'erneut_erinnert',
    ];

    /** @var array<string,string> */
    public const ART_LABELS = [
        'antrag_gestoppt'          => 'Antrag gestoppt',
        'antrag_freigegeben'       => 'Antrag freigegeben',
        'konto_entsperrt'          => 'Konto entsperrt',
        'portal_entsperrt'         => 'Portal entsperrt',
        'bremse_geloest'           => 'Anmelde-Bremse gelöst',
        'notfallsperre_aufgehoben' => 'Notfallsperre aufgehoben',
        'einladung_neu'            => 'Einladung neu',
        'konto_zurueckgesetzt'     => 'Konto zurückgesetzt',
        'kontaktiert'              => 'Kontaktiert',
        'erneut_erinnert'          => 'Erneut erinnert',
    ];

    public static function anfuegen(
        int $teamId,
        ?int $personId,
        ?int $employeeId,
        string $art,
        ?string $notiz = null,
        ?string $bezug = null,
        ?string $ergebnis = null,
        ?\DateTimeInterface $ruheBis = null,
        ?int $userId = null,
    ): int {
        if (!in_array($art, self::ARTEN, true)) {
            throw new InvalidArgumentException("Unbekannte Vermerk-Art \"{$art}\".");
        }
        if ($personId === null && $employeeId === null) {
            throw new InvalidArgumentException('Ein Vermerk braucht eine Person oder eine Akte.');
        }
        $notiz = $notiz === null ? null : trim($notiz);
        if (($notiz === null || $notiz === '') && $art !== 'erneut_erinnert') {
            throw new InvalidArgumentException("Vermerk \"{$art}\" ohne Notiz — die Notiz ist Pflicht (Spec §7.1).");
        }

        return (int) DB::table('rec_hr_vermerke')->insertGetId([
            'team_id'         => $teamId,
            'rec_person_id'   => $personId,
            'rec_employee_id' => $employeeId,
            'art'             => $art,
            'bezug'           => $bezug,
            'notiz'           => $notiz === '' ? null : $notiz,
            'ergebnis'        => $ergebnis,
            'ruhe_bis'        => $ruheBis?->format('Y-m-d H:i:s'),
            'user_id'         => $userId,
            'created_at'      => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Die letzten Vermerke eines Menschen — an der Person ODER an einer seiner Akten.
     *
     * @param  list<int>  $employeeIds
     */
    public static function letzte(int $teamId, ?int $personId, array $employeeIds, int $anzahl = 10): Collection
    {
        if ($personId === null && $employeeIds === []) {
            return collect();
        }

        return DB::table('rec_hr_vermerke as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.user_id')
            ->where('v.team_id', $teamId)
            ->where(function ($q) use ($personId, $employeeIds) {
                if ($personId !== null) {
                    $q->orWhere('v.rec_person_id', $personId);
                }
                if ($employeeIds !== []) {
                    $q->orWhereIn('v.rec_employee_id', $employeeIds);
                }
            })
            ->orderByDesc('v.created_at')
            ->orderByDesc('v.id')
            ->limit($anzahl)
            ->get(['v.art', 'v.bezug', 'v.notiz', 'v.ergebnis', 'v.ruhe_bis', 'v.created_at', 'u.name as benutzer']);
    }
}
