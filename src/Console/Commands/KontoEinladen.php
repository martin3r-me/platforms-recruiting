<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Services\KontoWriter;
use Platform\Recruiting\Support\NummernwechselTafel;
use Platform\Recruiting\Support\PhoneE164;
use Throwable;

/**
 * Der Knopf, mit dem HR einlaedt — und der Stand, an dem HR die Umstellung
 * abliest (Spec §3, Canvas 68).
 *
 * ER VERSCHICKT NICHTS. Die Meta-Vorlage fuer die Einladung ist nicht
 * genehmigt (Gate E). Dieses Kommando ERZEUGT die Einladung und ZEIGT sie;
 * weitergegeben wird sie von Hand. Daraus folgt eine Eigenheit, die unten
 * bei der Wiederholbarkeit noch einmal auftaucht: der Klartext des Codes
 * existiert ausschliesslich auf diesem Bildschirm — gespeichert ist nur sein
 * Hash.
 *
 * DER EINLADUNGSCODE IST DER LINK UND DER ABTIPPBARE CODE ZUGLEICH (Canvas
 * 68, Eintrag 1740, Ruling GD-4). Darum stehen in der Ausgabe beide Formen
 * desselben Wertes: wer am Handy ist, tippt den Link an; wer am Rechner
 * sitzt, tippt die acht Zeichen ein.
 *
 * WER NICHT EINGELADEN WIRD, und warum das kein Sparen ist:
 *
 *  - WER SCHON EIN KONTO HAT. Wiederholbarkeit: eine Welle darf zweimal
 *    laufen, ohne jemandem sein Passwort wegzunehmen.
 *  - WER KEINE NUMMER HAT. Die Einladung geht per WhatsApp; ohne Nummer gibt
 *    es keinen Weg (Spec §3: "Ohne WhatsApp — bekommen kein Konto").
 *  - WESSEN NUMMER ALS 'unavailable' BEKANNT IST. Nur das BEKANNTE Nein
 *    sperrt; 'unknown' ist die Vorgabe der Spalte und steht beim Bestand,
 *    daraus ein Nein zu machen hiesse, den Bestand von der Umstellung
 *    auszuschliessen.
 *  - WER KEIN HINTERLEGTES GEBURTSDATUM HAT (Befund F6). Das ist der
 *    wichtigste der vier: die Registrierung verlangt das Geburtsdatum als
 *    zweiten Nachweis (Zwei-Nachweis-Regel, Spec §2.4). Wer keines
 *    hinterlegt hat, bekaeme fuenfmal "pruef dein Geburtsdatum" und danach
 *    eine Stunde lang eine 404 — ohne dass etwas an ihm falsch waere, und
 *    ohne dass er es je richtig machen koennte. Die Seite kann diesen Fall
 *    nicht von einem Tippfehler unterscheiden; der Riegel gehoert deshalb an
 *    DIESEN Knopf, und er gilt auch fuer eine ausdruecklich getippte
 *    Kennung.
 *
 * Alle vier stehen unter --bericht namentlich unter "nicht erreichbar", je
 * mit ihrem eigenen Grund: eine Zahl ohne Grund sagt HR nicht, was zu tun
 * ist.
 *
 * WIEDERHOLBAR, ABER NICHT STILL: ein zweiter Lauf erneuert eine offene
 * Einladung, statt sie zu ueberspringen. Das ist eine Entscheidung mit
 * Grund. Verschickt wird ja nichts (s.o.) — der Code steht nur auf dem
 * Bildschirm, den HR gerade liest. Wuerde ein zweiter Lauf offene
 * Einladungen ueberspringen, waere eine verlorene Ausgabe unwiederbringlich:
 * HR saehe die Person als "eingeladen" und kaeme nie wieder an ihren Code,
 * weil die Datenbank nur den Hash kennt. Sobald bei Gate E wirklich
 * verschickt wird, ist diese Abwaegung neu zu treffen.
 *
 * OHNE --welle KEIN VERSAND AN ALLE. Eine Welle ist eine bewusste Handlung;
 * ein versehentliches "recruiting:konto-einladen" darf nicht den ganzen
 * Bestand umstellen. Der Probelauf (--dry-run) bleibt frei — er schreibt
 * nichts. Eine Welle, die keine positive Zahl ist, wird abgewiesen statt
 * stillschweigend auf null gesetzt: sonst meldete --welle=abc einen Erfolg
 * ueber niemanden.
 *
 * KENNUNGEN IN DER AUSGABE, NIE NAMEN (Muster:
 * recruiting:mitarbeiter-grenzfaelle), und von Nummern nur die letzten vier
 * Stellen. Die eine Ausnahme ist der Einladungscode selbst: ohne ihn kann HR
 * die Einladung nicht weitergeben, er ist genau dafuer da. Er steht auf dem
 * Bildschirm und geht NICHT ins Log.
 */
