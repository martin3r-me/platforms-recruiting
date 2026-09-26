<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Testmitarbeiter fuer die Demo-Umgebung — damit das neue Portal ohne echte
 * Menschen vorgefuehrt und geprueft werden kann.
 *
 * Bewusste Entscheidungen:
 *
 *  - KEINE Telefonnummern. Wer eine braucht, traegt sie von Hand ein. Eine
 *    erfundene Nummer kann eine echte sein, und dann geht eine WhatsApp an
 *    einen Fremden.
 *  - Feste, sprechende Portal-Token (demo-alles-gut, demo-abgelaufen, ...).
 *    Man muss nichts nachschlagen, die Adresse steht in der Ausgabe.
 *  - Geschrieben wird ueber den Query Builder, nicht ueber Eloquent. Sonst
 *    setzt RecEmployeeExportObserver bei jeder Anlage zas_changed_at und die
 *    erfundenen Leute stehen in der naechsten updates.csv fuer ZAS
 *    (derselbe Mechanismus wie beim Vorfall am 02.09.2026).
 *  - Laeuft NICHT gegen die Produktion. Der Wirt wird geprueft, es gibt
 *    keinen Schalter, der das uebergeht.
 *
 * Aufruf:
 *   php artisan recruiting:demo-mitarbeiter --team=1
 *   php artisan recruiting:demo-mitarbeiter --team=1 --loeschen
 */
class SeedDemoEmployees extends Command
{
    protected $signature = 'recruiting:demo-mitarbeiter
        {--team= : Team-ID (Vorgabe: das kleinste Team mit Mitarbeitern, sonst das kleinste ueberhaupt)}
        {--loeschen : Die angelegten Testmitarbeiter samt Nachweisen wieder entfernen}';

    protected $description = 'Testmitarbeiter fuer die Demo anlegen (keine echten Daten, keine Telefonnummern)';

    /** Am Praefix der Personalnummer erkennt das Loeschen seine eigenen Leute wieder. */
    private const PRAEFIX = 'DEMO-';

    /** Fuer alle gleich, damit man sich beim Vorfuehren nur eines merken muss. */
    private const GEBURTSDATUM = '1990-05-17';
    private const AUSWEIS = 'L01X00T4711';   // die letzten vier: 4711

    public function handle(): int
    {
        if (!$this->darfHier()) {
            return self::FAILURE;
        }

        $teamId = $this->teamId();
        if ($teamId === null) {
            $this->error('Kein Team gefunden — bitte --team= angeben.');

            return self::FAILURE;
        }

        if ($this->option('loeschen')) {
            return $this->loeschen($teamId);
        }

        return $this->anlegen($teamId);
    }

    /**
     * Die Produktion ist tabu. Kein --force, kein --ich-weiss-was-ich-tue:
     * erfundene Mitarbeiter im echten Bestand waeren ueber den ZAS-Export
     * und die Dispo sofort unterwegs, und niemand koennte sie sauber
     * herausloesen.
     */
    private function darfHier(): bool
    {
        $url = (string) config('app.url');

        // Zweiter Riegel (Schlussfix F7): die UMGEBUNG zaehlt mit, nicht nur
        // der Markenname im Wirt. app()->environment() kann in einem
        // Kommando fehlen (kein gebootetes Laravel im Test) -- dann bleibt
        // es bei der Namenspruefung.
        $umgebung = null;
        try {
            $umgebung = (string) app()->environment();
        } catch (\Throwable) {
            $umgebung = null;
        }

        if (self::istProduktion($url, $umgebung)) {
            $wirt = (string) parse_url($url, PHP_URL_HOST);
            $this->error("Nicht auf der Produktion ({$wirt}). Dieses Kommando legt erfundene Menschen an.");

            return false;
        }

        return true;
    }

    /**
     * Die eigentliche Regel — herausgeloest, damit sie geprueft werden kann.
     * Im Zweifel NEIN: eine unlesbare oder leere Adresse gilt als Produktion.
     *
     * ZWEI RIEGEL (Schlussfix F7, 26.09.2026). Der Wirt-Vergleich allein hing
     * an einem MARKENNAMEN: er greift heute (die Produktion laeuft unter
     * mitarbeiter.rheingedeck.de), aber er faellt lautlos aus, sobald eine
     * Produktion unter einer anderen Adresse steht — etwa bei einem zweiten
     * Kunden oder nach einem Umzug. Die UMGEBUNG ist die Eigenschaft, die
     * wirklich gemeint ist. Der Namensvergleich BLEIBT daneben: er faengt
     * den umgekehrten Fall, eine Produktionsadresse mit falsch gesetztem
     * APP_ENV.
     *
     * $umgebung = null heisst "nicht feststellbar" und oeffnet nichts: dann
     * entscheidet weiter der Wirt allein.
     */
    public static function istProduktion(?string $url, ?string $umgebung = null): bool
    {
        if (strtolower(trim((string) $umgebung)) === 'production') {
            return true;
        }

        $wirt = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        if ($wirt === '') {
            return true;
        }

        return str_contains($wirt, 'rheingedeck');
    }

