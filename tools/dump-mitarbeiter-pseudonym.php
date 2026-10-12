<?php
/**
 * Pseudonymisierter Bestands-Dump: Produktion lesen, Demo fuettern.
 * ---------------------------------------------------------------------------
 *
 * WOFUER: Die Generalprobe des Deploys auf der Demo braucht einen Bestand, der
 * sich anfuehlt wie der echte — 1213 Menschen statt acht Puppen, dazu ihre
 * Einsaetze, Vertraege und HR-Faelle. Echte Daten haben in diesem Projekt
 * schon Dinge gekippt, die kein ausgedachter Datensatz je getroffen haette
 * (die Mitarbeiter-Anlage starb an einem zu langen Feldwert, Fehler 22001,
 * und verschluckte dabei still die Portal-Nachricht). Auf diesem Bestand
 * laufen danach die Prod-Kommandos (nachweise-umziehen, personen-anlegen,
 * seed-rec-contract-extra-fields, vertraege-an-anstellung, einsatz-pruefung,
 * personendaten-abgleich, konto-einladen --bericht).
 *
 * DER TRICK: Was wir testen wollen, haengt an GLEICHHEITEN, nicht an Inhalten.
 * Ob zwei Datensaetze dieselbe Nummer tragen, ob zwei denselben Namen und
 * dasselbe Geburtsdatum haben, ob ein Einsatz einen Mitarbeiter trifft — das
 * entscheidet ueber Paarung, Kollision und Personen-Zeile. WELCHE Nummer es
 * ist, entscheidet nichts.
 *
 * Deshalb ersetzt dieses Skript NICHT UMKEHRBAR, aber EINDEUTIG ZUORDNEND:
 * gleicher Wert rein, gleicher Wert raus; verschiedener Wert rein,
 * verschiedener Wert raus. Die 354 Paare bleiben 354 Paare, der eine Konflikt
 * bleibt ein Konflikt, die fuenf Abweichungen bleiben fuenf.
 *
 * FAIL CLOSED: Spalten werden AUSDRUECKLICH freigegeben, je Tabelle. Was das
 * Skript nicht kennt, wird geleert (gar nicht erst ins INSERT geschrieben,
 * die Demo setzt ihren Spalten-Vorgabewert) und am Ende gemeldet. Lieber ein
 * Feld zu viel leer als eines zu wenig — eine vergessene Spalte traegt sonst
 * echte Daten mit. Und: die SQL wird erst NACH bestandener Selbstpruefung
 * ausgegeben. Scheitert sie, bleibt STDOUT leer — eine leere demo.sql kann
 * niemand aus Versehen einspielen.
 *
 * TELEFONNUMMERN: siehe Konstante TELEFON_PRAEFIX — gueltiges Format, der
 * Schutz ist die Umgebung (keine Meta-Vorlagen auf der Demo).
 *
 * NICHT MITGENOMMEN: hochgeladene Dateien (Ausweiskopien, Selfies). Die
 * *_file_id-Spalten bleiben erhalten, damit die Nachweis-Logik etwas zu sehen
 * hat; die Dateien selbst liegen nicht auf der Demo. Das ist Absicht — sie
 * bringen null Testwert und sind das Heikelste am ganzen Bestand. Ebenso
 * NICHT: Vertragsinhalt, Unterschriftsbild, Notizen, Nachrichten-Verweise.
 *
 * ---------------------------------------------------------------------------
 * AUFRUF (alles lokal, auf der Produktion wird NICHTS ausgefuehrt):
 *
 *     php tools/dump-mitarbeiter-pseudonym.php --ordner=<dir> --team=6 > demo.sql
 *
 * Im Ordner liegt je Tabelle eine CSV mit Kopfzeile, Dateiname = Tabellenname.
 * Nur rec_employees.csv ist Pflicht. Fehlt eine andere Datei, wird die Tabelle
 * uebersprungen und gemeldet — und es gibt fuer sie KEIN TRUNCATE, die Demo
 * behaelt dort ihren Stand.
 *
 * Der alte Weg fuer nur die Mitarbeiter bleibt:
 *
 *     php tools/dump-mitarbeiter-pseudonym.php --csv=rec_employees.csv --team=6 > demo.sql
 *
 * Der fruehere Weg direkt aus der Datenbank (.env, SHOW COLUMNS) ist
 * gestrichen: er kannte nur eine Tabelle, und den TablePlus-Weg benutzt
 * ohnehin jeder.
 *
 * DIE NEUN EXPORTE — in TablePlus gegen prod, je Ergebnis "Export → CSV" mit
 * Kopfzeile, Dateiname wie angegeben:
 *
 *   rec_employees.csv (Pflicht) — alle, die Paarung braucht den ganzen Bestand:
 *       SELECT * FROM rec_employees;
 *
 *   rec_employee_hr_data.csv — 1:1 zu den Mitarbeitern, also ebenfalls alle:
 *       SELECT * FROM rec_employee_hr_data;
 *
 *   rec_dispo_assignments.csv — die Einsatz-Pruefung schaut nach VORN, die
 *   Akte zeigt Historie; 60 Tage zurueck reichen fuer beides und halten die
 *   Datei klein:
 *       SELECT * FROM rec_dispo_assignments WHERE datum >= CURDATE() - INTERVAL 60 DAY;
 *
 *   rec_dispo_events.csv — genau die Veranstaltungen dieser Einsaetze, sonst
 *   haengen Einsaetze ins Leere oder es kommen Jahre alter Events mit:
 *       SELECT * FROM rec_dispo_events WHERE id IN (SELECT rec_dispo_event_id FROM rec_dispo_assignments WHERE datum >= CURDATE() - INTERVAL 60 DAY);
 *
 *   rec_contract_templates.csv — alle, auch geloeschte: die Vertragspruefung
 *   liest Vorlagen withTrashed():
 *       SELECT * FROM rec_contract_templates;
 *
 *   rec_contracts.csv — alle (vertraege-an-anstellung arbeitet den ganzen
 *   Bestand ab), aber OHNE Inhalt und Unterschriftsbild: die beiden Spalten
 *   machen die Datei riesig, und was nicht exportiert wird, kann auch auf
 *   keinem Laptop liegen bleiben. Das Skript leert sie trotzdem, falls doch
 *   jemand SELECT * nimmt:
 *       SELECT id, uuid, rec_applicant_id, rec_employee_id, rec_contract_template_id, team_id, status, signed_at, sent_at, completed_at, superseded_by_contract_id, created_at, updated_at FROM rec_contracts;
 *
 *   rec_applicants.csv — nur Bewerbungen, auf die etwas zeigt (Anstellung,
 *   Vertrag, HR-Fall). Mehr braucht die Generalprobe nicht:
 *       SELECT * FROM rec_applicants WHERE id IN (SELECT rec_applicant_id FROM rec_employees) OR id IN (SELECT rec_applicant_id FROM rec_contracts) OR id IN (SELECT rec_applicant_id FROM rec_hr_desk_cases);
 *
 *   rec_hr_desk_cases.csv — alle offenen (die Einsatz-Pruefung prueft gegen
 *   offene Faelle, ob sie neu anlegen muss) plus die letzten 60 Tage:
 *       SELECT * FROM rec_hr_desk_cases WHERE status = 'open' OR opened_at >= CURDATE() - INTERVAL 60 DAY;
 *
 *   contract_extra_fields.csv — Vertrags-Zusatzfelder. fieldable_type MUSS
 *   mit: die App liest Werte ueber den Morph-Alias 'rec_contract'
 *   (RecruitingServiceProvider), aeltere Zeilen tragen den vollen
 *   Klassennamen. Ein LIKE '%RecContract' traefe den Alias NICHT. Nur die vier
 *   Felder, die die Vertragspruefung braucht — der Rest bleibt auf prod:
 *       SELECT v.fieldable_id, v.fieldable_type, d.name, v.value FROM core_extra_field_values v JOIN core_extra_field_definitions d ON d.id = v.definition_id WHERE v.fieldable_type IN ('rec_contract', 'Platform\\Recruiting\\Models\\RecContract') AND d.name IN ('vertragsbeginn', 'vertragsende', 'zuschlag', 'herkunft');
 *
 * ZUSATZFELDER UND seed-rec-contract-extra-fields: Die Werte haengen an
 * Definitionen, deren Kennungen auf prod und Demo verschieden sind. Das SQL
 * loest sie deshalb auf der Demo ueber Name + Mandant auf (Unterabfrage).
 * Fehlt die Definition dort, wird definition_id NULL und das INSERT bricht
 * ab (NOT NULL) — laut statt still. Die Pruefzeile am Ende zeigt zusaetzlich
 * "ohne_definition" (muss 0 sein). Die Definitionen legt
 * `php artisan recruiting:seed-rec-contract-extra-fields` an — und zwar nur
 * fuer Mandanten, die schon Vertragsvorlagen haben. Reihenfolge also:
 * entweder seed VORHER (wenn die Demo schon Vorlagen fuer das Zielteam hat),
 * oder: Datei einspielen, scheitert an den Zusatzfeldern → seed → Datei
 * NOCHMAL einspielen (sie ist wiederholbar: TRUNCATE bzw. DELETE vorweg).
 * Bewusst KEIN eigenes Anlegen der Definitionen hier: seed gehoert zu den
 * Prod-Schritten, die die Generalprobe ueben soll.
 *
 * Eine Eigenart des CSV-Wegs: TablePlus schreibt NULL und leeren Text
 * gleich. Das Skript macht deshalb aus jedem leeren Feld ein NULL (bei
 * Pflichtspalten den Vorgabewert, siehe 'pflicht'). Fuer diesen Test ist das
 * folgenlos — der Code behandelt beides ohnehin gleich — aber es ist ein
 * Unterschied zum Original, und der gehoert benannt.
 *
 * Meldungen gehen nach STDERR, die SQL nach STDOUT — deshalb funktioniert die
 * Umleitung mit >.
 * ---------------------------------------------------------------------------
 */