final class KontoEinladen extends Command
{
    protected $signature = 'recruiting:konto-einladen
        {--team= : Nur dieses Team}
        {--ids= : Bestimmte Personen, komma-getrennt}
        {--welle= : Hoechstens so viele auf einmal}
        {--dry-run : Nur zeigen, was passieren wuerde}
        {--bericht : Nur den Stand ausgeben, nichts verschicken}';

    protected $description = 'Konto-Einladungen erzeugen und den Stand der Umstellung zeigen (eingeladen, registriert, nicht erreichbar)';

    private const GRUND_KEINE_NUMMER = 'keine Nummer';

    private const GRUND_KEIN_GEBURTSDATUM = 'kein Geburtsdatum';

    private const GRUND_KEIN_WHATSAPP = 'kein WhatsApp';

    /**
     * Die Reihenfolge, in der die Gruende geprueft werden — der erste
     * zutreffende gewinnt. Sie ist nicht beliebig: "keine Nummer" ist der
     * Grund, der alle anderen erst gar nicht zur Frage macht.
     *
     * @var list<string>
     */
    private const GRUENDE = [
        self::GRUND_KEINE_NUMMER,
        self::GRUND_KEIN_GEBURTSDATUM,
        self::GRUND_KEIN_WHATSAPP,
    ];

    public function handle(): int
    {
        $rohTeam = $this->option('team');

        // EIN TEAM IST EINE KENNUNG, SONST IST ES KEINES. Hier stand blosses
        // (int)-Umdeuten: "--team=abc" wurde damit still zu Team 0, und weil
        // es kein Team 0 gibt, war die Zielgruppe leer. Der Lauf meldete
        // dann "0 eingeladen" mit Erfolg — dieselbe stille Verengung wie
        // frueher bei --welle.
        if ($rohTeam !== null && (!self::istZiffernfolge($rohTeam) || (int) $rohTeam < 1)) {
            $this->error(sprintf(
                '--team braucht eine Team-Kennung, keine Angabe wie "%s". Beispiel: --team=3.',
                (string) $rohTeam,
            ));

            return self::FAILURE;
        }

        $teamId = $rohTeam !== null ? (int) $rohTeam : null;

        $getippt = $this->kennungen();

        // --ids GAB ES, ABER NICHTS DAVON IST EINE KENNUNG.
        //
        // Das ist der gefaehrlichste der drei Faelle, und er ist die
        // GEGENRICHTUNG zum Wellen-Fehler: eine leere Kennungsliste heisst
        // in diesem Kommando nicht "niemand", sondern "KEIN FILTER". Wer
        // "--ids=abc --welle=10" tippte, meinte drei Menschen und bekam die
        // ganze Zielgruppe — mit Rueckgabewert 0 und ohne ein Wort darueber,
        // dass --ids verworfen wurde. Eine Welle laesst sich nicht
        // zuruecknehmen.
        if ($getippt['brauchbar'] === [] && $getippt['unbrauchbar'] !== []) {
            $this->error(sprintf(
                '--ids enthaelt keine Kennung: %s. Kennungen sind positive Zahlen, komma-getrennt (z. B. --ids=17,205). '
                . 'Abgebrochen, weil ein leeres --ids KEIN Filter ist — der Lauf ginge sonst an die ganze Zielgruppe.',
                implode(', ', $getippt['unbrauchbar']),
            ));

            return self::FAILURE;
        }

        // Teilweise unbrauchbar: das VERENGT nur und ist damit die
        // ungefaehrliche Richtung — aber HR hat es getippt und wartet auf
        // eine Antwort, also wird es benannt statt still verschluckt.
        if ($getippt['unbrauchbar'] !== []) {
            $this->line(sprintf(
                'Keine Kennung, uebergangen: %s',
                implode(', ', $getippt['unbrauchbar']),
            ));
        }

        $ids = $getippt['brauchbar'];

        return $this->option('bericht')
            ? $this->bericht($teamId, $ids)
            : $this->einladen($teamId, $ids);
    }

