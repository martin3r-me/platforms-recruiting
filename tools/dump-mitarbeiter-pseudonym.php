<?php
/**
 * Pseudonymisierter Mitarbeiter-Dump: Produktion lesen, Demo fuettern.
 * ---------------------------------------------------------------------------
 *
 * WOFUER: Der Sichttest des neuen Portals braucht einen Bestand, der sich
 * anfuehlt wie der echte — 1213 Menschen statt acht Puppen. Echte Daten haben
 * in diesem Projekt schon Dinge gekippt, die kein ausgedachter Datensatz je
 * getroffen haette (die Mitarbeiter-Anlage starb an einem zu langen Feldwert,
 * Fehler 22001, und verschluckte dabei still die Portal-Nachricht).
 *
 * DER TRICK: Was wir testen wollen, haengt an GLEICHHEITEN, nicht an Inhalten.
 * Ob zwei Datensaetze dieselbe Nummer tragen, ob zwei denselben Namen und
 * dasselbe Geburtsdatum haben — das entscheidet ueber Paarung, Kollision und
 * Personen-Zeile. WELCHE Nummer es ist, entscheidet nichts.
 *
 * Deshalb ersetzt dieses Skript EINDEUTIG UMKEHRBAR NICHT, aber EINDEUTIG
 * ZUORDNEND: gleicher Wert rein, gleicher Wert raus; verschiedener Wert rein,
 * verschiedener Wert raus. Die 354 Paare bleiben 354 Paare, der eine Konflikt
 * bleibt ein Konflikt, die fuenf Abweichungen bleiben fuenf.
 *
 * FAIL CLOSED: Spalten werden AUSDRUECKLICH freigegeben. Was das Skript nicht
 * kennt, wird geleert und am Ende gemeldet. Lieber ein Feld zu viel leer als
 * eines zu wenig — eine vergessene Spalte traegt sonst echte Daten mit.
 *
 * TELEFONNUMMERN: Die Ersatznummern liegen im Bereich +49 000 ..., der keiner
 * gueltigen deutschen Vorwahl entspricht. Sie sind nicht waehlbar und niemandem
 * zugeteilt. Selbst wenn auf der Demo scharfe Meta-Zugangsdaten liegen und der
 * Scheduler laeuft, erreicht er niemanden.
 *
 * NICHT MITGENOMMEN: hochgeladene Dateien (Ausweiskopien, Selfies). Die
 * *_file_id-Spalten bleiben erhalten, damit die Nachweis-Logik etwas zu sehen
 * hat; die Dateien selbst liegen nicht auf der Demo. Das ist Absicht — sie
 * bringen null Testwert und sind das Heikelste am ganzen Bestand.
 *
 * ---------------------------------------------------------------------------
 * ZWEI WEGE. Beide erzeugen dasselbe Ergebnis.
 *
 * WEG 1 — AUS EINER CSV, ALLES LOKAL (empfohlen):
 *   Auf der Produktion NICHTS ausfuehren. In TablePlus gegen prod:
 *       SELECT * FROM rec_employees;
 *   Ergebnis als CSV exportieren (mit Kopfzeile), Datei herunterladen. Dann
 *   bei dir auf dem Rechner:
 *
 *       php tools/dump-mitarbeiter-pseudonym.php --csv=rec_employees.csv > demo-mitarbeiter.sql
 *
 *   Kein Skript auf dem Server, keine Datenbankverbindung, kein lokales MySQL.
 *
 *   Eine Eigenart des CSV-Wegs: TablePlus schreibt NULL und leeren Text
 *   gleich. Das Skript macht deshalb aus jedem leeren Feld ein NULL. Fuer
 *   diesen Test ist das folgenlos — der Code behandelt beides ohnehin gleich
 *   (leerer Marker, leere Nummer) — aber es ist ein Unterschied zum Original,
 *   und der gehoert benannt.
 *
 * WEG 2 — DIREKT AUS DER DATENBANK:
 *   Im Verzeichnis der Seite, dort wo eine .env mit den DB_-Zugangsdaten liegt:
 *
 *       php tools/dump-mitarbeiter-pseudonym.php > demo-mitarbeiter.sql
 *
 *   Nur lesende Zugriffe (SHOW COLUMNS, SELECT). Kein INSERT, UPDATE, DELETE.
 *
 * In beiden Faellen: Meldungen gehen nach STDERR, die SQL nach STDOUT —
 * deshalb funktioniert die Umleitung mit >.
 * ---------------------------------------------------------------------------
 */