    private function teamId(): ?int
    {
        if ($this->option('team') !== null) {
            return (int) $this->option('team');
        }

        return DB::table('rec_employees')->min('team_id')
            ?? DB::table('teams')->min('id');
    }

    /**
     * Die Faelle sind so gewaehlt, dass jede Farbe der Aufgabenliste einmal
     * vorkommt und die beiden Sonderfaelle (Nicht-EU, zwei Gesellschaften)
     * sichtbar werden.
     *
     * @return list<array{token:string, vorname:string, nachname:string, was:string, spalten:array, nachweise:list<array>}>
     */
    private function faelle(): array
    {
        $heute = now();

        return [
            [
                'token' => 'demo-alles-gut', 'vorname' => 'Alina', 'nachname' => 'Vollstaendig',
                'was' => 'Alles da — die Aufgabenliste ist leer',
                'spalten' => ['is_eu_citizen' => 1, 'company' => 'RG'],
                'nachweise' => [
                    ['ausweis', $heute->copy()->addYears(4)->toDateString()],
                    ['selfie', null],
                    ['krankenkasse', null],
                ],
            ],
            [
                'token' => 'demo-fehlt-was', 'vorname' => 'Bernd', 'nachname' => 'Luecke',
                'was' => 'Ausweis fehlt → rot „Fehlt noch"',
                'spalten' => ['is_eu_citizen' => 1, 'company' => 'RG'],
                'nachweise' => [
                    ['selfie', null],
                ],
            ],
            [
                'token' => 'demo-abgelaufen', 'vorname' => 'Carla', 'nachname' => 'Abgelaufen',
                'was' => 'Ausweis seit einem Monat abgelaufen → rot mit Datum',
                'spalten' => ['is_eu_citizen' => 1, 'company' => 'RG'],
                'nachweise' => [
                    ['ausweis', $heute->copy()->subMonth()->toDateString()],
                    ['selfie', null],
                ],
            ],
            [
                'token' => 'demo-laeuft-ab', 'vorname' => 'Dario', 'nachname' => 'Baldweg',
                'was' => 'Ausweis laeuft in 20 Tagen ab (Vorlauf 30) → gelb',
                'spalten' => ['is_eu_citizen' => 1, 'company' => 'RG'],
                'nachweise' => [
                    ['ausweis', $heute->copy()->addDays(20)->toDateString()],
                    ['selfie', null],
                ],
            ],
            [
                'token' => 'demo-nicht-eu', 'vorname' => 'Emeka', 'nachname' => 'Drittstaat',
                'was' => 'Nicht-EU → Aufenthaltstitel und Arbeitsgenehmigung werden zusaetzlich verlangt',
                'spalten' => ['is_eu_citizen' => 0, 'company' => 'RG', 'nationality' => 'NG'],
                'nachweise' => [
                    ['ausweis', $heute->copy()->addYears(3)->toDateString()],
                ],
            ],
            [
                'token' => 'demo-studentin', 'vorname' => 'Fiona', 'nachname' => 'Immatrikuliert',
                'was' => 'Studentin → Immatrikulationsbescheinigung wird zusaetzlich verlangt',
                'spalten' => ['is_eu_citizen' => 1, 'company' => 'RG', 'employment_type' => 'student'],
                'nachweise' => [
                    ['ausweis', $heute->copy()->addYears(6)->toDateString()],
                    ['selfie', null],
                ],
            ],
            [
                'token' => 'demo-zwei-firmen', 'vorname' => 'Gregor', 'nachname' => 'Doppelt',
                'was' => 'Dieselbe Person bei RG UND MA — Profil zeigt beide, Nachweise gelten fuer beide',
                'spalten' => ['is_eu_citizen' => 1, 'company' => 'RG', 'person_key' => 'demo-person-gregor'],
                'nachweise' => [
                    ['ausweis', $heute->copy()->addYears(2)->toDateString()],
                ],
            ],
            [
                'token' => 'demo-zwei-firmen-ma', 'vorname' => 'Gregor', 'nachname' => 'Doppelt',
                'was' => 'Die zweite Anstellung derselben Person — hier haengt KEIN Nachweis, er muss trotzdem erscheinen',
                'spalten' => ['is_eu_citizen' => 1, 'company' => 'MA', 'person_key' => 'demo-person-gregor'],
                'nachweise' => [],
            ],
        ];
    }