    // ---------------------------------------------------------------- Einladen

    /**
     * @param  list<int>  $ids
     */
    private function einladen(?int $teamId, array $ids): int
    {
        $rohWelle  = $this->option('welle');
        $probelauf = (bool) $this->option('dry-run');

        // EINE WELLE IST EINE POSITIVE ZAHL, SONST IST SIE KEINE.
        //
        // Hier stand frueher max(0, (int) ...). Das machte aus --welle=abc
        // klaglos eine Null: die Welle war leer, das Kommando meldete "0
        // eingeladen" und gab SUCCESS zurueck. HR wollte fuenfzig Leute
        // einladen und bekam eine Erfolgsmeldung ueber niemanden — und weil
        // ein zweiter Lauf die vorige Einladung entwertet (s.
        // Klassen-Docblock), faellt der Irrtum spaeter an einer ganz anderen
        // Stelle auf.
        //
        // Auch die Null selbst wird abgewiesen: "--welle=0" ist keine Welle,
        // und wer sie tippt, hat sich vertan oder ein Skript hat eine leere
        // Variable eingesetzt. Wer nur nachsehen will, hat --dry-run und
        // --bericht.
        //
        // Der Probelauf ist NICHT ausgenommen: eine falsch getippte Welle
        // sagt ihm genauso wenig, wie gross die echte waere.
        if ($rohWelle !== null && !self::istZiffernfolge($rohWelle)) {
            $this->error(sprintf(
                '--welle braucht eine Anzahl, keine Angabe wie "%s". Beispiel: --welle=50.',
                (string) $rohWelle,
            ));

            return self::FAILURE;
        }

        if ($rohWelle !== null && (int) $rohWelle < 1) {
            $this->error(
                '--welle=0 ist keine Welle. Zum Nachsehen: --dry-run (schreibt nichts) oder --bericht.',
            );

            return self::FAILURE;
        }

        $welle = $rohWelle !== null ? (int) $rohWelle : null;

        // OHNE --welle KEIN VERSAND AN ALLE. Der Probelauf ist ausgenommen:
        // er schreibt nichts, und er ist genau der Weg, auf dem HR sieht,
        // wie gross die Welle waere, bevor sie eine bewusste Zahl eintippt.
        if ($ids === [] && $welle === null && !$probelauf) {
            $this->error(
                'Ohne --welle geht keine Einladung an alle — eine Welle ist eine bewusste Handlung. '
                . 'Entweder --welle=<Anzahl> oder --ids=<Kennungen>. '
                . 'Zum Nachsehen: --dry-run (schreibt nichts) oder --bericht.',
            );

            return self::FAILURE;
        }

        $stand = $this->stand($teamId, $ids);

        $this->nenneUnbekannteKennungen($stand['fehlend']);

        $einladbar = array_merge($stand['eingeladen'], $stand['offen']);
        // Nach Kennung, damit zwei Laeufe hintereinander dieselbe
        // Reihenfolge haben — sonst waere "die naechsten 50" jedes Mal eine
        // andere Menge.
        usort($einladbar, static fn (object $a, object $b): int => (int) $a->id <=> (int) $b->id);

        $wartende = 0;
        if ($welle !== null && count($einladbar) > $welle) {
            $wartende  = count($einladbar) - $welle;
            $einladbar = array_slice($einladbar, 0, $welle);
        }

        $zeilen      = [];
        $gescheitert = 0;

        foreach ($einladbar as $person) {
            $personId = (int) $person->id;

            if ($probelauf) {
                $zeilen[] = [
                    $personId,
                    (string) ($person->team_id ?? ''),
                    '...' . NummernwechselTafel::kurz((string) $person->phone),
                    'wuerde eingeladen',
                    '',
                ];

                continue;
            }

            try {
                $token = KontoWriter::ladeEin($personId);
            } catch (Throwable $e) {
                $gescheitert++;
                $this->error("Person {$personId}: die Einladung scheiterte (Naeheres im Log).");
                // Der Grund ins Log, aber NIE der Code und nie eine volle
                // Nummer — dieselbe Regel wie im ganzen Konto-Zweig.
                Log::warning('recruiting.konto.einladung_fehler', [
                    'person_id' => $personId,
                    'fehler'    => $e->getMessage(),
                ]);

                continue;
            }

            $zeilen[] = [
                $personId,
                (string) ($person->team_id ?? ''),
                '...' . NummernwechselTafel::kurz((string) $person->phone),
                $token,
                '/recruiting/konto/anlegen/' . $token,
            ];
        }

        if ($zeilen !== []) {
            $this->table(
                ['Person', 'Team', 'Nummer', 'Einladungscode', 'Link'],
                $zeilen,
            );
        }

        $this->zeigeNichtErreichbare($stand['nicht_erreichbar'], $ids !== []);

        if ($probelauf) {
            $this->line(sprintf(
                'Probelauf: %d wuerden eingeladen, %d haben schon ein Konto, %d sind nicht erreichbar. Nichts geaendert.',
                count($zeilen),
                count($stand['registriert']),
                count($stand['nicht_erreichbar']),
            ));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d eingeladen, %d haben schon ein Konto, %d sind nicht erreichbar, %d gescheitert.',
            count($zeilen),
            count($stand['registriert']),
            count($stand['nicht_erreichbar']),
            $gescheitert,
        ));