// Jede PHP-Meldung geht nach STDERR. Ohne das landet schon ein harmloser
// Deprecated-Hinweis mitten in der erzeugten SQL und macht sie unbrauchbar —
// beim ersten Testlauf genau so passiert.
ini_set('display_errors', 'stderr');

const GEBURTSTAG_VERSCHIEBUNG = 137;      // Tage; Gleichheit bleibt, Identitaet geht
const AUSWEIS_EINHEITLICH     = 'L01X00T4711';  // Anmeldung: Geburtsdatum + 4711
const PERSONALNUMMER_PRAEFIX  = 'DUMP-';
const TELEFON_PRAEFIX         = '+49000';

// ---------------------------------------------------------------------------
// Spaltenregeln. Alles, was hier nicht steht, wird GELEERT und gemeldet.
// ---------------------------------------------------------------------------

/** Unveraendert uebernehmen — kein Personenbezug oder fuer den Test noetig. */
const BEHALTEN = [
    'id', 'team_id', 'is_active', 'company', 'employment_type',
    'employment_classification', 'art_der_tatigkeit', 'umfang_der_tatigkeit',
    'is_eu_citizen', 'nationality', 'birth_country',
    'gender', 'marital_status', 'religion', 'number_of_children', 'tax_class',
    'health_insurance', 'is_main_employer',
    'shirt_size', 'pants_size', 'shoe_size', 'has_car', 'drivers_license_class',
    'is_first_aider', 'is_safety_officer',
    'has_infection_protection_certificate',
    // Ablaufdaten: die ECHTE Verteilung ist der halbe Testwert (wie viele sind
    // abgelaufen, wie viele laufen bald ab) und sie ist kein Personenbezug.
    'identity_card_valid_until', 'residence_permit_valid_until',
    'work_permit_valid_until', 'school_certificate_valid_until',
    'first_aider_valid_until', 'infection_protection_valid_until',
    'infection_protection_first_issued_at', 'infection_protection_instructed_at',
    // Beschaeftigungs-Zeitraum
    'employed_since', 'employment_ended_at',
    'contract_sent_date', 'contract_signed_at', 'contract_end_date',
    // Der Personen-Marker ist eine UUID ohne Personenbezug — und die Paarung
    // haengt daran. Ohne ihn waere der ganze Dump fuer den Backfill wertlos.
    'person_key', 'rec_person_id',
    // Portal-Zustand
    'portal_verified_at', 'portal_last_seen_at', 'portal_locked_at',
    'portal_locked_reason', 'portal_v2_since',
    // ZAS-Zustand (keine Namen, nur Marker und Zeitstempel)
    'zas_changed_at', 'zas_initial_exported_at', 'export_status',
    'created_at', 'updated_at',
];

/** Dateiverweise: bleiben stehen, die Dateien selbst kommen nicht mit. */
const DATEI_SPALTEN = [
    'identity_card_front_file_id', 'identity_card_back_file_id',
    'aufenthaltstitel_front_file_id', 'aufenthaltstitel_back_file_id',
    'fiktionsbescheinigung_front_file_id', 'fiktionsbescheinigung_back_file_id',
    'visumsblatt_file_id', 'zusatzblatt_file_id', 'zusatzblatt_back_file_id',
    'nationalpass_file_id', 'selfie_file_id', 'health_insurance_card_file_id',
    'schulbescheinigung_file_id', 'immatrikulation_file_id',
    'erstbescheinigung_file_id', 'first_aider_certificate_file_id',
];