// Jede PHP-Meldung geht nach STDERR. Ohne das landet schon ein harmloser
// Deprecated-Hinweis mitten in der erzeugten SQL und macht sie unbrauchbar —
// beim ersten Testlauf genau so passiert.
ini_set('display_errors', 'stderr');

// Der ECHTE Abgleich aus dem Modul, nicht nachgebaut: die Selbstpruefung
// fragt ihn, ob jeder Einsatz auf der Demo denselben Mitarbeiter trifft wie
// auf prod. Beide Klassen sind reines PHP ohne Laravel.
require_once __DIR__ . '/../src/Support/ZasPersonnelNumber.php';
require_once __DIR__ . '/../src/Services/Zas/Dispo/ZasDispoMatcher.php';

use Platform\Recruiting\Services\Zas\Dispo\ZasDispoMatcher;
use Platform\Recruiting\Support\ZasPersonnelNumber;

const GEBURTSTAG_VERSCHIEBUNG = 137;      // Tage; Gleichheit bleibt, Identitaet geht
const AUSWEIS_EINHEITLICH     = 'L01X00T4711';  // Anmeldung: Geburtsdatum + 4711
const PERSONALNUMMER_PRAEFIX  = 'DUMP-';
// 30.09.2026 berichtigt: der alte Praefix '+49000' war zwar nicht waehlbar,
// aber PhoneE164::normalize() lieferte dafuer NULL — 000 ist keine Vorwahl,
// und mit neun Folgeziffern kam eine 14-stellige Nummer heraus. Auf der Demo
// hatten danach 1235 von 1242 Menschen GAR KEINE Nummer, und weil die Nummer
// der Benutzername des Kontos ist, konnte sich niemand anmelden.
//
// Jetzt: gueltiges Mobilformat (13 Ziffern), damit die Demo benutzbar ist.
// DAMIT IST DIE NUMMER NICHT MEHR VON SICH AUS UNERREICHBAR — einen deutschen
// Bereich, der zugleich gueltig und garantiert niemandem zugeteilt ist, gibt
// es nicht, und den offiziellen britischen Film-Bereich (+447700900...) lehnt
// unser Normalisierer ab (er nimmt nur deutsche Nummern).
// DER SCHUTZ IST JETZT DIE UMGEBUNG: auf der Demo sind keine Meta-Vorlagen
// eingetragen, ohne die geht keine einzige Nachricht raus. Wer dort je
// Vorlagen eintraegt, schreibt an Fremde. Das ist kein Rest-Risiko, sondern
// eine Bedingung — sie gehoert geprueft, bevor jemand den Versand scharf macht.
const TELEFON_PRAEFIX         = '+491570';

// ---------------------------------------------------------------------------
// Personalnummern (12.10.2026 umgestellt)
// ---------------------------------------------------------------------------
// Frueher wurde jede Personalnummer zu 'DUMP-<id>'. Das hat zwei Dinge
// zerstoert, die die Generalprobe jetzt braucht:
//  1. Den Firmen-Praefix. ZasPersonnelNumber::prefixOf('DUMP-5') ist 'DUMP',
//     nicht 'RG' — die Vertragspruefung liest die Gesellschaft eines Einsatzes
//     aber genau aus diesem Praefix (VertragsPruefung::pruefe).
//  2. Die Gleichheit. Zwei Zeilen mit derselben echten Nummer bekamen zwei
//     verschiedene Ersatznummern — genau die Faelle, um die es bei Paarung
//     und Dubletten geht.
// Jetzt: Praefix bleibt, nur die ZIFFERN werden eindeutig zugeordnet ersetzt
// (RG353 → RG9000000, MA353 → MA9000000 — dieselben Ziffern bei beiden
// Firmen bleiben dieselben, wie auf prod). Dieselbe Karte gilt fuer
// rec_dispo_assignments.pnr_raw; deshalb trifft der Abgleich (ZasDispoMatcher:
// exakt, eigener Praefix, gekuerzte Form) auf der Demo genau dort, wo er auf
// prod trifft. Die Selbstpruefung beweist das je Einsatz.
const ERSATZ_NUMMERN_BASIS = 9000000;
// Die gekuerzte Form der ZAS (MA1000000878 → MA878, siehe
// ZasPersonnelNumber::shortenedForm) muss erhalten bleiben: eine lange Nummer
// wird zu 1000000000 + Ersatz(kurze Nummer). Dann ist die gekuerzte Form des
// Ersatzes wieder der Ersatz der gekuerzten Form.
const KUERZUNGS_SCHWELLE = 1000000000;
// Platzhalter fuer unbesetzte Plaetze in der Dispo (RG0, RG14 — RG14 trifft
// auf prod sogar einen echten Mitarbeiter). Bleiben, wie sie sind; ihre
// Ziffern sind deshalb auch in der Mitarbeiter-Karte Fixpunkte, sonst traefe
// RG14 auf der Demo jemand anderen als auf prod. Eine nackte 0 oder 14 sagt
// ueber niemanden etwas.
const PLATZHALTER   = ['RG0', 'RG14'];
const FESTE_ZIFFERN = ['0', '14'];

// ---------------------------------------------------------------------------
// Spaltenregeln rec_employees. Alles, was hier nicht steht, wird GELEERT und
// gemeldet.
// ---------------------------------------------------------------------------

/** Unveraendert uebernehmen — kein Personenbezug oder fuer den Test noetig. */
const BEHALTEN = [
    // team_id steht bewusst NICHT hier: sie kommt aus der Produktion und passt
    // auf keiner anderen Umgebung. Ohne --team landen die Datensaetze am
    // falschen Mandanten und die Liste bleibt leer, obwohl alles drin ist.
    'id', 'is_active', 'company', 'employment_type',
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
    // rec_applicant_id: bleibt NUR, wenn rec_applicants.csv mitkommt. Sonst
    // zeigt er auf eine Bewerbung, die es auf der Demo nicht (oder als eine
    // ganz andere) gibt. Mit Bewerbern MUSS er bleiben: vertraege-an-anstellung
    // findet die Anstellung eines Vertrags ueber Bewerbung → rec_employees.
    'rec_applicant_id',
];

/** Verweise auf Zeilen, die auf der Demo nicht existieren — auf NULL. */
const LEEREN = [
    'rec_position_id', 'rec_zas_inbound_file_id',
    'created_by_user_id', 'zas_id',
    'payroll_data_changed_at', 'payroll_data_changed_fields',
];

// ---------------------------------------------------------------------------
// Regeln aller Tabellen. Schluessel:
//   behalten  unveraendert
//   kennwort  unveraendert, aber NUR wenn der Wert wie ein Code aussieht
//             (status, reason …); sonst geleert und gemeldet. Zweiter Riegel:
//             ein Statusfeld, in das jemand Freitext getippt hat, faellt auf.
//   ersetzen  Regel in ersetze()
//   leeren    bekannt, wird NULL (bzw. Pflichtwert)
//   pflicht   NOT-NULL-Spalten: Wert, der statt NULL geschrieben wird. Ein
//             explizites NULL bricht ein INSERT im Strict-Mode ab, auch wenn
//             die Spalte einen Vorgabewert hat — deshalb steht hier jeweils
//             genau dieser Vorgabewert aus der Migration (neutral, kein
//             Original). Gilt fuer geleerte Spalten UND fuer leere CSV-Felder.
//   katalog   Namenspruefung: ein Treffer ist hier nur eine Warnung (siehe
//             Selbstpruefung) — Vorlagentexte, Taetigkeiten, Eventnamen sind
//             Kundendaten, in denen Nachnamen wie "Koch" als Wort vorkommen.
// team_id wird in JEDER Tabelle, die sie hat, aus --team gesetzt.
// Spaltennamen aus den Migrationen (database/migrations, Stand 12.10.2026).
// ---------------------------------------------------------------------------