        if ($wartende > 0) {
            $this->line("{$wartende} warten auf die naechste Welle.");
        }

        if ($zeilen !== []) {
            $this->line('Der Code ist ein Geheimnis: nur an den Menschen selbst, nicht in eine Gruppen-Unterhaltung.');
            $this->line('Verschickt wird hier noch nichts — Link oder Code gibt HR selbst weiter.');
            $this->line('Der Code steht NUR hier: gespeichert ist bloss sein Hash. '
                . 'Ein weiterer Lauf erzeugt einen neuen und entwertet diesen.');
        }

        return $gescheitert > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ----------------------------------------------------------------- Bericht

    /**
     * @param  list<int>  $ids
     */
    private function bericht(?int $teamId, array $ids): int
    {
        $stand = $this->stand($teamId, $ids);

        $this->nenneUnbekannteKennungen($stand['fehlend']);

        $this->line($teamId !== null ? "Stand (Team {$teamId})" : 'Stand (alle Teams)');
        $this->table(['Gruppe', 'Anzahl'], [
            ['registriert', count($stand['registriert'])],
            ['eingeladen, kein Konto', count($stand['eingeladen'])],
            ['noch nicht eingeladen', count($stand['offen'])],
            ['nicht erreichbar', count($stand['nicht_erreichbar'])],
        ]);

        $this->zeigeNichtErreichbare($stand['nicht_erreichbar'], true);

        $this->line('');
        $this->line('Eingeladen, noch kein Konto');
        if ($stand['eingeladen'] === []) {
            $this->line('Niemand.');
        } else {
            $this->table(
                ['Person', 'Team', 'eingeladen am', 'Einladung gilt bis'],
                array_map(fn (object $p): array => [
                    (int) $p->id,
                    (string) ($p->team_id ?? ''),
                    (string) $p->invited_at,
                    $this->einladungsstand($p),
                ], $stand['eingeladen']),
            );
        }

        $this->line('');
        $this->line('Registriert');
        if ($stand['registriert'] === []) {
            $this->line('Niemand.');
        } else {
            // RULING GD-8: die Spalte heisst NICHT "zuletzt angemeldet".
            // letzte_anmeldung_at faellt in KontoWriter::pruefeAnmeldung(),
            // sobald das Passwort stimmt — auch dann, wenn die Dispo-Sperre
            // (portal_locked_at) den Menschen unmittelbar danach abweist.
            // "Zuletzt angemeldet" waere bei einem gesperrten Konto eine
            // Falschaussage, und HR entscheidet nach dieser Spalte, ob ein
            // Konto funktioniert.
            $this->table(
                ['Person', 'Team', 'registriert am', 'letzter Passwortnachweis'],
                array_map(fn (object $p): array => [
                    (int) $p->id,
                    (string) ($p->team_id ?? ''),
                    (string) $p->registered_at,
                    (string) ($p->letzte_anmeldung_at ?? 'nie'),
                ], $stand['registriert']),
            );
        }

        $this->line('');
        $this->line('Nummernwechsel beantragt');
        $this->zeigeOffeneNummernwechsel($teamId);

        return self::SUCCESS;
    }