/** Werden ersetzt — die Regel steht jeweils in ersetze(). */
const ERSETZEN = [
    'uuid', 'first_name', 'last_name', 'birth_name', 'birth_date', 'birth_place',
    'phone', 'email', 'street', 'house_number', 'zip', 'city', 'country_code',
    'identity_card_number', 'iban', 'bic', 'bank_institute', 'account_holder',
    'steuer_id', 'sozialversicherungsnummer', 'personnel_number',
    'portal_token', 'other_employer', 'beschaftigungsort', 'cost_center',
    'recruited_by_personnel_number',
];

/** Verweise auf Zeilen, die auf der Demo nicht existieren — auf NULL. */
const LEEREN = [
    'rec_applicant_id', 'rec_position_id', 'rec_zas_inbound_file_id',
    'created_by_user_id', 'zas_id',
    'payroll_data_changed_at', 'payroll_data_changed_fields',
];

// ---------------------------------------------------------------------------

$vornamen = ['Alina','Bernd','Carla','Dario','Emeka','Fiona','Gregor','Hanna','Ibrahim','Jana',
    'Kai','Lena','Marek','Nora','Osman','Pia','Quentin','Rosa','Sven','Tanja','Umut','Vera',
    'Wanda','Xaver','Yasmin','Zoran','Anton','Birte','Cem','Doris','Elias','Frieda','Goran',
    'Heike','Ilja','Jonas','Katrin','Lukas','Mira','Nils','Olga','Paul','Rasmus','Sarah',
    'Timo','Ulrike','Viktor','Wiebke','Yannick','Zeynep'];
$nachnamen = ['Adler','Bergmann','Clemens','Dohmen','Ebert','Faber','Gerlach','Hartmann',
    'Iversen','Jansen','Kaufmann','Lindner','Moser','Neumann','Ostermann','Pfeiffer',
    'Quandt','Reuter','Schuster','Thiele','Urban','Vogel','Wagner','Zimmer','Ahrens',
    'Brandt','Conrad','Dressler','Engels','Fricke','Gross','Hensel','Ibrahim','Jost',
    'Kessler','Lorenz','Mahler','Nagel','Otte','Petersen','Riedel','Sommer','Tietz',
    'Ullrich','Voigt','Weber','Zeller','Ansorge','Bruns','Cordes'];

$karten = ['vorname' => [], 'nachname' => [], 'telefon' => [], 'ort' => []];

/**
 * Eindeutig zuordnende Ersetzung: gleicher Wert rein -> gleicher Wert raus,
 * verschiedener Wert rein -> VERSCHIEDENER Wert raus. Kein Zufall, keine
 * Kollisionen — sonst entstuenden Paare, die es in der Produktion nicht gibt.
 */
function ausPool(string $original, array $pool, array &$karte, string $praefix = ''): string
{
    if (!isset($karte[$original])) {
        $n = count($karte);
        $wort = $pool[$n % count($pool)];
        $runde = intdiv($n, count($pool));
        $karte[$original] = $praefix . $wort . ($runde > 0 ? (string) ($runde + 1) : '');
    }

    return $karte[$original];
}

/**
 * Telefonnummer: die letzten NEUN Ziffern entscheiden ueber alles (PhoneE164::
 * suffix). Also wird genau der Suffix eindeutig zugeordnet — dieselbe Nummer
 * bleibt dieselbe, eine andere bleibt eine andere. Der Praefix +49000 ist
 * keine gueltige Vorwahl; die Nummern sind nicht waehlbar.
 */
function telefon(?string $roh, array &$karte): ?string
{
    $ziffern = preg_replace('/\D+/', '', (string) $roh);
    if ($ziffern === '' || strlen($ziffern) < 9) {
        // Zu kurz oder leer: so lassen, wie es war (leer bleibt leer). Diese
        // Faelle sind selbst ein Testfall ("wer bekommt kein Konto").
        return ($roh === null || trim((string) $roh) === '') ? null : '0' . str_pad((string) strlen($ziffern), 3, '0', STR_PAD_LEFT);
    }

    $suffix = substr($ziffern, -9);
    if (!isset($karte[$suffix])) {
        $karte[$suffix] = str_pad((string) (100000000 + count($karte)), 9, '0', STR_PAD_LEFT);
    }

    return TELEFON_PRAEFIX . $karte[$suffix];
}