const TABELLEN = [
    'rec_employees' => [
        'behalten' => [...BEHALTEN, ...DATEI_SPALTEN],
        'kennwort' => [],
        'ersetzen' => ERSETZEN,
        'leeren'   => LEEREN,
        'pflicht'  => ['is_active' => '1'],
        'katalog'  => ['art_der_tatigkeit', 'beschaftigungsort', 'cost_center'],
    ],
    // Taetigkeiten, Tagebudget, Bewertungs-ZAHLEN, Waeschepaket — alles
    // Katalog oder Zaehler. Der Bewertungs-TEXT ist Freitext ueber einen
    // Menschen und bleibt auf prod.
    'rec_employee_hr_data' => [
        'behalten' => [
            'id', 'rec_employee_id', 'created_at', 'updated_at',
            'contract_signed_at', 'contract_sent_date', 'contract_end_date',
            'linen_package_items', 'star_rating', 'qualifications',
            'rating_erscheinungsbild', 'rating_fachkompetenz', 'rating_auffassungsgabe',
            'rating_auftreten', 'rating_teamintegration',
            'status_ma_since', 'dispo_taetigkeiten', 'dispo_taetigkeiten_synced_at',
            'short_term_days_allowed', 'short_term_days_worked',
            'short_term_days_remaining', 'short_term_days_allowed_year',
        ],
        'kennwort' => ['export_status', 'employment_classification'],
        'ersetzen' => ['uuid'],
        'leeren'   => ['evaluation_note'],
        'pflicht'  => ['export_status' => 'GO', 'employment_classification' => 'kurzfristig'],
        'katalog'  => ['qualifications', 'dispo_taetigkeiten', 'linen_package_items'],
    ],
    // Veranstaltungen sind Kunden-/Eventdaten: Name, Ort, Zeitraum bleiben.
    // Freitexte (Anfahrt, Dresscode, Hinweis) koennen Ansprechpartner samt
    // Handynummer tragen — die Generalprobe braucht sie nicht, also weg.
    // source_meta ist die rohe ZAS-Zeile (mitarbeiter_info, interne_bem).
    'rec_dispo_events' => [
        'behalten' => [
            'id', 'einsatz_ref', 'name', 'venue_text', 'ort', 'einsatzfirma',
            'starts_on', 'ends_on', 'created_at', 'updated_at', 'vorlauf_minuten',
            'filiale', 'filial_nr', 'escalation_day', 'escalation_time_1',
            'escalation_time_2', 'escalation_time_3', 'escalation_date',
            'dresscode_ack_at',
        ],
        'kennwort' => [],
        'ersetzen' => ['uuid'],
        'leeren'   => ['anfahrt', 'dresscode', 'hinweis', 'dresscode_ack', 'source_meta',
                       'ansprechpartner', 'alarm_message_id'],
        'pflicht'  => ['einsatz_ref' => ''],
        'katalog'  => ['name', 'venue_text', 'ort', 'einsatzfirma', 'filiale'],
    ],
    // Einsaetze: Datum, Zeiten, Status, Taetigkeit und alle Zustands-
    // Zeitstempel (sonst schickt der Trigger auf der Demo alles noch einmal
    // bzw. die Pruefung sieht andere Faelle als prod). Weg: Nachrichten-
    // Verweise, die Nummer, an die erinnert wurde, Notizen, User-Verweise,
    // source_meta (enthaelt tlp_nr = Telefon), das Kleider-Paket (Tabelle
    // kommt nicht mit).
    'rec_dispo_assignments' => [
        'behalten' => [
            'id', 'ds_ref', 'rec_dispo_event_id', 'rec_employee_id', 'datum', 'von', 'bis',
            'status_id', 'taetigkeit', 'last_seen_at', 'missing_since',
            'created_at', 'updated_at', 'reminder_sent_at', 'confirmed_at',
            'deletion_marked_at', 'deletion_confirmed_at', 'escalation_1_at', 'escalation_2_at',
            'confirmed_datum', 'confirmed_von', 'confirmed_bis', 'reconfirm_required_at',
            'reconfirm_previous',   // {datum, von, bis} — nur Termin-Schnappschuss
            'individual_note_updated_at', 'declined_at', 'declined_portal_locked',
            'declined_hr_at', 'declined_hr_done_at', 'zas_removed_at',
            'escalation_due_1_at', 'escalation_due_2_at', 'escalation_due_3_at',
            'late_marked_at', 'dress_frozen_at', 'aufgaben_erinnert_at',
        ],
        'kennwort' => ['declined_reason'],
        'ersetzen' => ['uuid', 'pnr_raw'],
        'leeren'   => [
            'source_meta', 'reminder_message_id', 'escalation_1_message_id',
            'escalation_2_message_id', 'individual_note', 'declined_note',
            'declined_by_user_id', 'declined_hr_done_by_user_id', 'zas_removed_by_user_id',
            'reminder_sent_to', 'manually_confirmed_by_user_id', 'late_marked_by_user_id',
            'rec_dispo_dress_package_id', 'dress_items_text',
        ],
        'pflicht'  => ['ds_ref' => '', 'pnr_raw' => '', 'status_id' => '0', 'declined_portal_locked' => '0'],
        'katalog'  => ['taetigkeit'],
    ],
    // Vorlagen tragen keinen Personenbezug — der Text bleibt, sonst gibt es
    // nichts zu pruefen (code AV-%, company, taetigkeit steuern die Zuordnung).
    'rec_contract_templates' => [
        'behalten' => [
            'id', 'name', 'code', 'description', 'content', 'field_mappings',
            'requires_signature', 'is_active', 'sort_order', 'created_at', 'updated_at',
            'deleted_at', 'taetigkeit',
        ],
        'kennwort' => ['type', 'company'],
        'ersetzen' => ['uuid'],
        'leeren'   => ['created_by_user_id'],
        'pflicht'  => ['name' => '', 'requires_signature' => '1', 'is_active' => '1',
                       'sort_order' => '0', 'type' => 'contract', 'company' => 'RG'],
        'katalog'  => ['name', 'code', 'description', 'content', 'field_mappings', 'taetigkeit'],
    ],
    // Vertraege: Zustand und Verweise bleiben. Inhalt (HTML mit Name, Adresse,
    // IBAN), Unterschriftsbild, Vorab-Daten des Formulars und Notizen nicht.
    // Lohn-/Wertfelder gibt es als SPALTEN nicht — Vertragsbeginn/-ende und
    // Zuschlag liegen in den Zusatzfeldern (contract_extra_fields.csv). Die
    // uuid ist der Signaturlink; sie wird ersetzt wie ueberall.
    'rec_contracts' => [
        'behalten' => [
            'id', 'rec_applicant_id', 'rec_employee_id', 'rec_contract_template_id',
            'signed_at', 'sent_at', 'completed_at', 'superseded_by_contract_id',
            'created_at', 'updated_at',
        ],
        'kennwort' => ['status'],
        'ersetzen' => ['uuid'],
        'leeren'   => ['personalized_content', 'signature_data', 'pre_signing_data', 'notes',
                       'created_by_user_id'],
        'pflicht'  => ['status' => 'pending'],
        'katalog'  => [],
    ],
    // Bewerbungen: so wenig wie moeglich. Namen und Geburtsdatum stehen in
    // dieser Tabelle gar nicht (sie liegen in CRM-Kontakt und Zusatzfeldern,
    // die NICHT mitkommen) — die Akte zeigt auf der Demo also eine Bewerbung
    // ohne Namen. Phase/Status-Kennungen (rec_phase_id,
    // rec_applicant_status_id) zeigen auf prod-Zeilen, die auf der Demo andere
    // sind → NULL. Behalten: die Schalter, nach denen Listen und HR-Schreib-
    // tisch filtern. auto_pilot wird 0 (Pflichtwert): auf der Demo soll kein
    // Bewerber angeschrieben werden, egal was prod sagt.
    'rec_applicants' => [
        'behalten' => [
            'id', 'progress', 'applied_at', 'is_active', 'is_parked', 'parked_at',
            'is_on_hr_desk', 'rejected_at', 'is_unrouted', 'is_test',
            'created_at', 'updated_at',
        ],
        'kennwort' => [],
        'ersetzen' => ['uuid'],
        'leeren'   => [
            'rec_applicant_status_id', 'notes', 'auto_pilot', 'auto_pilot_completed_at',
            'auto_pilot_state_id', 'created_by_user_id', 'owned_by_user_id', 'public_token',
            'enrichment_status', 'preferred_comms_channel_id', 'auto_pilot_reminder_count',
            'auto_pilot_last_reminder_at', 'rec_phase_id', 'source_platform_id',
            'contract_template_id', 'import_source', 'export_changed_at', 'zuschlag',
            'suggested_posting_id', 'match_reason', 'duplicate_of_applicant_id',
            'rating_erscheinungsbild', 'rating_fachkompetenz', 'rating_auffassungsgabe',
            'rating_auftreten', 'rating_teamintegration', 'evaluation_note',
            'linen_package_items', 'qualifications', 'rec_position_id',
            'zuschlag_vorschlag', 'vertragsbeginn_vorschlag', 'vertragsende_vorschlag',
            'vorschlag_at', 'vorschlag_by', 'vorschlag_taken_at',
            'vertragsbeginn_geplant', 'vertragsende_geplant', 'vertragsdaten_geplant_at',
        ],
        // Alles Vorgabewerte der Migration; auto_pilot = 0 ist zugleich die
        // Bremse (siehe oben), der Erinnerungszaehler startet neutral bei 0.
        'pflicht'  => ['progress' => '0', 'is_active' => '1', 'auto_pilot' => '0',
                       'auto_pilot_reminder_count' => '0', 'is_parked' => '0',
                       'is_on_hr_desk' => '0', 'is_unrouted' => '0', 'is_test' => '0'],
        'katalog'  => [],
    ],
    // HR-Faelle: Fallart, Status, Daten, Verweise. Die Notizen sind Freitext
    // (bei contract_missing steht Event + Taetigkeit drin, bei HR-Faellen
    // alles Moegliche) → NULL. Folgenlos fuer die Generalprobe: die Faelle,
    // deren Notiz der Code liest (contract_missing, work_permit), legt erst
    // die neue einsatz-pruefung an — auf prod gibt es sie vor dem Deploy
    // nicht, die Demo baut sie im Lauf selbst.
    'rec_hr_desk_cases' => [
        'behalten' => [
            'id', 'rec_applicant_id', 'rec_employee_id', 'opened_at', 'resolved_at',
            'created_at', 'updated_at',
        ],
        'kennwort' => ['reason', 'status'],
        'ersetzen' => ['uuid'],
        'leeren'   => ['notes', 'resolution_notes', 'opened_by_user_id', 'resolved_by_user_id'],
        'pflicht'  => ['reason' => '', 'status' => 'open'],
        'katalog'  => [],
    ],
];

