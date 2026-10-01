<?php

return [
    'name' => 'Recruiting',
    'description' => 'Recruiting Module',
    'version' => '1.0.0',

    'routing' => [
        'prefix' => 'recruiting',
        'middleware' => ['web', 'auth'],
    ],

    'guard' => 'web',

    'navigation' => [
        'main' => [
            'recruiting' => [
                'title' => 'Recruiting',
                'icon' => 'heroicon-o-briefcase',
                'route' => 'recruiting.dashboard',
            ],
        ],
    ],

    'sidebar' => [
        [
            'group' => 'Recruiting',
            'items' => [
                ['label' => 'Dashboard',       'route' => 'recruiting.dashboard',                'icon' => 'heroicon-o-home'],
                ['label' => 'Stellen',         'route' => 'recruiting.positions.index',          'icon' => 'heroicon-o-briefcase'],
                ['label' => 'Ausschreibungen', 'route' => 'recruiting.postings.index',           'icon' => 'heroicon-o-megaphone'],
                ['label' => 'Bewerber',        'route' => 'recruiting.applicants.index',         'icon' => 'heroicon-o-user-group'],
                ['label' => 'Eingangs-Inbox',  'route' => 'recruiting.inbox.index',              'icon' => 'heroicon-o-inbox'],
                ['label' => 'WhatsApp-Kosten', 'route' => 'recruiting.whatsapp-costs.index', 'icon' => 'heroicon-o-banknotes'],
            ],
        ],
        [
            'group' => 'Disposition',
            'items' => [
                ['label' => 'ZAS-Eingang', 'route' => 'recruiting.dispo.index', 'icon' => 'heroicon-o-inbox-arrow-down'],
            ],
        ],
    ],
    'billables' => [
        [
            'model' => \Platform\Recruiting\Models\RecPosting::class,
            'type' => 'per_item',
            'label' => 'Stellenausschreibung',
            'description' => 'Jede erstellte Stellenausschreibung verursacht tägliche Kosten nach Nutzung.',
            'pricing' => [
                ['cost_per_day' => 0.005, 'start_date' => '2025-01-01', 'end_date' => null]
            ],
            'free_quota' => null,
            'min_cost' => null,
            'max_cost' => null,
            'billing_period' => 'daily',
            'start_date' => '2026-01-01',
            'end_date' => null,
            'trial_period_days' => 0,
            'discount_percent' => 0,
            'exempt_team_ids' => [],
            'priority' => 100,
            'active' => true,
        ],
        [
            'model' => \Platform\Recruiting\Models\RecApplicant::class,
            'type' => 'per_item',
            'label' => 'Bewerber',
            'description' => 'Jeder angelegte Bewerber verursacht tägliche Kosten nach Nutzung.',
            'pricing' => [
                ['cost_per_day' => 0.0025, 'start_date' => '2025-01-01', 'end_date' => null]
            ],
            'free_quota' => null,
            'min_cost' => null,
            'max_cost' => null,
            'billing_period' => 'daily',
            'start_date' => '2026-01-01',
            'end_date' => null,
            'trial_period_days' => 0,
            'discount_percent' => 0,
            'exempt_team_ids' => [],
            'priority' => 100,
            'active' => true,
        ],
    ],

    'whatsapp_costs' => [
        // Meta Utility-Template an DE-Empfänger, direkt über Cloud API. Stand 04/2026.
        // Bei Meta-Ratenänderung hier anpassen (kein Hardcoding im Code).
        'price_per_delivered_template' => 0.055,
        // Service-Aufschlag in Prozent, der auf den Meta-Basispreis kommt. Der dem
        // Kunden angezeigte Preis enthält diesen Aufschlag bereits (nicht separat ausgewiesen).
        'fee_percent' => 30,
        'currency' => 'EUR',
    ],

    /*
    |--------------------------------------------------------------------------
    | ZAS Bewerber-Export
    |--------------------------------------------------------------------------
    |
    | Konfiguration fuer den ZAS-Pull-Endpoint (externes IBEI-HR-System).
    | Siehe docs/meingedeck/zas-applicant-export.md
    |
    | - token:                Bearer-Token fuer Authorization-Header. Pflicht.
    |                         Lange Zufallsstring (>= 32 Zeichen). Niemals per
    |                         Klartext-Mail uebergeben — Bitwarden o. ä.
    | - signed_url_secret:    HMAC-Sekret fuer die Datei-URLs. Pflicht.
    |                         Bei Rotation werden alle bestehenden Foto-Links
    |                         in ZAS sofort ungueltig — also nur rotieren
    |                         wenn man weiss was man tut.
    | - signed_url_ttl_days:  Lebensdauer der Foto-Links. ZAS sollte die
    |                         Dateien beim Pull sofort lokal kopieren —
    |                         URLs sind nicht fuer Langzeit-Persistenz.
    | - export_min_phase_order:
    |                         Optional zusaetzliches Phase-Gate. NULL =
    |                         deaktiviert; primaerer Filter ist sowieso
    |                         "Bewerber hat versendeten Vertrag". Falls
    |                         spaeter strenger gefiltert werden soll
    |                         (z. B. nur Phase >= 4 in der neuen Logik).
    */
    'zas' => [
        'token'                  => env('RECRUITING_ZAS_TOKEN'),
        'signed_url_secret'      => env('RECRUITING_ZAS_SIGNED_URL_SECRET'),
        'signed_url_ttl_days'    => (int) env('RECRUITING_ZAS_SIGNED_URL_TTL_DAYS', 7),
        'export_min_phase_order' => env('RECRUITING_ZAS_EXPORT_MIN_PHASE_ORDER'),

        // Storage-Disk fuer von ZAS eingehende CSVs (POST /recruiting/zas/inbound).
        // Default 'local' = privat, nicht oeffentlich erreichbar.
        'inbound_disk'           => env('RECRUITING_ZAS_INBOUND_DISK', 'local'),

        // Team, dem von ZAS importierte Mitarbeiter zugeordnet werden (Pflicht fuer Import).
        'inbound_team_id'        => env('RECRUITING_ZAS_INBOUND_TEAM_ID'),

        // ZAS-Bestand (aus einer Lieferung entstanden, keine Bewerbung) wird in
        // ZAS gepflegt: bei true uebernimmt der Inbound jeden gelieferten,
        // abweichenden Wert (ausser Ausweisnummer, Land, Personalnummer, Firma).
        // Standardmaessig AN. Die env-Variable ist nur der Notaus (=false), ohne
        // neues Deploy — und am Golive-Tag des MA-Portals fuer alle: dann pflegen wir.
        // Pruefen: recruiting:zas-inbound-reprocess <id> --dry-run --overwrite
        'inbound_overwrite_zas_owned' => (bool) env('RECRUITING_ZAS_INBOUND_OVERWRITE_ZAS_OWNED', true),

        // Taetigkeitsbezeichnungen ({Dispo}-Feld 'taetigkeit'), die einen Mitarbeiter
        // als Ansprechpartner vor Ort qualifizieren (exakter Vergleich, Gross/Klein
        // egal). Erweiterbar ohne Code, z. B. 'Borussia Teamleiter'. Kunden-Regel:
        // "Wer Ansprechpartner sein soll, wird in ZAS als Teamleitung disponiert."
        'dispo_lead_taetigkeiten' => ['Teamleitung'],

        // Chat-Vorlagen der Dispo (Kunde 01.09., Meta-genehmigt 02.09.): AUSSCHLIESSLICH
        // diese drei sind aus der Kommunikation/dem VA-Chat versendbar. label = was der
        // Enduser sieht; template = Meta-Name; Variable {{name}} = Vorname des MA.
        'dispo_chat_templates' => [
            ['key' => 'init', 'label' => 'Gespräch starten',  'template' => 't_init'],
            ['key' => 'wann', 'label' => 'Wann bist du da?',  'template' => 't_wo_bist'],
            ['key' => 'wo',   'label' => 'Wo bist du?',       'template' => 'wo_bist_du'],
        ],

        // Maximale Datenzeilen pro Inbound-Lieferung. Die Verarbeitung laeuft
        // synchron im Request (gemessen: ~2-3 Sekunden pro 100 Zeilen), eine zu
        // grosse Lieferung liefe in den nginx/PHP-Timeout. Absprache mit ZAS
        // sind Pakete a rund 100 Zeilen; der Wert hier ist die harte Grenze.
        // 0 schaltet den Waechter ab (Notausgang fuer eine Sonderlieferung).
        // Abgewiesene Lieferungen bleiben roh gespeichert und lassen sich per
        // recruiting:zas-inbound-reprocess portionsweise verarbeiten.
        'inbound_max_rows'       => (int) env('RECRUITING_ZAS_INBOUND_MAX_ROWS', 300),

        // Eigener Firmen-Praefix an der ZAS-Personalnummer.
        //
        // ZAS bedient zwei Firmen (RG und MA) und vergibt in beiden dieselben
        // Ziffernfolgen — 276, 322, 325 und 353 existieren doppelt. Die
        // Dispo-Lieferung traegt den Praefix seit jeher (`RG353`), der
        // Mitarbeiter-Export wird darauf umgestellt. Bis dahin kommen dort
        // blanke Nummern, die wir beim Import selbst praefixen; damit gibt es
        // keinen Stichtag, an dem beide Seiten gleichzeitig umschalten muessen.
        //
        // Eine Nummer OHNE Praefix gilt ueberall als die eigene Firma. Ein
        // fremder Praefix wird nie ueberschrieben und trifft in der
        // Dispo-Zuordnung auch nie einen unserer Mitarbeiter.
        // Leerer Wert schaltet Normalisierung und Praefix-Matching ab.
        'company_prefix'         => env('RECRUITING_ZAS_COMPANY_PREFIX', \Platform\Recruiting\Support\ZasPersonnelNumber::DEFAULT_PREFIX),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mitarbeiter-Konto (Canvas 68)
    |--------------------------------------------------------------------------
    |
    | Gelesen wird der Pfeffer ausschliesslich von
    | Platform\Recruiting\Services\KontoWriter — die Regel-Klassen
    | (EinladungsToken, Einmalcode) bekommen ihn hereingereicht und bleiben
    | dadurch rein und ohne Framework testbar (Ruling GD-5).
    */
    'konto' => [
        // Pfeffer fuer Einladungs-Token und Einmalcode. NICHT fuer das Passwort.
        // Faellt bewusst auf app.key zurueck: der steht auch nicht in der Datenbank,
        // ist auf jedem Host gesetzt, und so kann kein vergessener .env-Eintrag die
        // Kontoanlage auf prod stillegen. Ein eigener Wert geht vor, wenn gesetzt.
        //
        // Ein Wechsel des Pfeffers (oder des APP_KEY) macht offene Einladungen und
        // laufende Codes ungueltig — sieben Tage beziehungsweise zehn Minuten.
        // Passwoerter beruehrt er nicht, die haengen an Hash::make(); waere das
        // Passwort mitgepfeffert, sperrte derselbe Verlust jeden Mitarbeiter
        // DAUERHAFT aus.
        'pepper' => env('RECRUITING_KONTO_PEPPER') ?: config('app.key'),

        // WEG 4 (Spec 5: Nummer weg UND Passwort vergessen) IST AUS.
        // Vorgabe false, ausdruecklich und nicht als Nebenwirkung.
        //
        // WARUM DIESER SCHALTER EXISTIERT. Die Schlusspruefung zu Aufgabe 9
        // hat festgehalten: Weg 4 darf nicht scharf sein, bevor die
        // HR-Meldung gebaut ist. Gehalten wurde dieser Riegel bis zur
        // Schlussrunde allein davon, dass RECRUITING_KONTO_VORLAGE_NOTFALL
        // leer ist und der Sender ohne Vorlagennamen nicht verschickt. Das
        // ist ein Zufall und kein Riegel: wer irgendwann alle vier Vorlagen
        // auf einmal eintraegt, schaltet Weg 4 mit, ohne es zu merken — und
        // Weg 4 gefolgt von Weg 3 ergibt die volle Uebernahme eines Kontos
        // allein aus Nummer, Geburtsdatum und Ausweisziffern, verzoegert um
        // vierundzwanzig Stunden.
        //
        // WAS ERFUELLT SEIN MUSS, BEVOR JEMAND HIER true EINTRAEGT:
        //  1. Die HR-Meldung aus Aufgabe 10 wird WIRKLICH GELESEN. Nicht
        //     "sie existiert": es muss verabredet sein, WER
        //     recruiting:konto-einladen --bericht (oder
        //     recruiting:konto-zuruecksetzen --offen) wie oft ansieht. Die
        //     vierundzwanzig Stunden sind nur dann ein Stopp-Recht, wenn in
        //     ihnen jemand hinsieht; sonst ist Weg 4 ein stiller
        //     Selbstbedienungs-Uebernahmeweg.
        //  2. Die Meta-Vorlage aus 'code_vorlagen.notfall' ist genehmigt und
        //     eingetragen — sonst ist der Schalter zwar an, aber es geht
        //     ohnehin kein Code raus.
        //  3. Es ist entschieden, ob der Bericht als Meldung genuegt oder ob
        //     eine echte Mail gebraucht wird (offene Frage aus Aufgabe 9;
        //     event@ ist ein Sammelkonto).
        //
        // IST ER AUS, verhaelt sich Weg 4 wie ein FEHLGESCHLAGENER VERSUCH —
        // dieselbe Seite, derselbe Text, kein Versand. Eine eigene Meldung
        // ("dieser Weg ist abgeschaltet") waere selbst eine Auskunft und
        // machte den Schalter nach aussen sichtbar. Dass es jemand versucht
        // hat, steht im Log (recruiting.konto.weg4_abgeschaltet).
        'weg4_aktiv' => (bool) env('RECRUITING_KONTO_WEG4_AKTIV', false),

        // Der Versand des Einmalcodes (Platform\Recruiting\Services\Comms\EinmalcodeSender).
        //
        // WARUM DAS HIER STEHT UND NICHT IM CODE: die Meta-Vorlagen sind noch nicht
        // genehmigt — Namen und Platzhalter stehen nicht sicher fest. Fest verdrahtet
        // braeuchte jede Aenderung ein Deploy; so genuegt ein .env-Eintrag. Muster sind
        // die ZAS- und Flynk-Bloecke weiter oben.
        //
        // OHNE NAMEN WIRD NICHT VERSCHICKT, und der Sender sagt im Log, welcher
        // Schluessel fehlt. Das ist der Zustand bis zur Freigabe bei Meta und bewusst
        // kein stiller Fehlschlag: ein Versand mit geratenem Vorlagennamen waere bei
        // Meta ohnehin abgelehnt, nur ohne lesbaren Grund.
        //
        // - name:        der bei Meta genehmigte Vorlagenname.
        // - sprache:     der Sprachcode der genehmigten Fassung (Meta unterscheidet sie).
        // - platzhalter: die Body-Platzhalter der Vorlage, IN IHRER REIHENFOLGE.
        //                Befuellbar sind {{code}} (der Einmalcode), {{minuten}} (seine
        //                Gueltigkeitsdauer) und der Vorname ({{name}}, {{vorname}}).
        //                Ein anderer Name verhindert den Versand — sonst stuende dort
        //                still der Vorname statt des Codes, und Meta naehme die
        //                Nachricht an. {{code}} ist Pflicht.
        //
        // ANFORDERUNG AN DIE META-VORLAGE: BENANNTE Platzhalter, kein {{1}}. Meta
        // laesst benannte und positionelle Platzhalter nicht in derselben Vorlage zu;
        // weil {{code}} benannt sein muss, kann eine Code-Vorlage gar nicht
        // positionell sein. Wer trotzdem eine positionelle beantragt, bekommt sie
        // genehmigt und dann von Meta jede Nachricht abgelehnt — und braucht am Ende
        // genau das Deploy, das dieser Block vermeiden soll.
        //
        // Drei Zwecke, weil der Text sich unterscheidet ("Konto einrichten" gegen
        // "neue Nummer bestaetigen"). Genehmigt Meta nur EINE Vorlage, traegt man
        // ueberall denselben Namen ein.
        'code_vorlagen' => [
            'anmeldung' => [
                'name'        => env('RECRUITING_KONTO_VORLAGE_ANMELDUNG', ''),
                'sprache'     => env('RECRUITING_KONTO_VORLAGE_SPRACHE', 'de'),
                'platzhalter' => ['code'],
            ],
            'passwort' => [
                'name'        => env('RECRUITING_KONTO_VORLAGE_PASSWORT', ''),
                'sprache'     => env('RECRUITING_KONTO_VORLAGE_SPRACHE', 'de'),
                'platzhalter' => ['code'],
            ],
            'nummernwechsel' => [
                'name'        => env('RECRUITING_KONTO_VORLAGE_NUMMERNWECHSEL', ''),
                'sprache'     => env('RECRUITING_KONTO_VORLAGE_SPRACHE', 'de'),
                'platzhalter' => ['code'],
            ],
            // Weg 4 aus Spec 5: Nummer weg UND Passwort vergessen. Eigener
            // Zweck, weil sein Code den Wechsel nur BEANTRAGT (24 Stunden,
            // HR kann stoppen) - der Text darf das sagen. Genehmigt Meta nur
            // EINE Vorlage, traegt man ueberall denselben Namen ein.
            'notfall' => [
                'name'        => env('RECRUITING_KONTO_VORLAGE_NOTFALL', ''),
                'sprache'     => env('RECRUITING_KONTO_VORLAGE_SPRACHE', 'de'),
                'platzhalter' => ['code'],
            ],
        ],

        // Der Hinweis AN DIE ALTE NUMMER, nachdem die Nummer gewechselt wurde
        // (Platform\Recruiting\Services\Comms\NummernwechselHinweisSender).
        //
        // Begleitregel aus Spec §5, Canvas 1789: "Die alte Nummer bekommt einmalig
        // einen Hinweis, dass die Nummer geaendert wurde, sofern noch zustellbar."
        // Sie ist die einzige Warnung, die ein Mensch bekommt, dem jemand das Konto
        // umgehaengt hat — und sie erreicht ihn auf dem Geraet, das er noch in der
        // Hand haelt.
        //
        // DIESE VORLAGE TRAEGT KEIN GEHEIMNIS und deshalb auch keinen Platzhalter,
        // der eines sein koennte. Befuellbar ist nur der Vorname ({{name}},
        // {{vorname}}); die NEUE Nummer steht bewusst NICHT darin — wer das alte
        // Geraet in der Hand hat, soll nicht auch noch erfahren, wohin das Konto
        // gewandert ist. Eine Vorlage ganz ohne Platzhalter ist der Normalfall.
        //
        // OHNE NAMEN WIRD NICHT VERSCHICKT — derselbe Zustand wie bei den
        // Code-Vorlagen bis zur Freigabe bei Meta. Der Wechsel selbst haengt nicht
        // daran: er ist zu diesem Zeitpunkt schon vollzogen.
        'hinweis_vorlage' => [
            'name'        => env('RECRUITING_KONTO_VORLAGE_HINWEIS', ''),
            'sprache'     => env('RECRUITING_KONTO_VORLAGE_SPRACHE', 'de'),
            'platzhalter' => [],
        ],

        // Vorlagennamen, die einmal einen Einmalcode getragen haben und es
        // heute nicht mehr tun.
        //
        // WOZU: der Chat unter /recruiting/conversations setzt die gesendeten
        // Werte wieder in den Vorlagentext ein. Fuer eine Code-Vorlage werden
        // sie geschwaerzt — aber eine Nachricht von gestern wurde mit der
        // Konfiguration von gestern verschickt. Faellt ein Name aus
        // code_vorlagen heraus, stuenden ALLE alten Nachrichten mit diesem
        // Namen wieder unmaskiert im Verlauf, und nichts wuerde davon rot.
        //
        // ANWEISUNG AN DEN NAECHSTEN: Wer eine Code-Vorlage umbenennt oder
        // ersetzt, traegt den ALTEN Namen hier ein. Diese Liste wird NIE
        // gekuerzt — ein Eintrag zu viel kostet nichts, ein fehlender kostet
        // den ganzen Verlauf.
        //
        // Der Sender liest diese Liste NICHT (er verschickt nur mit den
        // aktuellen Namen); nur die Schwaerzung liest beide.
        'code_vorlagen_alt' => [
            // 'konto_einmalcode_v1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Aufgaben-Nachricht (Spec §2.3, Aufgabe 9)
    |--------------------------------------------------------------------------
    |
    | Der Versand von Platform\Recruiting\Services\Comms\AufgabenSender: der
    | WhatsApp-Hinweis, dass im MA-Portal etwas fuer den naechsten Einsatz
    | offen ist. Zwei Anlaesse, weil der Text sich unterscheidet — die erste
    | Nachricht ("neu") und die Erinnerung, wenn der Einsatz naeher rueckt
    | ("erinnerung").
    |
    | WARUM DAS HIER STEHT UND NICHT IM CODE: dieselbe Begruendung wie bei
    | 'konto.code_vorlagen' oben — die Meta-Vorlagen sind noch nicht
    | genehmigt, Name und Platzhalter stehen nicht sicher fest. Ein fest
    | verdrahteter Name braeuchte nach der Freigabe ein Deploy; so genuegt
    | ein .env-Eintrag.
    |
    | OHNE NAMEN WIRD NICHT VERSCHICKT, und der Sender sagt im Log, welcher
    | Anlass betroffen ist — kein stiller Fehlschlag. Die Aufgabenliste im
    | Portal funktioniert davon unabhaengig: sie wird berechnet (OffenePunkte,
    | Aufgabe 8), nicht durch diese Nachricht getragen.
    |
    | - name:        der bei Meta genehmigte Vorlagenname.
    | - sprache:     der Sprachcode der genehmigten Fassung.
    | - platzhalter: die Body-Platzhalter der Vorlage, IN IHRER REIHENFOLGE.
    |                Befuellbar sind 'anzahl' (die Zahl der offenen Punkte)
    |                und 'datum' (der naechste Einsatz, nur bei 'erinnerung'
    |                sinnvoll). Ein anderer Name verhindert den Versand —
    |                DIE NACHRICHT NENNT DIE PUNKTE NICHT EINZELN (Spec §2.1:
    |                das Portal traegt die Aufgaben), nur ihre Anzahl und den
    |                Verweis aufs Portal. Waere die Nachricht der Traeger,
    |                muesste jede Aenderung eine neue erzeugen.
    */
    'aufgaben' => [
        'vorlagen' => [
            'neu' => [
                'name'        => env('RECRUITING_AUFGABEN_VORLAGE_NEU', ''),
                'sprache'     => env('RECRUITING_AUFGABEN_VORLAGE_SPRACHE', 'de'),
                'platzhalter' => ['anzahl'],
            ],
            'erinnerung' => [
                'name'        => env('RECRUITING_AUFGABEN_VORLAGE_ERINNERUNG', ''),
                'sprache'     => env('RECRUITING_AUFGABEN_VORLAGE_SPRACHE', 'de'),
                'platzhalter' => ['anzahl', 'datum'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Filialen (zentrale Zuordnung Nummer → Filiale)
    |--------------------------------------------------------------------------
    |
    | Die Filialnummer ist der kanonische Schluessel: sie kommt aus dem
    | ZAS-Webexport als {Dispo2} filial_nr UND liegt als rec_positions.cost_center
    | an den Recruiting-Stellen. Diese Map ist die eine Wahrheit fuer beide Welten
    | (aufgeloest ueber Platform\Recruiting\Support\Filialen).
    | 'code' = ZAS-Kuerzel (Anzeige, Kundenwunsch), 'name' = Klarname (Reserve).
    */
    'filialen' => [
        100 => ['code' => 'DUS & ES', 'name' => 'Düsseldorf & Essen'],
        200 => ['code' => 'MGL', 'name' => 'Mönchengladbach'],
        300 => ['code' => 'WUP', 'name' => 'Wuppertal'],
        400 => ['code' => 'CGN', 'name' => 'Köln'],
        1100 => ['code' => 'DUS-V', 'name' => 'Düsseldorf Verwaltung'],
    ],

    /*
    |--------------------------------------------------------------------------
    | FLYNK-Sync (Ausschreibungen → Website-Tasks)
    |--------------------------------------------------------------------------
    |
    | Ausgehender Sync veröffentlichter Ausschreibungen an den FLYNK
    | Task-Webhook. Ohne enabled=true + token passiert nichts.
    | Siehe docs/superpowers/specs/2026-07-06-flynk-ausschreibungen-sync-design.md
    */
    'flynk' => [
        'enabled'      => (bool) env('RECRUITING_FLYNK_ENABLED', false),
        'base_url'     => env('RECRUITING_FLYNK_BASE_URL', 'https://flynk.on-forge.com/api'),
        'token'        => env('RECRUITING_FLYNK_TOKEN'),
        'careers_url'  => env('RECRUITING_FLYNK_CAREERS_URL'),
        'timeout'      => (int) env('RECRUITING_FLYNK_TIMEOUT', 10),
        'per_run_cap'  => (int) env('RECRUITING_FLYNK_PER_RUN_CAP', 50),
        'max_attempts' => (int) env('RECRUITING_FLYNK_MAX_ATTEMPTS', 5),
    ],
];