    /**
     * Die offenen Notfall-Antraege aus Weg 4.
     *
     * OHNE DIESEN ABSCHNITT IST DAS STOPP-RECHT THEORETISCH. Spec §5 gibt HR
     * fuer Weg 4 vierundzwanzig Stunden, in denen ein beantragter
     * Nummernwechsel gestoppt werden kann. Bisher entstand dabei nur eine
     * Log-Zeile und ein Eintrag, den ausschliesslich sieht, wer von sich aus
     * recruiting:konto-zuruecksetzen --offen faehrt. Tut das niemand, wird
     * der Wechsel still wirksam — und ein Recht, von dem niemand erfaehrt,
     * ist keines. Deshalb steht er hier, im Bericht, den HR ohnehin liest.
     *
     * NICHT nach --ids gefiltert, und das ist Absicht: dieser Abschnitt ist
     * da, damit HR von einem Antrag ERFAEHRT. Ein Filter, den HR aus einem
     * anderen Grund gesetzt hat, duerfte ihn nicht verstecken.
     *
     * DIE FAELLIGEN ZUERST: KontoWriter::offeneNummernwechsel() sortiert
     * nach wechsel_wirksam_ab aufsteigend. Faellig heisst "wirksam ab liegt
     * in der Vergangenheit" — aufsteigend sortiert stehen damit alle
     * faelligen vor allen noch nicht faelligen, und innerhalb der faelligen
     * der aelteste oben.
     *
     * DIE DARSTELLUNG LIEGT IN NummernwechselTafel und wird hier nicht zum
     * zweiten Mal gebaut: --offen zeigt dieselben Antraege, und zwei
     * Fassungen derselben Tafel laufen auseinander.
     */
    private function zeigeOffeneNummernwechsel(?int $teamId): void
    {
        $offene = KontoWriter::offeneNummernwechsel($teamId);

        if ($offene === []) {
            $this->line(NummernwechselTafel::LEER);

            return;
        }

        $this->table(NummernwechselTafel::kopf(), NummernwechselTafel::zeilen($offene));
        $this->line('Stoppen mit: recruiting:konto-zuruecksetzen --stopp=<Person>');
    }

    // ------------------------------------------------------------------ Stand

    /**
     * Wer steht wo — die eine Einteilung, aus der sowohl die Welle als auch
     * der Bericht leben. Zwei Einteilungen liefen auseinander, und dann
     * lade jemand ein, den der Bericht als nicht erreichbar fuehrt.
     *
     * DIE ZIELGRUPPE sind Personen mit mindestens einer AKTIVEN Anstellung,
     * nicht gesperrt und nicht stillgelegt. Das ist dieselbe Grenze, die
     * KontoWriter zieht: offeneZeile() wirft bei gesperrt/stillgelegt, und
     * darfSichAnmelden() verlangt eine aktive Anstellung (Canvas 1793 — ein
     * Ausgeschiedener kaeme mit der Einladung gar nicht ins Konto).
     *
     * @param  list<int>  $ids
     * @return array{
     *     registriert: list<object>,
     *     eingeladen: list<object>,
     *     offen: list<object>,
     *     nicht_erreichbar: list<array{person: object, grund: string}>,
     *     fehlend: list<int>
     * }
     */
    private function stand(?int $teamId, array $ids): array
    {
        $personen = DB::table('rec_persons')
            ->whereNull('locked_at')
            ->whereNull('merged_into_person_id')
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('rec_employees')
                ->whereColumn('rec_employees.rec_person_id', 'rec_persons.id')
                ->where('rec_employees.is_active', 1))
            ->orderBy('id')
            ->get([
                'id', 'team_id', 'phone', 'password_hash', 'invited_at', 'registered_at',
                'invite_token_hash', 'invite_expires_at', 'invite_used_at', 'letzte_anmeldung_at',
            ])
            ->all();