/** Reihenfolge der Ausgabe. Mitarbeiter zuerst: ihre Nummern-Karte brauchen die Einsaetze. */
const REIHENFOLGE = [
    'rec_employees', 'rec_employee_hr_data', 'rec_dispo_events', 'rec_dispo_assignments',
    'rec_contract_templates', 'rec_contracts', 'rec_applicants', 'rec_hr_desk_cases',
];

// ---------------------------------------------------------------------------
// Vertrags-Zusatzfelder
// ---------------------------------------------------------------------------

/** Nur diese vier; Wertform je Feld — passt ein Wert nicht, wird er verworfen und gemeldet. */
const ZUSATZFELDER = [
    'vertragsbeginn' => '/^(\d{4}-\d{2}-\d{2}([ T][\d:.]+)?|\d{1,2}\.\d{1,2}\.\d{4})$/',
    'vertragsende'   => '/^(\d{4}-\d{2}-\d{2}([ T][\d:.]+)?|\d{1,2}\.\d{1,2}\.\d{4})$/',
    'zuschlag'       => '/^[\d.,\s€]{0,20}$/u',
    'herkunft'       => '/^[a-z_]{1,30}$/',
];
// Beide Formen, in denen fieldable_type auf prod steht: der Morph-Alias
// (RecruitingServiceProvider, so schreibt die App heute) und der volle
// Klassenname (aeltere Zeilen; auch context_type der Definitionen, so legt
// sie SeedRecContractExtraFields an).
const VERTRAG_ALIAS  = 'rec_contract';
const VERTRAG_KLASSE = 'Platform\\Recruiting\\Models\\RecContract';

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
const ORTE = ['Musterstadt','Beispielheim','Testdorf','Probstadt'];

$karten = ['vorname' => [], 'nachname' => [], 'telefon' => [], 'ort' => [],
           'pnr' => [], 'pnr_unbekannt' => []];

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
 * bleibt dieselbe, eine andere bleibt eine andere. Der Praefix steht an der
 * Konstante TELEFON_PRAEFIX — dort steht auch, warum die Nummern seit dem
 * 30.09.2026 gueltig sind und was das voraussetzt.
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
        // Sieben Stellen: +491570 (6 Ziffern) + 7 = 13 Ziffern, das ist ein
        // gueltiges deutsches Mobilformat. Neun waeren es 15 und damit wieder
        // unlesbar. Reicht fuer 10 Mio. verschiedene Nummern.
        $karte[$suffix] = str_pad((string) count($karte), 7, '0', STR_PAD_LEFT);
    }

    return TELEFON_PRAEFIX . $karte[$suffix];
}

/** 'RG353' → ['RG', '353']; alles andere (Bindestrich, Leerzeichen innen …) → null. */
function zerlegeNummer(string $wert): ?array
{
    return preg_match('/^(\p{L}*)(\d+)$/u', $wert, $m) ? [$m[1], $m[2]] : null;
}

/** Wuerde ZasPersonnelNumber::shortenedForm() fuer diese Ziffern eine kurze Form bilden? */
function istLangeForm(string $ziffern): bool
{
    return strlen($ziffern) >= 10 && strlen($ziffern) <= 18 && (int) $ziffern > KUERZUNGS_SCHWELLE;
}

/**
 * Ziffern einer Personalnummer, eindeutig zuordnend ersetzt. Fixpunkte
 * (Platzhalter-Ziffern) bleiben; die lange Form wird ueber die kurze
 * gebildet, damit die Kuerzung auf der Demo dasselbe trifft wie auf prod.
 */
function ersatzZiffern(string $ziffern, array &$karte): string
{
    if (in_array($ziffern, FESTE_ZIFFERN, true)) {
        return $ziffern;
    }
    if (istLangeForm($ziffern)) {
        $kurz = (string) ((int) $ziffern - KUERZUNGS_SCHWELLE);

        return (string) (KUERZUNGS_SCHWELLE + (int) ersatzZiffern($kurz, $karte));
    }
    if (!isset($karte[$ziffern])) {
        $karte[$ziffern] = (string) (ERSATZ_NUMMERN_BASIS + count($karte));
    }

    return $karte[$ziffern];
}

/** Kennt die Mitarbeiter-Karte diese Ziffern (direkt oder ueber die kurze Form)? */
function zifferBekannt(string $ziffern, array $karte): bool
{
    if (in_array($ziffern, FESTE_ZIFFERN, true) || isset($karte[$ziffern])) {
        return true;
    }
    if (istLangeForm($ziffern)) {
        $kurz = (string) ((int) $ziffern - KUERZUNGS_SCHWELLE);

        return in_array($kurz, FESTE_ZIFFERN, true) || isset($karte[$kurz]);
    }

    return false;
}

/** Stabiler Ersatz fuer eine Nummer, die nicht ins Muster passt oder niemanden trifft. */
function unbekannteNummer(string $wert, array &$karte): string
{
    $karte[$wert] ??= PERSONALNUMMER_PRAEFIX . 'U' . (count($karte) + 1);

    return $karte[$wert];
}

function personalnummer(?string $wert, array &$karten): ?string
{
    if ($wert === null || trim($wert) === '') {
        return $wert;
    }
    $teile = zerlegeNummer(trim($wert));
    if ($teile === null) {
        return unbekannteNummer(trim($wert), $karten['pnr_unbekannt']);
    }

    return $teile[0] . ersatzZiffern($teile[1], $karten['pnr']);
}

/**
 * pnr_raw eines Einsatzes. Praefix bleibt, Ziffern ueber DIESELBE Karte wie
 * die Mitarbeiter. Ziffern, die zu keinem Mitarbeiter gehoeren, treffen auf
 * prod niemanden (weder exakt noch ueber eigenen Praefix noch gekuerzt) —
 * sie werden DUMP-U<n> und treffen auf der Demo ebenso niemanden.
 */