    private function anlegen(int $teamId): int
    {
        $jetzt = now();
        $zeilen = [];
        $nummer = 0;

        foreach ($this->faelle() as $fall) {
            $nummer++;

            $vorhanden = DB::table('rec_employees')->where('portal_token', $fall['token'])->first();
            if ($vorhanden !== null) {
                $this->line("  uebersprungen: {$fall['token']} gibt es schon (#{$vorhanden->id})");
                $employeeId = (int) $vorhanden->id;
            } else {
                $employeeId = (int) DB::table('rec_employees')->insertGetId(array_merge([
                    'uuid'                  => (string) Str::uuid(),
                    'team_id'               => $teamId,
                    'first_name'            => $fall['vorname'],
                    'last_name'             => $fall['nachname'],
                    'birth_date'            => self::GEBURTSDATUM,
                    'identity_card_number'  => self::AUSWEIS,
                    'email'                 => strtolower($fall['vorname']) . '@example.invalid',
                    'phone'                 => null,   // bewusst leer, siehe Klassenkommentar
                    'personnel_number'      => self::PRAEFIX . str_pad((string) $nummer, 2, '0', STR_PAD_LEFT),
                    'portal_token'          => $fall['token'],
                    'portal_v2_since'       => $jetzt,
                    'is_active'             => 1,
                    'created_at'            => $jetzt,
                    'updated_at'            => $jetzt,
                ], $fall['spalten']));
            }

            foreach ($fall['nachweise'] as [$code, $gueltigBis]) {
                $schonDa = DB::table('rec_employee_proofs')
                    ->where('rec_employee_id', $employeeId)
                    ->where('proof_type_code', $code)
                    ->whereNull('superseded_at')
                    ->exists();
                if ($schonDa) {
                    continue;
                }

                DB::table('rec_employee_proofs')->insert([
                    'uuid'            => (string) Str::uuid(),
                    'team_id'         => $teamId,
                    'rec_employee_id' => $employeeId,
                    'person_key'      => $fall['spalten']['person_key'] ?? null,
                    'proof_type_code' => $code,
                    'file_id'         => null,   // es gibt noch keine echte Datei — die Huelle zeigt nur den Stand
                    'valid_until'     => $gueltigBis,
                    'version'         => 1,
                    'uploaded_via'    => 'import',
                    'created_at'      => $jetzt,
                    'updated_at'      => $jetzt,
                ]);
            }

            $zeilen[] = [
                '#' . $employeeId,
                $fall['vorname'] . ' ' . $fall['nachname'],
                // Ueber route(), nicht von Hand zusammengesetzt — sonst laeuft
                // diese Ausgabe wieder auseinander, sobald sich die Route
                // aendert (Fixrunde 1, Aufgabe 4: Token muss am URL-ENDE
                // stehen, Meta-URL-Buttons erlauben die Variable nur als
                // Suffix).
                route('recruiting.public.portal-shell', ['token' => $fall['token']]),
                $fall['was'],
            ];
        }

        $this->info('Testmitarbeiter stehen. Anmeldung bei allen gleich:');
        $this->line('  Geburtsdatum  ' . self::GEBURTSDATUM . '   (17.05.1990)');
        $this->line('  Ausweis-Endziffern  4711');
        $this->newLine();
        $this->table(['MA', 'Name', 'Adresse', 'Wozu'], $zeilen);
        $this->newLine();
        $this->comment('Wieder weg mit: php artisan recruiting:demo-mitarbeiter --loeschen');

        return self::SUCCESS;
    }

    private function loeschen(int $teamId): int
    {
        $ids = DB::table('rec_employees')
            ->where('team_id', $teamId)
            ->where('personnel_number', 'like', self::PRAEFIX . '%')
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('Nichts zu loeschen.');

            return self::SUCCESS;
        }

        $nachweise = DB::table('rec_employee_proofs')->whereIn('rec_employee_id', $ids)->delete();
        $leute = DB::table('rec_employees')->whereIn('id', $ids)->delete();

        $this->info("{$leute} Testmitarbeiter und {$nachweise} Nachweise entfernt.");

        return self::SUCCESS;
    }
}