        $gefunden = array_map(static fn (object $p): int => (int) $p->id, $personen);

        $ohneKonto = array_values(array_filter($personen, static fn (object $p): bool => $p->password_hash === null));
        $ohneKontoIds = array_map(static fn (object $p): int => (int) $p->id, $ohneKonto);

        // Die beiden teuren Fragen genau einmal fuer die ganze Menge, und
        // nur fuer die ohne Konto: wer schon eines hat, wird ohnehin nicht
        // eingeladen.
        $ohneGeburtsdatum = array_flip(KontoWriter::personenOhneGeburtsdatum($ohneKontoIds));
        $ohneWhatsapp     = array_flip($this->ohneWhatsapp($ohneKonto));

        $stand = [
            'registriert'      => [],
            'eingeladen'       => [],
            'offen'            => [],
            'nicht_erreichbar' => [],
            'fehlend'          => array_values(array_diff($ids, $gefunden)),
        ];

        foreach ($personen as $person) {
            if ($person->password_hash !== null) {
                $stand['registriert'][] = $person;

                continue;
            }

            $grund = $this->grund($person, $ohneGeburtsdatum, $ohneWhatsapp);

            if ($grund !== null) {
                $stand['nicht_erreichbar'][] = ['person' => $person, 'grund' => $grund];

                continue;
            }

            if ($person->invited_at !== null) {
                $stand['eingeladen'][] = $person;

                continue;
            }

            $stand['offen'][] = $person;
        }