function einsatzNummer(?string $wert, array &$karten): ?string
{
    if ($wert === null || trim($wert) === '') {
        return $wert;
    }
    $roh = trim($wert);
    if (in_array($roh, PLATZHALTER, true)) {
        return $wert;
    }
    $teile = zerlegeNummer($roh);
    if ($teile === null || !zifferBekannt($teile[1], $karten['pnr'])) {
        return unbekannteNummer($roh, $karten['pnr_unbekannt']);
    }

    return $teile[0] . ersatzZiffern($teile[1], $karten['pnr']);
}

/** Deterministische Ersatz-UUID; je Tabelle eindeutig, weil die id es ist. */
function ersatzUuid(int $id): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $id);
}

function leer($wert): bool
{
    return $wert === null || trim((string) $wert) === '';
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

/**
 * CSV mit Kopfzeile lesen. Zeilen mit falscher Feldzahl werden uebersprungen
 * — und GEZAEHLT (frueher still): eine kaputte Zeile ist kein Datenschutz-,
 * aber ein Vergleichsproblem, und das soll man sehen.
 *
 * @return array{0: list<string>, 1: list<array<string,?string>>, 2: int}
 */
function liesCsv(string $pfad): array
{
    if (!is_readable($pfad)) {
        fwrite(STDERR, "FEHLER: {$pfad} nicht lesbar.\n");
        exit(1);
    }
    $griff = fopen($pfad, 'r');
    $spalten = fgetcsv($griff, null, ',', '"', '');
    if ($spalten === false || $spalten === [null]) {
        fwrite(STDERR, "FEHLER: {$pfad} hat keine Kopfzeile.\n");
        exit(1);
    }
    $spalten = array_map(fn ($s) => trim((string) $s, " \t\"'\xEF\xBB\xBF"), $spalten);

    $zeilen = [];
    $kaputt = 0;
    while (($satz = fgetcsv($griff, null, ',', '"', '')) !== false) {
        if ($satz === [null]) {
            continue;
        }
        if (count($satz) !== count($spalten)) {
            $kaputt++;
            continue;
        }
        // TablePlus schreibt NULL und leeren Text gleich -> beides wird NULL.
        $zeilen[] = array_map(fn ($w) => ($w === '' ? null : $w), array_combine($spalten, $satz));
    }
    fclose($griff);

    return [$spalten, $zeilen, $kaputt];
}

// ---------------------------------------------------------------------------
// Aufruf
// ---------------------------------------------------------------------------

$csvPfad = null;
$ordner = null;
$zielTeam = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--csv=')) {
        $csvPfad = substr($arg, 6);
    }
    if (str_starts_with($arg, '--ordner=')) {
        $ordner = rtrim(substr($arg, 9), '/');
    }
    if (str_starts_with($arg, '--team=')) {
        $zielTeam = (int) substr($arg, 7);
    }
}

if ($zielTeam === null || $zielTeam <= 0) {
    fwrite(STDERR, "FEHLER: --team=<kennung> fehlt.\n\n");
    fwrite(STDERR, "Die Mandanten-Kennung aus der Produktion passt auf keiner anderen\n");
    fwrite(STDERR, "Umgebung. Ohne sie landen die Datensaetze am falschen Mandanten und\n");
    fwrite(STDERR, "die Liste bleibt leer, obwohl alles in der Tabelle steht.\n\n");
    fwrite(STDERR, "Welche Kennung die Zielumgebung benutzt, verraet dort:\n");
    fwrite(STDERR, "    SELECT team_id, COUNT(*) FROM rec_positions GROUP BY team_id;\n\n");
    fwrite(STDERR, "Beispiel:  php tools/dump-mitarbeiter-pseudonym.php --ordner=export --team=6 > demo.sql\n");
    exit(1);
}

if (($csvPfad === null) === ($ordner === null)) {
    fwrite(STDERR, "FEHLER: genau eines von --ordner=<dir> oder --csv=<rec_employees.csv> angeben.\n");
    fwrite(STDERR, "Die Liste der Exporte steht im Kopf dieses Skripts.\n");
    exit(1);
}

/** @var array<string, array{0: list<string>, 1: list<array>, 2: int}> $quellen */
$quellen = [];
$zusatzQuelle = null;

if ($csvPfad !== null) {
    $quellen['rec_employees'] = liesCsv($csvPfad);
    fwrite(STDERR, "Quelle: CSV {$csvPfad} (nur rec_employees)\n");
} else {
    if (!is_dir($ordner)) {
        fwrite(STDERR, "FEHLER: Ordner {$ordner} nicht gefunden.\n");
        exit(1);
    }
    if (!is_readable("{$ordner}/rec_employees.csv")) {
        fwrite(STDERR, "FEHLER: {$ordner}/rec_employees.csv fehlt — die Datei ist Pflicht.\n");
        exit(1);
    }
    fwrite(STDERR, "Quelle: Ordner {$ordner}\n");
    foreach (REIHENFOLGE as $tabelle) {
        $pfad = "{$ordner}/{$tabelle}.csv";
        if (is_file($pfad)) {
            $quellen[$tabelle] = liesCsv($pfad);
        } else {
            fwrite(STDERR, "   - {$tabelle}.csv fehlt → Tabelle UEBERSPRUNGEN (kein TRUNCATE, Demo behaelt ihren Stand)\n");
        }
    }
    if (is_file("{$ordner}/contract_extra_fields.csv")) {
        $zusatzQuelle = liesCsv("{$ordner}/contract_extra_fields.csv");
    } else {
        fwrite(STDERR, "   - contract_extra_fields.csv fehlt → Zusatzfelder UEBERSPRUNGEN (kein DELETE)\n");
    }
    $bekannt = array_merge(array_map(fn ($t) => "{$t}.csv", REIHENFOLGE), ['contract_extra_fields.csv']);
    foreach (glob("{$ordner}/*.csv") ?: [] as $datei) {
        if (!in_array(basename($datei), $bekannt, true)) {
            fwrite(STDERR, "   - " . basename($datei) . " ist keine bekannte Tabelle → ignoriert\n");
        }
    }
}

$mitBewerbern = isset($quellen['rec_applicants']);
if (isset($quellen['rec_contracts']) && !isset($quellen['rec_contract_templates'])) {
    fwrite(STDERR, "!! Vertraege ohne Vorlagen: die Vertraege zeigen auf prod-Vorlagenkennungen, die Demo hat andere.\n");
}
if (isset($quellen['rec_contracts']) && $zusatzQuelle === null) {
    fwrite(STDERR, "!! Vertraege ohne Zusatzfelder: alte Demo-Zusatzwerte haengen danach an fremden Vertraegen.\n");
}

// ---------------------------------------------------------------------------
// Lesen und umschreiben
// ---------------------------------------------------------------------------

function ersetze(string $tabelle, string $spalte, $wert, array $zeile, array &$karten, array $vornamen, array $nachnamen, bool $mitBewerbern)
{
    $id = (int) ($zeile['id'] ?? 0);

    if ($spalte === 'uuid') {
        return ersatzUuid($id);
    }
    if ($tabelle === 'rec_dispo_assignments' && $spalte === 'pnr_raw') {
        return einsatzNummer($wert, $karten);
    }
    if ($tabelle !== 'rec_employees') {
        return null;   // keine weitere Regel: fail closed
    }

    return match ($spalte) {
        'first_name'    => leer($wert) ? $wert : ausPool((string) $wert, $vornamen, $karten['vorname']),
        'last_name'     => leer($wert) ? $wert : ausPool((string) $wert, $nachnamen, $karten['nachname']),
        'birth_name'    => leer($wert) ? $wert : ausPool((string) $wert, $nachnamen, $karten['nachname']),
        'birth_date'    => $wert === null ? null
                             : date('Y-m-d', strtotime((string) $wert . ' +' . GEBURTSTAG_VERSCHIEBUNG . ' days')),
        'birth_place'   => leer($wert) ? $wert : ausPool((string) $wert, ORTE, $karten['ort']),
        'phone'         => telefon($wert, $karten['telefon']),
        'email'         => leer($wert) ? $wert : "demo{$id}@example.invalid",
        'street'        => $wert === null ? null : 'Musterweg',
        'house_number'  => $wert === null ? null : (string) (($id % 89) + 1),
        'zip'           => $wert === null ? null : '40000',
        'city'          => $wert === null ? null : 'Musterstadt',
        'country_code'  => $wert,   // DE/PL/... kein Personenbezug
        'identity_card_number'       => leer($wert) ? $wert : AUSWEIS_EINHEITLICH,
        'iban'                       => leer($wert) ? $wert : sprintf('DE00000000000000%06d', $id),
        'bic'                        => leer($wert) ? $wert : 'DEMODEFFXXX',
        'bank_institute'             => leer($wert) ? $wert : 'Musterbank',
        'account_holder'             => leer($wert) ? $wert : 'Demo Konto',
        'steuer_id'                  => leer($wert) ? $wert : sprintf('00000%06d', $id),
        'sozialversicherungsnummer'  => leer($wert) ? $wert : sprintf('00%06dD000', $id),
        'personnel_number'           => personalnummer($wert, $karten),
        'recruited_by_personnel_number' => leer($wert) ? $wert : PERSONALNUMMER_PRAEFIX . 'X',
        'portal_token'               => leer($wert) ? $wert : sprintf('dump-%08d', $id),
        'other_employer'             => leer($wert) ? $wert : 'Anderer Arbeitgeber',
        'beschaftigungsort'          => $wert,   // Einsatzort, kein Personenbezug
        'cost_center'                => $wert,
        'rec_applicant_id'           => $mitBewerbern ? $wert : null,
        default                      => null,
    };
}

