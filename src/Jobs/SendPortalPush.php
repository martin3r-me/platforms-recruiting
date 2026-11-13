<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecPushAbo;
use Platform\Recruiting\Services\Push\PushTransport;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

/**
 * Push ans Mitarbeiter-Portal (Spec Jobs anbieten 6.7, Markus 13.11.2026).
 * Je Person alle Abos ihrer Identitaets-Gruppe (Ankerwechsel verliert kein Geraet).
 * Text ohne Kunde, Ort oder Uhrzeit — er steht auf dem Sperrbildschirm.
 * Wirft nie: ein Push-Fehler darf nichts anderes beruehren.
 */
class SendPortalPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ART_ANGEBOTE = 'angebote';
    public const ART_PROBE = 'probe';

    private const TEXTE = [
        self::ART_ANGEBOTE => ['title' => 'Neue Einsätze für dich', 'body' => 'Schau ins Portal und sag zu.'],
        self::ART_PROBE => ['title' => 'Benachrichtigungen sind eingeschaltet', 'body' => 'So melden wir uns bei neuen Einsätzen.'],
    ];

    public int $tries = 1;
    public int $timeout = 600;

    /** @param list<int> $employeeIds */
    public function __construct(
        public readonly array $employeeIds,
        public readonly string $art,
    ) {
    }

    public function handle(PushTransport $transport, DispoIdentityResolver $identitaet): void
    {
        try {
            $this->senden($transport, $identitaet);
        } catch (\Throwable $e) {
            self::log('warning', 'recruiting.portal_push.abgebrochen', ['art' => $this->art, 'fehler' => $e->getMessage()]);
        }
    }

    private function senden(PushTransport $transport, DispoIdentityResolver $identitaet): void
    {
        $text = self::TEXTE[$this->art] ?? null;
        $ids = array_values(array_unique(array_map('intval', $this->employeeIds)));
        if ($text === null || $ids === []) {
            return;
        }

        $personen = [];
        foreach ($identitaet->groupsFor($ids) as $gruppe) {
            array_push($personen, ...$gruppe);
        }
        $abos = RecPushAbo::query()->whereIn('rec_employee_id', array_values(array_unique($personen)))->orderBy('id')->get();
        if ($abos->isEmpty()) {
            return;
        }
        // Link auf den Datensatz, an dem das Abo haengt — dessen Portal hat das Geraet eingeschaltet.
        $tokens = RecEmployee::query()->whereIn('id', $abos->pluck('rec_employee_id')->unique()->all())->pluck('portal_token', 'id');

        $zaehler = ['ok' => 0, 'erloschen' => 0, 'fehler' => 0];
        foreach ($abos as $abo) {
            $token = (string) ($tokens[$abo->rec_employee_id] ?? '');
            if ($token === '') {
                continue;
            }
            $payload = $text + ['url' => '/recruiting/mitarbeiter/neu/' . rawurlencode($token) . '?tab=jobs'];

            try {
                $ergebnis = $transport->senden($abo, $payload);
            } catch (\Throwable $e) {
                $ergebnis = 'fehler';
            }

            try {
                if ($ergebnis === 'ok') {
                    $abo->forceFill(['last_ok_at' => now()])->save();
                } elseif ($ergebnis === 'erloschen') {
                    $abo->delete();
                }
            } catch (\Throwable) {
                // DB-Schluckauf bei einem Abo darf die uebrigen nicht aufhalten.
            }
            $zaehler[$ergebnis] = ($zaehler[$ergebnis] ?? 0) + 1;
        }

        self::log('info', 'recruiting.portal_push.gesendet', ['art' => $this->art, 'personen' => count($ids)] + $zaehler);
    }

    /** Ohne Log-Bindung (handgebauter Container im Test) still. */
    private static function log(string $stufe, string $nachricht, array $kontext): void
    {
        try {
            Log::{$stufe}($nachricht, $kontext);
        } catch (\Throwable) {
        }
    }
}