        return $stand;
    }

    /**
     * Warum diese Person keine Einladung bekommt — oder null, wenn nichts
     * dagegen spricht.
     *
     * @param  array<int, int>  $ohneGeburtsdatum
     * @param  array<int, int>  $ohneWhatsapp
     */
    private function grund(object $person, array $ohneGeburtsdatum, array $ohneWhatsapp): ?string
    {
        $personId = (int) $person->id;

        if (trim((string) $person->phone) === '') {
            return self::GRUND_KEINE_NUMMER;
        }

        if (isset($ohneGeburtsdatum[$personId])) {
            return self::GRUND_KEIN_GEBURTSDATUM;
        }

        if (isset($ohneWhatsapp[$personId])) {
            return self::GRUND_KEIN_WHATSAPP;
        }

        return null;
    }

    /**
     * Wessen Nummer im CRM als 'unavailable' bekannt ist (Spec §3: "Ohne
     * WhatsApp — bekommen kein Konto").
     *
     * NUR DAS BEKANNTE NEIN SPERRT. 'unknown' ist die Vorgabe der Spalte
     * (Migration 2025_02_18_000001) und steht an fast jeder Nummer, die nie
     * angeschrieben wurde; wer daraus ein Nein machte, schloesse den Bestand
     * von der Umstellung aus. Und ein 'available'/'opted_in' an derselben
     * Nummer hebt ein 'unavailable' auf: dann ist die Nummer nachweislich
     * erreichbar, und die andere Zeile ist der veraltete Eintrag.
     *
     * DIE SCHREIBWEISEN SIND DIE DES BESTANDES, nicht die reine Lehre —
     * nachgelesen in ContactPhoneSync::syncEmployee(), das seit dem Vorfall
     * RG19734 genau so sucht: linkable_type mit LIKE '%RecEmployee' (es gibt
     * kein Morph-Kuerzel fuer rec_employee), phoneable_type entweder als
     * Kuerzel 'crm_contact' (Morph-Karte des CrmServiceProvider) ODER als
     * voller Klassenname. Wer nur eine der beiden Formen abfragt, findet je
     * nach Alter der Zeile nichts und haelt niemanden auf.
     *
     * VERGLICHEN WIRD UEBER PhoneE164::suffix() — die letzten neun Ziffern.
     * Dieselbe Kruecke wie in ContactPhoneSync, und aus demselben Grund: im
     * CRM stehen "+49 151 …", "0151 …" und E.164 nebeneinander, und
     * normalisieren scheitert an unvollstaendigen Nummern.
     *
     * @param  list<object>  $personen
     * @return list<int>
     */
    private function ohneWhatsapp(array $personen): array
    {
        if ($personen === []) {
            return [];
        }

        $personIds = array_map(static fn (object $p): int => (int) $p->id, $personen);

        /** @var array<int, int> $anstellungZuPerson */
        $anstellungZuPerson = DB::table('rec_employees')
            ->whereIn('rec_person_id', $personIds)
            ->pluck('rec_person_id', 'id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        if ($anstellungZuPerson === []) {
            return [];
        }

        /** @var array<int, list<int>> $kontaktZuPersonen */
        $kontaktZuPersonen = [];
        foreach (DB::table('crm_contact_links')
            ->where('linkable_type', 'LIKE', '%RecEmployee')
            ->whereIn('linkable_id', array_keys($anstellungZuPerson))
            ->get(['linkable_id', 'contact_id']) as $link) {
            $personId = $anstellungZuPerson[(int) $link->linkable_id] ?? null;
            if ($personId === null) {
                continue;
            }
            $kontaktZuPersonen[(int) $link->contact_id][] = $personId;
        }

        if ($kontaktZuPersonen === []) {
            return [];
        }

        /** @var array<int, list<string>> $statusJePerson */
        $statusJePerson = [];

        $suffixe = [];
        foreach ($personen as $person) {
            $suffixe[(int) $person->id] = PhoneE164::suffix((string) $person->phone);
        }

        foreach (DB::table('crm_phone_numbers')
            ->whereIn('phoneable_id', array_keys($kontaktZuPersonen))
            ->where(fn ($q) => $q->where('phoneable_type', 'crm_contact')
                ->orWhere('phoneable_type', 'LIKE', '%CrmContact'))
            ->where('is_active', 1)
            ->get(['phoneable_id', 'international', 'raw_input', 'whatsapp_status']) as $nummer) {
            foreach ($kontaktZuPersonen[(int) $nummer->phoneable_id] ?? [] as $personId) {
                $suffix = $suffixe[$personId] ?? '';
                if ($suffix === '') {
                    continue;
                }
                if (PhoneE164::suffix((string) $nummer->international) !== $suffix
                    && PhoneE164::suffix((string) $nummer->raw_input) !== $suffix) {
                    continue;
                }
                $statusJePerson[$personId][] = (string) $nummer->whatsapp_status;
            }
        }

        $ohne = [];
        foreach ($statusJePerson as $personId => $status) {
            if (array_intersect(['available', 'opted_in'], $status) !== []) {
                continue;
            }
            if (in_array('unavailable', $status, true)) {
                $ohne[] = $personId;
            }
        }

        return $ohne;
    }

    // ----------------------------------------------------------------- Ausgabe

    /**
     * @param  list<array{person: object, grund: string}>  $nichtErreichbar
     * @param  bool  $mitKennungen  Kennungen einzeln nennen (bei --bericht
     *                              und bei ausdruecklich getippten --ids;
     *                              bei einer Welle wuerde die Liste den
     *                              Bildschirm fluten, und sie steht ohnehin
     *                              im Bericht)
     */
    private function zeigeNichtErreichbare(array $nichtErreichbar, bool $mitKennungen): void
    {
        if ($nichtErreichbar === []) {
            return;
        }

        $this->line('');
        $this->line('Nicht erreichbar');

        if ($mitKennungen) {
            $this->table(
                ['Person', 'Team', 'Grund'],
                array_map(static fn (array $fall): array => [
                    (int) $fall['person']->id,
                    (string) ($fall['person']->team_id ?? ''),
                    $fall['grund'],
                ], $nichtErreichbar),
            );

            return;
        }

        // Ohne Kennungen bleiben die Gruende — eine blosse Gesamtzahl sagt
        // HR nicht, was zu tun ist.
        foreach (self::GRUENDE as $grund) {
            $anzahl = count(array_filter($nichtErreichbar, static fn (array $fall): bool => $fall['grund'] === $grund));
            if ($anzahl > 0) {
                $this->line("{$grund}: {$anzahl} (Kennungen mit --bericht)");
            }
        }
    }

    /**
     * Kennungen, die HR getippt hat, die aber gar nicht in die Zielgruppe
     * gehoeren — es gibt sie nicht, sie sind gesperrt, stillgelegt, oder es
     * haengt keine aktive Anstellung mehr an ihnen.
     *
     * Sie werden BENANNT und nicht still uebersprungen: HR hat sie
     * eingetippt und wartet auf eine Antwort, und ein stilles Ueberspringen
     * saehe aus wie "erledigt".
     *
     * @param  list<int>  $fehlend
     */
    private function nenneUnbekannteKennungen(array $fehlend): void
    {
        if ($fehlend === []) {
            return;
        }

        $this->line(sprintf(
            'Ohne Wirkung (gibt es nicht, gesperrt, stillgelegt oder ohne aktive Anstellung): %s',
            implode(', ', $fehlend),
        ));
    }

    /**
     * Wie es um die offene Einladung dieser Person steht.
     *
     * GEMESSEN WIRD AN now(), NICHT AN time(). Hier stand die Wanduhr
     * (time()), und das war nicht bloss unsauber: der ganze Zweig friert
     * die Zeit ein (Carbon::setTestNow), und alles andere im Kommando —
     * auch die Frist, die ladeEin() setzt — rechnet mit now(). Zwei Uhren
     * im selben Kommando heisst, dass eine Einladung als abgelaufen gedruckt
     * wird, die nach der Uhr des Kommandos noch laeuft. Der Test darueber
     * lag nur zufaellig richtig und waere am 2026-10-05 von selbst
     * umgekippt, ohne dass jemand etwas angefasst haette.
     */
    private function einladungsstand(object $person): string
    {
        if ($person->invite_token_hash === null) {
            return 'keine offene Einladung';
        }

        $giltBis = (string) $person->invite_expires_at;
        $zeitpunkt = strtotime($giltBis);

        return $zeitpunkt !== false && $zeitpunkt <= now()->getTimestamp()
            ? $giltBis . ' (abgelaufen)'
            : $giltBis;
    }

    /**
     * Die getippten Kennungen, getrennt nach brauchbar und unbrauchbar.
     *
     * WARUM DAS UNBRAUCHBARE MITKOMMT UND NICHT EINFACH WEGFAELLT: eine
     * leere Kennungsliste heisst in diesem Kommando nicht "niemand",
     * sondern "KEIN FILTER". Gaebe diese Methode fuer "--ids=abc" nur ein
     * leeres Feld zurueck, liefe "--ids=abc --welle=10" gegen die GANZE
     * Zielgruppe, und der Aufrufer koennte den Fall nicht mehr von "--ids
     * wurde gar nicht getippt" unterscheiden. Die Entscheidung, was daraus
     * folgt, faellt in handle(); hier wird nur sauber getrennt.
     *
     * Eine "0" ist keine Kennung: sie entsteht aus einem abgeschnittenen
     * Aufruf ("--ids=,") genauso wie aus einem Vertipper.
     *
     * @return array{brauchbar: list<int>, unbrauchbar: list<string>}
     */
    private function kennungen(): array
    {
        $roh = trim((string) ($this->option('ids') ?? ''));

        if ($roh === '') {
            return ['brauchbar' => [], 'unbrauchbar' => []];
        }

        $brauchbar   = [];
        $unbrauchbar = [];

        foreach (explode(',', $roh) as $teil) {
            $teil = trim($teil);

            if (self::istZiffernfolge($teil) && (int) $teil > 0) {
                $brauchbar[] = (int) $teil;

                continue;
            }

            $unbrauchbar[] = $teil === '' ? '(leer)' : $teil;
        }

        return [
            'brauchbar'   => array_values(array_unique($brauchbar)),
            'unbrauchbar' => array_values(array_unique($unbrauchbar)),
        ];
    }

    /**
     * Ist das eine reine Ziffernfolge?
     *
     * EINE Fassung dieser Frage fuer --team, --ids und --welle: drei
     * Fassungen liefen auseinander, und dann waere "--team=1x" abgewiesen
     * und "--welle=1x" nicht.
     */
    private static function istZiffernfolge(mixed $wert): bool
    {
        return ctype_digit(trim((string) $wert));
    }
}