/** Kennwort: Code-artiger Wert (status, reason …), kein Freitext. */
function istKennwort(?string $wert): bool
{
    return $wert === null || preg_match('/^[A-Za-z0-9_.\-]{1,60}$/', $wert) === 1;
}

/** @var array<string, array{spalten: list<string>, werte: list<array>, unbekannt: list<string>, geleert: int, kennwort_verworfen: int, kaputt: int}> $ergebnis */
$ergebnis = [];

foreach (REIHENFOLGE as $tabelle) {
    if (!isset($quellen[$tabelle])) {
        continue;
    }
    [$spalten, $zeilen, $kaputt] = $quellen[$tabelle];
    $r = TABELLEN[$tabelle];

    $unbekannt = [];
    foreach ($spalten as $spalte) {
        if ($spalte !== 'team_id'
            && !in_array($spalte, $r['behalten'], true)
            && !in_array($spalte, $r['kennwort'], true)
            && !in_array($spalte, $r['ersetzen'], true)
            && !in_array($spalte, $r['leeren'], true)) {
            $unbekannt[] = $spalte;
        }
    }
    // Unbekannte Spalten kommen gar nicht erst ins INSERT: dann setzt die Demo
    // ihren Vorgabewert, und eine unbekannte NOT-NULL-Spalte mit Vorgabe
    // bricht nichts.
    $ausgabeSpalten = array_values(array_diff($spalten, $unbekannt));

    $werte = [];
    $kennwortVerworfen = 0;
    foreach ($zeilen as $zeile) {
        $neu = [];
        foreach ($ausgabeSpalten as $spalte) {
            $wert = $zeile[$spalte];
            if ($spalte === 'team_id') {
                $neu[$spalte] = (string) $zielTeam;
            } elseif (in_array($spalte, $r['behalten'], true)) {
                $neu[$spalte] = $wert;
            } elseif (in_array($spalte, $r['kennwort'], true)) {
                if (!istKennwort($wert)) {
                    $kennwortVerworfen++;
                    $wert = null;
                }
                $neu[$spalte] = $wert;
            } elseif (in_array($spalte, $r['leeren'], true)) {
                $neu[$spalte] = null;
            } else {
                $neu[$spalte] = ersetze($tabelle, $spalte, $wert, $zeile, $karten, $vornamen, $nachnamen, $mitBewerbern);
            }
            if ($neu[$spalte] === null && array_key_exists($spalte, $r['pflicht'])) {
                $neu[$spalte] = $r['pflicht'][$spalte];
            }
        }
        $werte[] = $neu;
    }

    $ergebnis[$tabelle] = [
        'spalten'            => $ausgabeSpalten,
        'werte'              => $werte,
        'unbekannt'          => $unbekannt,
        'geleert'            => count(array_intersect($spalten, $r['leeren'])),
        'kennwort_verworfen' => $kennwortVerworfen,
        'kaputt'             => $kaputt,
    ];
}

// ----- Zusatzfelder -----
$zusatz = [];
$zusatzVerworfen = ['fremdes_feld' => 0, 'fremder_typ' => 0, 'wertform' => 0, 'ohne_vertrag' => 0];
if ($zusatzQuelle !== null) {
    [$zSpalten, $zZeilen, $zKaputt] = $zusatzQuelle;
    foreach (['fieldable_id', 'name', 'value'] as $pflichtSpalte) {
        if (!in_array($pflichtSpalte, $zSpalten, true)) {
            fwrite(STDERR, "FEHLER: contract_extra_fields.csv ohne Spalte {$pflichtSpalte} — SELECT aus dem Skriptkopf nehmen.\n");
            exit(1);
        }
    }
    if (!in_array('fieldable_type', $zSpalten, true)) {
        fwrite(STDERR, "   ! contract_extra_fields.csv ohne fieldable_type — es wird '" . VERTRAG_ALIAS . "' angenommen (so schreibt die App heute).\n");
    }
    $vertragIds = isset($quellen['rec_contracts'])
        ? array_flip(array_map(fn ($z) => (string) $z['id'], $quellen['rec_contracts'][1]))
        : null;
    foreach ($zZeilen as $z) {
        $name = trim((string) $z['name']);
        $typ = trim((string) ($z['fieldable_type'] ?? VERTRAG_ALIAS));
        $wert = $z['value'] === null ? null : trim((string) $z['value']);
        if (!array_key_exists($name, ZUSATZFELDER)) {
            $zusatzVerworfen['fremdes_feld']++;
            continue;
        }
        if (!in_array($typ, [VERTRAG_ALIAS, VERTRAG_KLASSE], true)) {
            $zusatzVerworfen['fremder_typ']++;
            continue;
        }
        if ($wert !== null && !preg_match(ZUSATZFELDER[$name], $wert)) {
            $zusatzVerworfen['wertform']++;
            continue;
        }
        if (!ctype_digit((string) $z['fieldable_id'])) {
            $zusatzVerworfen['wertform']++;
            continue;
        }
        if ($vertragIds !== null && !isset($vertragIds[(string) $z['fieldable_id']])) {
            // Gehoert zu keinem exportierten Vertrag — kein Schaden, aber
            // Ballast; bleibt draussen und wird gezaehlt.
            $zusatzVerworfen['ohne_vertrag']++;
            continue;
        }
        $zusatz[] = ['name' => $name, 'typ' => $typ, 'id' => (string) (int) $z['fieldable_id'], 'wert' => $wert];
    }
    if ($zKaputt > 0) {
        fwrite(STDERR, "   ! contract_extra_fields.csv: {$zKaputt} Zeilen mit falscher Feldzahl uebersprungen\n");
    }
}

// ---------------------------------------------------------------------------
// Selbstpruefung: haelt der Dump, was er verspricht? Laeuft VOR der Ausgabe.
// ---------------------------------------------------------------------------

$fehler = [];
$warnungen = [];
$mitarbeiter = $quellen['rec_employees'][1];
$mitarbeiterNeu = $ergebnis['rec_employees']['werte'];

// --- 1. Telefon und IBAN in rec_employees (wie bisher) ---
$telefonwerte = [];
foreach ($mitarbeiterNeu as $z) {
    if (!empty($z['phone']) && !str_starts_with((string) $z['phone'], TELEFON_PRAEFIX)
        && !str_starts_with((string) $z['phone'], '0')) {
        $fehler[] = 'Telefonnummer ausserhalb des Ersatzbereichs (rec_employees.phone)';
    }
    if (!empty($z['iban']) && !str_starts_with((string) $z['iban'], 'DE00')) {
        $fehler[] = 'IBAN nicht ersetzt (rec_employees.iban)';
    }
    if (!empty($z['phone'])) {
        $telefonwerte[] = $z['phone'];
    }
}