// ---------------------------------------------------------------------------
// Verbindung aus der .env der Anwendung — keine Zugangsdaten im Skript.
// ---------------------------------------------------------------------------

function envWert(string $schluessel, string $fallback = ''): string
{
    static $env = null;
    if ($env === null) {
        $env = [];
        $pfad = getcwd() . '/.env';
        if (!is_readable($pfad)) {
            fwrite(STDERR, "FEHLER: {$pfad} nicht lesbar. Das Skript muss im Verzeichnis der Seite laufen.\n");
            exit(1);
        }
        foreach (file($pfad, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $zeile) {
            if (str_starts_with(trim($zeile), '#') || !str_contains($zeile, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $zeile, 2);
            $env[trim($k)] = trim($v, " \t\"'");
        }
    }

    return $env[$schluessel] ?? $fallback;
}

/** Anfuehrungszeichen fuer SQL, ohne dass eine Datenbankverbindung noetig waere. */
function sqlWert(?string $wert): string
{
    if ($wert === null) {
        return 'NULL';
    }

    return "'" . str_replace(
        ['\\', "'", "\n", "\r", "\0", "\x1a"],
        ['\\\\', "''", '\\n', '\\r', '', ''],
        $wert
    ) . "'";
}

$csvPfad = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--csv=')) {
        $csvPfad = substr($arg, 6);
    }
}