// --- 2. Nummerngruppen (wie bisher) ---
$suffixe = [];
foreach ($mitarbeiter as $z) {
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
if ($vorherPaare !== $nachherPaare) {
    $fehler[] = 'Die Zahl der geteilten Nummern hat sich veraendert — die Ersetzung ist nicht eindeutig zuordnend.';
}

// --- 3. Echte Werte, die nirgends mehr auftauchen duerfen ---
// Namen: Woerter (Buchstabenfolgen) ab 4 Zeichen aus Vor-, Nach- und
// Geburtsnamen. rec_applicants hat keine Namensspalten (Namen liegen in CRM
// und Zusatzfeldern, die nicht mitkommen) — also nur rec_employees. Woerter,
// die selbst im Ersatz-Pool stehen, sind vom Ersatz nicht zu unterscheiden
// und werden ausgenommen (gezaehlt).
$poolWoerter = array_flip(array_map('mb_strtolower', array_merge($vornamen, $nachnamen, ORTE)));
$echteNamen = [];
$poolGleich = 0;
foreach ($mitarbeiter as $z) {
    foreach (['first_name', 'last_name', 'birth_name'] as $s) {
        preg_match_all('/\p{L}+/u', (string) ($z[$s] ?? ''), $m);
        foreach ($m[0] as $wort) {
            $wort = mb_strtolower($wort);
            if (mb_strlen($wort) < 4) {
                continue;
            }
            if (isset($poolWoerter[$wort])) {
                $poolGleich++;
                continue;
            }
            $echteNamen[$wort] = true;
        }
    }
}
// Telefon-Endungen (9 Ziffern) und E-Mail-Adressen, IBAN aus dem Original.
$echteEndungen = [];
$echteMails = [];
$echteIban = [];
foreach ($mitarbeiter as $z) {
    $d = preg_replace('/\D+/', '', (string) ($z['phone'] ?? ''));
    if (strlen($d) >= 9) {
        $echteEndungen[substr($d, -9)] = true;
    }
    if (!leer($z['email'] ?? null)) {
        $echteMails[mb_strtolower(trim((string) $z['email']))] = true;
    }
    if (!leer($z['iban'] ?? null)) {
        $echteIban[preg_replace('/\s+/', '', strtoupper((string) $z['iban']))] = true;
    }
}
foreach ($quellen['rec_dispo_assignments'][1] ?? [] as $z) {
    $d = preg_replace('/\D+/', '', (string) ($z['reminder_sent_to'] ?? ''));
    if (strlen($d) >= 9) {
        $echteEndungen[substr($d, -9)] = true;
    }
}

$katalogTreffer = [];
foreach ($ergebnis as $tabelle => $e) {
    $katalog = TABELLEN[$tabelle]['katalog'];
    foreach ($e['werte'] as $z) {
        foreach ($z as $spalte => $w) {
            if ($w === null || $w === '') {
                continue;
            }
            $w = (string) $w;
            $ort = "{$tabelle}.{$spalte} (id " . ($z['id'] ?? '?') . ')';

            if (preg_match_all('/\p{L}+/u', $w, $m)) {
                foreach ($m[0] as $wort) {
                    if (isset($echteNamen[mb_strtolower($wort)])) {
                        // Den Namen selbst NICHT ausgeben — die Meldung landet
                        // im Terminal, vielleicht in einem Ticket.
                        if (in_array($spalte, $katalog, true)) {
                            $katalogTreffer["{$tabelle}.{$spalte}"] = ($katalogTreffer["{$tabelle}.{$spalte}"] ?? 0) + 1;
                        } else {
                            $fehler[] = "Echter Name in {$ort}";
                        }
                        break;
                    }
                }
            }

            if (str_contains($w, '@')) {
                preg_match_all('/[\w.+\-]+@[\w\-]+(\.[\w\-]+)+/u', $w, $m);
                foreach ($m[0] as $mail) {
                    $mail = mb_strtolower($mail);
                    if (isset($echteMails[$mail])) {
                        $fehler[] = "Echte E-Mail-Adresse in {$ort}";
                    } elseif (!str_ends_with($mail, '@example.invalid') && !in_array($spalte, $katalog, true)) {
                        $fehler[] = "E-Mail-Adresse ausserhalb des Ersatzbereichs in {$ort}";
                    }
                }
            }

            if (preg_match_all('/\+?\d[\d \/\-()]{7,}\d/', $w, $m)) {
                foreach ($m[0] as $lauf) {
                    $d = preg_replace('/\D+/', '', $lauf);
                    for ($i = 0; $i + 9 <= strlen($d); $i++) {
                        if (isset($echteEndungen[substr($d, $i, 9)])) {
                            $fehler[] = "Echte Telefonnummer in {$ort}";
                            break 2;
                        }
                    }
                }
            }

            if (preg_match_all('/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]){11,30}\b/', $w, $m)) {
                foreach ($m[0] as $iban) {
                    if (isset($echteIban[preg_replace('/\s+/', '', $iban)])) {
                        $fehler[] = "Echte IBAN in {$ort}";
                    }
                }
            }
        }
    }
}
foreach ($katalogTreffer as $wo => $n) {
    $warnungen[] = "{$n}x ein Wort, das auch ein echter Mitarbeiter-Name ist, in {$wo} (Katalogspalte — Kundentext/Taetigkeit; Stichprobe ansehen)";
}

// --- 4. Personalnummern und Einsaetze ---
// a) Keine echte Nummer bleibt stehen (ausser Fixpunkten/Platzhaltern).
$fixpunkte = array_merge(PLATZHALTER, FESTE_ZIFFERN);
$istFixpunkt = function (string $nr) use ($fixpunkte): bool {
    $t = zerlegeNummer($nr);

    return in_array($nr, $fixpunkte, true) || ($t !== null && in_array($t[1], FESTE_ZIFFERN, true))
        || ($t !== null && istLangeForm($t[1]) && in_array((string) ((int) $t[1] - KUERZUNGS_SCHWELLE), FESTE_ZIFFERN, true));
};
foreach ($mitarbeiter as $i => $z) {
    $alt = trim((string) ($z['personnel_number'] ?? ''));
    $neu = trim((string) ($mitarbeiterNeu[$i]['personnel_number'] ?? ''));
    if ($alt !== '' && $alt === $neu && !$istFixpunkt($alt)) {
        $fehler[] = "Personalnummer nicht ersetzt (rec_employees id {$z['id']})";
    }
}

// b) Jeder Einsatz trifft auf der Demo denselben Mitarbeiter wie auf prod —
//    geprueft mit dem echten ZasDispoMatcher (eigener Praefix wie die
//    Vorgabe der App). Karte wie DispoEmployeeDirectory::map(): Nummer → id,
//    bei doppelter Nummer gewinnt die spaetere Zeile.
$einsatzStatistik = null;
if (isset($ergebnis['rec_dispo_assignments'])) {
    $karteAlt = [];
    $karteNeu = [];
    foreach ($mitarbeiter as $i => $z) {
        if (!leer($z['personnel_number'] ?? null)) {
            $karteAlt[(string) $z['personnel_number']] = (int) $z['id'];
        }
        if (!leer($mitarbeiterNeu[$i]['personnel_number'] ?? null)) {
            $karteNeu[(string) $mitarbeiterNeu[$i]['personnel_number']] = (int) $z['id'];
        }
    }
    $abgleichAlt = new ZasDispoMatcher($karteAlt, ZasPersonnelNumber::DEFAULT_PREFIX);
    $abgleichNeu = new ZasDispoMatcher($karteNeu, ZasPersonnelNumber::DEFAULT_PREFIX);

    $einsaetzeAlt = $quellen['rec_dispo_assignments'][1];
    $einsaetzeNeu = $ergebnis['rec_dispo_assignments']['werte'];
    $treffAlt = 0;
    $treffNeu = 0;
    $zugeordnetAlt = 0;
    $zugeordnetNeu = 0;
    $ersatzNummern = array_flip(array_keys($karteNeu));
    foreach ($einsaetzeAlt as $i => $alt) {
        $neu = $einsaetzeNeu[$i];
        $ma = $abgleichAlt->match($alt['pnr_raw'] ?? null);
        $mn = $abgleichNeu->match($neu['pnr_raw'] ?? null);
        $treffAlt += $ma['employee_id'] !== null ? 1 : 0;
        $treffNeu += $mn['employee_id'] !== null ? 1 : 0;
        $zugeordnetAlt += leer($alt['rec_employee_id'] ?? null) ? 0 : 1;
        $zugeordnetNeu += leer($neu['rec_employee_id'] ?? null) ? 0 : 1;
        if ($ma['employee_id'] !== $mn['employee_id'] || $ma['reason'] !== $mn['reason']) {
            $fehler[] = "Einsatz id {$alt['id']}: Abgleich trifft auf der Demo anders als auf prod ({$ma['reason']} → {$mn['reason']})";
        }

        // Jede pnr ist ein Ersatz aus rec_employees (oder dessen eigene/
        // gekuerzte Form), DUMP-U* oder Platzhalter — nie ein Original.
        $p = trim((string) ($neu['pnr_raw'] ?? ''));
        if ($p === '' || in_array($p, PLATZHALTER, true) || str_starts_with($p, PERSONALNUMMER_PRAEFIX . 'U')) {
            continue;
        }
        $t = zerlegeNummer($p);
        $gueltig = $t !== null && (isset($ersatzNummern[$p]) || $mn['employee_id'] !== null || $istFixpunkt($p)
            || in_array($t[1], $karten['pnr'], true)
            || (istLangeForm($t[1]) && in_array((string) ((int) $t[1] - KUERZUNGS_SCHWELLE), $karten['pnr'], true)));
        if (!$gueltig || ($p === trim((string) ($alt['pnr_raw'] ?? '')) && !$istFixpunkt($p))) {
            $fehler[] = "Einsatz id {$alt['id']}: pnr_raw ist kein Ersatz";
        }
    }
    if ($zugeordnetAlt !== $zugeordnetNeu) {
        $fehler[] = "Einsaetze mit zugeordnetem MA: vorher {$zugeordnetAlt}, nachher {$zugeordnetNeu}";
    }
    if ($treffAlt !== $treffNeu) {
        $fehler[] = "Abgleich-Treffer: vorher {$treffAlt}, nachher {$treffNeu}";
    }
    $einsatzStatistik = [$zugeordnetAlt, $zugeordnetNeu, $treffAlt, $treffNeu];
}

// ---------------------------------------------------------------------------
// Bericht
// ---------------------------------------------------------------------------

fwrite(STDERR, "\n--- Zusammenfassung je Tabelle ---\n");
foreach ($ergebnis as $tabelle => $e) {
    fwrite(STDERR, sprintf("%-24s %6d Zeilen, %2d bekannte Spalten geleert, %d unbekannte Spalten geleert%s%s\n",
        $tabelle, count($e['werte']), $e['geleert'], count($e['unbekannt']),
        $e['kennwort_verworfen'] > 0 ? ", {$e['kennwort_verworfen']} Freitext-Werte in Kennwort-Spalten geleert" : '',
        $e['kaputt'] > 0 ? ", !! {$e['kaputt']} Zeilen mit falscher Feldzahl uebersprungen" : ''));
    if ($e['unbekannt'] !== []) {
        fwrite(STDERR, "   !! UNBEKANNTE SPALTEN — GELEERT (fail closed): " . implode(', ', $e['unbekannt']) . "\n");
    }
}
if ($zusatzQuelle !== null) {
    fwrite(STDERR, sprintf("%-24s %6d Werte uebernommen; verworfen: %d fremdes Feld, %d fremder Typ, %d unerwartete Wertform, %d ohne exportierten Vertrag\n",
        'contract_extra_fields', count($zusatz), $zusatzVerworfen['fremdes_feld'], $zusatzVerworfen['fremder_typ'],
        $zusatzVerworfen['wertform'], $zusatzVerworfen['ohne_vertrag']));
}
if (array_filter(array_column($ergebnis, 'unbekannt')) !== []) {
    fwrite(STDERR, "   Pruef, ob eine unbekannte Spalte fuer den Test gebraucht wird, und trag sie\n");
    fwrite(STDERR, "   dann oben in TABELLEN (behalten/ersetzen) ein.\n");
}
if (!$mitBewerbern) {
    fwrite(STDERR, "Ohne rec_applicants.csv: rec_employees.rec_applicant_id wird geleert.\n");
}

fwrite(STDERR, "\n--- Selbstpruefung ---\n");
fwrite(STDERR, sprintf("Nummerngruppen vorher: %d, nachher: %d %s\n",
    $vorherPaare, $nachherPaare, $vorherPaare === $nachherPaare ? '(gleich, gut)' : '!! WEICHT AB'));
fwrite(STDERR, sprintf("Namenswoerter geprueft: %d (%d stehen selbst im Ersatz-Pool und sind ausgenommen)\n",
    count($echteNamen), $poolGleich));
if ($einsatzStatistik !== null) {
    fwrite(STDERR, sprintf("Einsaetze mit MA vorher/nachher: %d/%d, Abgleich-Treffer vorher/nachher: %d/%d\n", ...$einsatzStatistik));
}
foreach ($warnungen as $w) {
    fwrite(STDERR, "   Hinweis: {$w}\n");
}

if ($fehler !== []) {
    fwrite(STDERR, "\n!! FEHLER — es wurde KEINE SQL ausgegeben:\n");
    $gezaehlt = array_count_values($fehler);
    foreach (array_slice($gezaehlt, 0, 50, true) as $f => $n) {
        fwrite(STDERR, "   - {$f}" . ($n > 1 ? " ({$n}x)" : '') . "\n");
    }
    if (count($gezaehlt) > 50) {
        fwrite(STDERR, '   … und ' . (count($gezaehlt) - 50) . " weitere\n");
    }
    exit(1);
}

// ---------------------------------------------------------------------------
// Schreiben — erst jetzt, nach bestandener Pruefung
// ---------------------------------------------------------------------------

echo "-- Pseudonymisierter Bestands-Dump, erzeugt am " . date('Y-m-d H:i') . "\n";
echo "-- Tabellen: " . implode(', ', array_keys($ergebnis)) . ($zusatzQuelle !== null ? ', Vertrags-Zusatzfelder' : '') . "\n";
echo "-- Geburtsdaten sind um " . GEBURTSTAG_VERSCHIEBUNG . " Tage verschoben.\n";
echo "-- Anmeldung im alten Portal: Token dump-<id>, verschobenes Geburtsdatum + Ausweis-Endziffern 4711\n";
echo "-- Telefonnummern liegen im Bereich " . TELEFON_PRAEFIX . " (gueltiges Format! Demo ohne Meta-Vorlagen lassen).\n";
echo "-- Personalnummern: Firmen-Praefix echt, Ziffern ab " . ERSATZ_NUMMERN_BASIS . "; " . implode('/', PLATZHALTER) . " unveraendert.\n";
echo "-- Alle Datensaetze haengen an Mandant " . $zielTeam . ".\n\n";
echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

foreach ($ergebnis as $tabelle => $e) {
    echo "TRUNCATE TABLE {$tabelle};\n";
    if ($e['werte'] === []) {
        echo "\n";
        continue;
    }
    $spaltenListe = '`' . implode('`, `', $e['spalten']) . '`';
    foreach (array_chunk($e['werte'], 100) as $block) {
        $tupel = [];
        foreach ($block as $zeile) {
            $felder = [];
            foreach ($e['spalten'] as $spalte) {
                $w = $zeile[$spalte];
                $felder[] = sqlWert($w === null ? null : (string) $w);
            }
            $tupel[] = '(' . implode(', ', $felder) . ')';
        }
        echo "INSERT INTO {$tabelle} ({$spaltenListe}) VALUES\n" . implode(",\n", $tupel) . ";\n\n";
    }
}

if ($zusatzQuelle !== null) {
    $typen = sqlWert(VERTRAG_ALIAS) . ', ' . sqlWert(VERTRAG_KLASSE);
    echo "-- Vertrags-Zusatzfelder (" . implode(', ', array_keys(ZUSATZFELDER)) . ").\n";
    echo "-- Die Definition wird auf DIESER Datenbank ueber Name + Mandant gesucht.\n";
    echo "-- Bricht das INSERT mit \"Column 'definition_id' cannot be null\" ab, fehlen die\n";
    echo "-- Definitionen: php artisan recruiting:seed-rec-contract-extra-fields, dann die\n";
    echo "-- ganze Datei noch einmal einspielen (sie ist wiederholbar).\n";
    echo "DELETE FROM core_extra_field_values WHERE fieldable_type IN ({$typen});\n\n";
    // Je Zeile eine Unterabfrage statt einer abgeleiteten Tabelle aus
    // Literalen: so wird der Name direkt gegen die Spalte verglichen und
    // uebernimmt deren Kollation — eine abgeleitete Tabelle kann auf MySQL 8
    // "Illegal mix of collations" werfen.
    foreach (array_chunk($zusatz, 100) as $block) {
        $zeilen = [];
        foreach ($block as $z) {
            $zeilen[] = sprintf("((SELECT d.id FROM core_extra_field_definitions d WHERE d.team_id = %d AND d.context_type IN (%s) AND d.context_id IS NULL AND d.name = %s ORDER BY d.id LIMIT 1), %s, %d, %s, NOW(), NOW())",
                $zielTeam, $typen, sqlWert($z['name']), sqlWert($z['typ']), (int) $z['id'], sqlWert($z['wert']));
        }
        echo "INSERT INTO core_extra_field_values (definition_id, fieldable_type, fieldable_id, value, created_at, updated_at) VALUES\n"
            . implode(",\n", $zeilen) . ";\n\n";
    }
    echo "-- Pruefzeile: erwartet " . count($zusatz) . " Werte, ohne_definition muss 0 sein.\n";
    echo "SELECT COUNT(*) AS eingespielt, SUM(d.id IS NULL) AS ohne_definition\n"
        . "  FROM core_extra_field_values v\n"
        . "  LEFT JOIN core_extra_field_definitions d ON d.id = v.definition_id\n"
        . " WHERE v.fieldable_type IN ({$typen});\n\n";
}

echo "SET FOREIGN_KEY_CHECKS = 1;\n";

fwrite(STDERR, "Keine echten Namen, Nummern, Adressen, IBAN im Ergebnis.\n");
fwrite(STDERR, "Fertig.\n");