if ($csvPfad !== null) {
    // ----- WEG 1: aus der CSV, ohne jede Datenbankverbindung -----
    if (!is_readable($csvPfad)) {
        fwrite(STDERR, "FEHLER: {$csvPfad} nicht lesbar.\n");
        exit(1);
    }
    $griff = fopen($csvPfad, 'r');
    $spalten = fgetcsv($griff, null, ',', '"', '');
    if ($spalten === false || $spalten === [null]) {
        fwrite(STDERR, "FEHLER: {$csvPfad} hat keine Kopfzeile.\n");
        exit(1);
    }
    $spalten = array_map(fn ($s) => trim((string) $s, " \t\"'\xEF\xBB\xBF"), $spalten);

    $zeilen = [];
    while (($satz = fgetcsv($griff, null, ',', '"', '')) !== false) {
        if ($satz === [null] || count($satz) !== count($spalten)) {
            continue;
        }
        // TablePlus schreibt NULL und leeren Text gleich -> beides wird NULL.
        $zeilen[] = array_map(fn ($w) => ($w === '' ? null : $w), array_combine($spalten, $satz));
    }
    fclose($griff);
    fwrite(STDERR, "Quelle: CSV {$csvPfad}\n");
} else {
    // ----- WEG 2: direkt aus der Datenbank, nur lesend -----
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            envWert('DB_HOST', '127.0.0.1'), envWert('DB_PORT', '3306'), envWert('DB_DATABASE')),
        envWert('DB_USERNAME'),
        envWert('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $spalten = $pdo->query('SHOW COLUMNS FROM rec_employees')->fetchAll(PDO::FETCH_COLUMN);
    $zeilen  = $pdo->query('SELECT * FROM rec_employees ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    fwrite(STDERR, "Quelle: Datenbank " . envWert('DB_DATABASE') . "\n");
}

$unbekannt = [];

foreach ($spalten as $spalte) {
    if (!in_array($spalte, BEHALTEN, true)
        && !in_array($spalte, DATEI_SPALTEN, true)
        && !in_array($spalte, ERSETZEN, true)
        && !in_array($spalte, LEEREN, true)) {
        $unbekannt[] = $spalte;
    }
}

if ($unbekannt !== []) {
    fwrite(STDERR, "\n!! UNBEKANNTE SPALTEN — werden GELEERT (fail closed):\n");
    foreach ($unbekannt as $s) {
        fwrite(STDERR, "   - {$s}\n");
    }
    fwrite(STDERR, "   Pruef, ob eine davon fuer den Test gebraucht wird, und trag sie\n");
    fwrite(STDERR, "   dann oben in BEHALTEN oder ERSETZEN ein.\n\n");
}

// ---------------------------------------------------------------------------
// Lesen und umschreiben
// ---------------------------------------------------------------------------

fwrite(STDERR, sprintf("%d Datensaetze gelesen, %d Spalten.\n", count($zeilen), count($spalten)));

echo "-- Pseudonymisierter Mitarbeiter-Dump, erzeugt am " . date('Y-m-d H:i') . "\n";
echo "-- Geburtsdaten sind um " . GEBURTSTAG_VERSCHIEBUNG . " Tage verschoben.\n";
echo "-- Anmeldung im Portal: verschobenes Geburtsdatum + Ausweis-Endziffern 4711\n";
echo "-- Telefonnummern liegen im Bereich " . TELEFON_PRAEFIX . " (nicht waehlbar).\n\n";
echo "SET FOREIGN_KEY_CHECKS = 0;\n";
echo "TRUNCATE TABLE rec_employees;\n\n";

$werte = [];
foreach ($zeilen as $zeile) {
    $neu = [];
    foreach ($spalten as $spalte) {
        $wert = $zeile[$spalte];

        if (in_array($spalte, BEHALTEN, true) || in_array($spalte, DATEI_SPALTEN, true)) {
            $neu[$spalte] = $wert;
            continue;
        }
        if (in_array($spalte, LEEREN, true) || in_array($spalte, $unbekannt, true)) {
            $neu[$spalte] = null;
            continue;
        }

        $neu[$spalte] = ersetze($spalte, $wert, $zeile, $karten, $vornamen, $nachnamen);
    }
    $werte[] = $neu;
}

function ersetze(string $spalte, $wert, array $zeile, array &$karten, array $vornamen, array $nachnamen)
{
    $id = (int) $zeile['id'];

    return match ($spalte) {
        'uuid'          => sprintf('00000000-0000-4000-8000-%012d', $id),
        'first_name'    => $wert === null || trim((string) $wert) === '' ? $wert
                             : ausPool((string) $wert, $vornamen, $karten['vorname']),
        'last_name'     => $wert === null || trim((string) $wert) === '' ? $wert
                             : ausPool((string) $wert, $nachnamen, $karten['nachname']),
        'birth_name'    => $wert === null || trim((string) $wert) === '' ? $wert
                             : ausPool((string) $wert, $nachnamen, $karten['nachname']),
        'birth_date'    => $wert === null ? null
                             : date('Y-m-d', strtotime((string) $wert . ' +' . GEBURTSTAG_VERSCHIEBUNG . ' days')),
        'birth_place'   => $wert === null || trim((string) $wert) === '' ? $wert
                             : ausPool((string) $wert, ['Musterstadt','Beispielheim','Testdorf','Probstadt'], $karten['ort']),
        'phone'         => telefon($wert, $karten['telefon']),
        'email'         => $wert === null || trim((string) $wert) === '' ? $wert : "demo{$id}@example.invalid",
        'street'        => $wert === null ? null : 'Musterweg',
        'house_number'  => $wert === null ? null : (string) (($id % 89) + 1),
        'zip'           => $wert === null ? null : '40000',
        'city'          => $wert === null ? null : 'Musterstadt',
        'country_code'  => $wert,   // DE/PL/... kein Personenbezug
        'identity_card_number'       => $wert === null || trim((string) $wert) === '' ? $wert : AUSWEIS_EINHEITLICH,
        'iban'                       => $wert === null || trim((string) $wert) === '' ? $wert : sprintf('DE00000000000000%06d', $id),
        'bic'                        => $wert === null || trim((string) $wert) === '' ? $wert : 'DEMODEFFXXX',
        'bank_institute'             => $wert === null || trim((string) $wert) === '' ? $wert : 'Musterbank',
        'account_holder'             => $wert === null || trim((string) $wert) === '' ? $wert : 'Demo Konto',
        'steuer_id'                  => $wert === null || trim((string) $wert) === '' ? $wert : sprintf('00000%06d', $id),
        'sozialversicherungsnummer'  => $wert === null || trim((string) $wert) === '' ? $wert : sprintf('00%06dD000', $id),
        'personnel_number'           => $wert === null || trim((string) $wert) === '' ? $wert : PERSONALNUMMER_PRAEFIX . $id,
        'recruited_by_personnel_number' => $wert === null || trim((string) $wert) === '' ? $wert : PERSONALNUMMER_PRAEFIX . 'X',
        'portal_token'               => $wert === null || trim((string) $wert) === '' ? $wert : sprintf('dump-%08d', $id),
        'other_employer'             => $wert === null || trim((string) $wert) === '' ? $wert : 'Anderer Arbeitgeber',
        'beschaftigungsort'          => $wert,   // Einsatzort, kein Personenbezug
        'cost_center'                => $wert,
        default                      => null,
    };
}

// ---------------------------------------------------------------------------
// Schreiben
// ---------------------------------------------------------------------------

$spaltenListe = '`' . implode('`, `', $spalten) . '`';
foreach (array_chunk($werte, 100) as $block) {
    $tupel = [];
    foreach ($block as $zeile) {
        $felder = [];
        foreach ($spalten as $spalte) {
            $w = $zeile[$spalte];
            $felder[] = sqlWert($w === null ? null : (string) $w);
        }
        $tupel[] = '(' . implode(', ', $felder) . ')';
    }
    echo "INSERT INTO rec_employees ({$spaltenListe}) VALUES\n" . implode(",\n", $tupel) . ";\n\n";
}
echo "SET FOREIGN_KEY_CHECKS = 1;\n";

// ---------------------------------------------------------------------------
// Selbstpruefung: haelt der Dump, was er verspricht?
// ---------------------------------------------------------------------------

$fehler = [];
$telefonwerte = [];
foreach ($werte as $z) {
    if (!empty($z['phone']) && !str_starts_with((string) $z['phone'], TELEFON_PRAEFIX)
        && !str_starts_with((string) $z['phone'], '0')) {
        $fehler[] = "Telefonnummer ausserhalb des Ersatzbereichs: {$z['phone']}";
    }
    if (!empty($z['iban']) && !str_starts_with((string) $z['iban'], 'DE00')) {
        $fehler[] = "IBAN nicht ersetzt: {$z['iban']}";
    }
    if (!empty($z['phone'])) {
        $telefonwerte[] = $z['phone'];
    }
}

$vorherPaare = 0;
$suffixe = [];
foreach ($zeilen as $z) {
    $d = preg_replace('/\D+/', '', (string) ($z['phone'] ?? ''));
    if (strlen($d) >= 9) {
        $suffixe[substr($d, -9)] = ($suffixe[substr($d, -9)] ?? 0) + 1;
    }
}
$vorherPaare = count(array_filter($suffixe, fn ($n) => $n > 1));

$nachher = [];
foreach ($telefonwerte as $t) {
    $d = preg_replace('/\D+/', '', $t);
    if (strlen($d) >= 9) {
        $nachher[substr($d, -9)] = ($nachher[substr($d, -9)] ?? 0) + 1;
    }
}
$nachherPaare = count(array_filter($nachher, fn ($n) => $n > 1));

fwrite(STDERR, "\n--- Selbstpruefung ---\n");
fwrite(STDERR, sprintf("Nummerngruppen vorher: %d, nachher: %d %s\n",
    $vorherPaare, $nachherPaare, $vorherPaare === $nachherPaare ? '(gleich, gut)' : '!! WEICHT AB'));

if ($vorherPaare !== $nachherPaare) {
    $fehler[] = 'Die Zahl der geteilten Nummern hat sich veraendert — die Ersetzung ist nicht eindeutig zuordnend.';
}

if ($fehler !== []) {
    fwrite(STDERR, "\n!! FEHLER — Dump NICHT verwenden:\n");
    foreach (array_unique($fehler) as $f) {
        fwrite(STDERR, "   - {$f}\n");
    }
    exit(1);
}

fwrite(STDERR, "Keine echten Nummern, keine echten IBAN im Ergebnis.\n");
fwrite(STDERR, "Fertig.\n");
