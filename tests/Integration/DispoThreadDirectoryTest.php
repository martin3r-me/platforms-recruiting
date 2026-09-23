<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory;

/**
 * DispoThreadDirectory loest Person -> Thread auf (Runde 4, #1), fuer den
 * VA-Chat-Panel (Task 7). Ende zu Ende gegen ECHTE Migrationen (recruiting +
 * crm), kein Testbench — Muster DispoIdentityResolverTest.
 */
class DispoThreadDirectoryTest extends TestCase
{
    private const TEAM = 701;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['inbound_team_id' => self::TEAM]],
        ]));

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('comms_whatsapp_messages')->delete();
        Capsule::table('comms_whatsapp_threads')->delete();
        Capsule::table('comms_channels')->delete();
        Capsule::table('rec_dispo_assignments')->delete();
        Capsule::table('rec_dispo_events')->delete();
        Capsule::table('crm_contact_links')->delete();
        Capsule::table('rec_employees')->delete();
        if (Capsule::schema()->hasTable('integrations_whatsapp_templates')) {
            Capsule::table('integrations_whatsapp_templates')->delete();
        }
    }

    private function directory(): DispoThreadDirectory
    {
        return new DispoThreadDirectory(new DispoIdentityResolver(), new DispoEmployeeGateway());
    }

    private function employee(string $pnr, ?string $phone = null): int
    {
        return (int) RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Markus', 'last_name' => 'Ammerer',
            'personnel_number' => $pnr, 'phone' => $phone, 'portal_token' => 'tok-' . $pnr, 'is_active' => true,
        ])->id;
    }

    private function link(int $employeeId, int $contactId): void
    {
        Capsule::table('crm_contact_links')->insert([
            'uuid' => 'lnk-' . $employeeId . '-' . $contactId, 'contact_id' => $contactId, 'team_id' => self::TEAM,
            'created_by_user_id' => 1, 'linkable_id' => $employeeId,
            'linkable_type' => (new RecEmployee())->getMorphClass(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function channel(): int
    {
        return (int) CommsChannel::create([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 555' . random_int(100000, 999999),
        ])->id;
    }

    /** Setzt den Zeitpunkt der letzten Nachricht (Sortier-Kriterium seit 22.09.). */
    private function lastMessageAt(int $threadId, string $at): void
    {
        Capsule::table('comms_whatsapp_threads')->where('id', $threadId)
            ->update(['last_inbound_at' => $at, 'last_outbound_at' => $at]);
    }

    private function thread(int $channelId, string $remotePhone, ?int $contactId, bool $isUnread, string $updatedAt): int
    {
        $attrs = [
            'team_id'             => self::TEAM,
            'comms_channel_id'    => $channelId,
            'token'               => bin2hex(random_bytes(8)),
            'remote_phone_number' => $remotePhone,
            'is_unread'           => $isUnread,
        ];
        if ($contactId !== null) {
            $attrs['contact_id'] = $contactId;
            $attrs['contact_type'] = CrmContact::class;
        }

        $id = (int) CommsWhatsAppThread::create($attrs)->id;
        Capsule::table('comms_whatsapp_threads')->where('id', $id)->update(['updated_at' => $updatedAt]);

        return $id;
    }

    public function test_contact_linked_thread_is_found_for_the_whole_identity_group(): void
    {
        $rg = $this->employee('RG1'); $ma = $this->employee('MA1');
        $this->link($rg, 900); $this->link($ma, 900);

        $channel = $this->channel();
        $threadId = $this->thread($channel, '+49 999 000000', 900, false, '2026-08-27 10:00:00');

        $canon = min($rg, $ma);

        $byMa = $this->directory()->threadsFor([$channel], [$ma]);
        $this->assertArrayHasKey($canon, $byMa);
        $this->assertSame($threadId, $byMa[$canon]['thread_id']);

        // Regression: Anfrage nach der KANONISCHEN id selbst (statt nach dem
        // anderen Gruppenmitglied) muss denselben Treffer liefern — vorher
        // wurde nur die ANGEFRAGTE id kanonisiert, das jeweils ANDERE
        // Gruppenmitglied fiel auf seine rohe id zurueck und der Kontakt-
        // Treffer landete dadurch unter der falschen (nicht angefragten) id.
        $byRg = $this->directory()->threadsFor([$channel], [$rg]);
        $this->assertArrayHasKey($canon, $byRg);
        $this->assertSame($threadId, $byRg[$canon]['thread_id']);
    }

    public function test_phone_matched_thread_is_found_without_contact(): void
    {
        $ma = $this->employee('MA2', '0172 3333333');
        $channel = $this->channel();
        $threadId = $this->thread($channel, '+49 172 3333333', null, false, '2026-08-27 10:00:00');

        $result = $this->directory()->threadsFor([$channel], [$ma]);

        $this->assertSame($threadId, $result[$ma]['thread_id']);
    }

    public function test_phone_matched_thread_is_found_for_group_member_not_requested(): void
    {
        // rg hat KEIN Telefon, nur ma hat das Telefon, das den Thread traegt.
        // Beide sind ueber denselben Kontakt verlinkt (Gruppe). Angefragt
        // wird rg (die kanonische id) — der Treffer haengt also am ANDEREN,
        // NICHT angefragten Gruppenmitglied.
        $rg = $this->employee('RG-PH1'); $ma = $this->employee('MA-PH1', '0172 5551111');
        $this->link($rg, 930); $this->link($ma, 930);

        $channel = $this->channel();
        $threadId = $this->thread($channel, '+49 172 5551111', null, false, '2026-08-27 10:00:00');

        $canon = min($rg, $ma);
        $result = $this->directory()->threadsFor([$channel], [$rg]);

        $this->assertArrayHasKey($canon, $result);
        $this->assertSame($threadId, $result[$canon]['thread_id']);
    }

    public function test_shared_phone_across_group_members_is_not_ambiguous(): void
    {
        // rg und ma teilen sich dieselbe Nummer (eine Person, zwei
        // Datensaetze) — das ist KEINE Mehrdeutigkeit im Sinne des
        // DispoPhoneMatcher. Angefragt wird die kanonische id (rg); vorher
        // fiel das NICHT angefragte Mitglied (ma) auf seine rohe id zurueck,
        // wodurch dieselbe Nummer unter ZWEI verschiedenen "kanonischen" ids
        // auftauchte -> der Matcher hielt das faelschlich fuer mehrdeutig
        // und lieferte gar nichts mehr.
        $rg = $this->employee('RG-PH2', '0172 5552222');
        $ma = $this->employee('MA-PH2', '0172 5552222');
        $this->link($rg, 940); $this->link($ma, 940);

        $channel = $this->channel();
        $threadId = $this->thread($channel, '+49 172 5552222', null, false, '2026-08-27 10:00:00');

        $canon = min($rg, $ma);
        $result = $this->directory()->threadsFor([$channel], [$rg]);

        $this->assertArrayHasKey($canon, $result);
        $this->assertSame($threadId, $result[$canon]['thread_id']);
    }

    public function test_phone_shared_with_an_employee_outside_the_request_is_ambiguous(): void
    {
        // a ist im VA disponiert, b NICHT — beide sind aber aktive MA des
        // Teams und teilen sich (Tippfehler / Familien-Nummer) dieselbe
        // Nummer, ohne EINE Person zu sein (kein gemeinsamer Kontakt).
        // Regression: der Matcher wurde nur aus den ANGEFRAGTEN MA gebaut,
        // die Nummer sah dadurch eindeutig aus und der fremde Thread wurde a
        // zugeschrieben. Gegen das VOLLE Telefonverzeichnis ist sie
        // mehrdeutig -> kein Telefon-Treffer.
        $a = $this->employee('MA-AMB-A', '0172 5559999');
        $b = $this->employee('MA-AMB-B', '0172 5559999');
        $this->link($a, 950); // eigener Kontakt, b bleibt unverlinkt

        $channel = $this->channel();
        $this->thread($channel, '+49 172 5559999', null, false, '2026-08-28 10:00:00');

        $result = $this->directory()->threadsFor([$channel], [$a]);

        $this->assertArrayNotHasKey($a, $result, 'Geteilte Nummer mit einem NICHT angefragten MA darf keinen Treffer liefern.');
        $this->assertArrayNotHasKey($b, $result);

        // Der sichere Weg (Kontakt-Link) traegt weiterhin: derselbe MA, ein
        // Thread mit contact_id -> wird gefunden.
        $contactThread = $this->thread($channel, '+49 999 222000', 950, false, '2026-08-28 11:00:00');

        $withContact = $this->directory()->threadsFor([$channel], [$a]);
        $this->assertSame($contactThread, $withContact[$a]['thread_id'] ?? null, 'Kontakt-verlinkter Thread bleibt trotz mehrdeutiger Nummer auffindbar.');
    }

    public function test_newest_thread_wins_and_current_number_beats_contact(): void
    {
        $ma = $this->employee('MA3', '0172 4444444');
        $this->link($ma, 910);

        $channel1 = $this->channel();
        $channel2 = $this->channel();

        // Zwei Telefon-Treffer auf demselben Kanal — derselbe normalisierte
        // Wert, unterschiedliche Rohschreibweise (Unique-Constraint erlaubt es).
        $newerPhoneThread = $this->thread($channel1, '+49 172 4444444', null, false, '2026-08-27 10:00:00');
        $olderPhoneThread = $this->thread($channel1, '0172 4444444', null, false, '2026-08-25 10:00:00');

        // Kontakt-verlinkter Thread auf ANDERER Nummer (der Vesa-Fall: alter
        // Thread haengt am Kontakt, die Akte traegt inzwischen eine neue Nummer).
        $contactThread = $this->thread($channel2, '+49 999 111000', 910, false, '2026-08-28 10:00:00');

        $phoneOnly = $this->directory()->threadsFor([$channel1], [$ma]);
        $this->assertSame($newerPhoneThread, $phoneOnly[$ma]['thread_id'], 'Unter reinen Telefon-Treffern gewinnt der neuere.');
        $this->assertNotSame($olderPhoneThread, $phoneOnly[$ma]['thread_id']);

        // Vorfall Vesa (04./07.09.): der Telefon-Treffer entspricht der AKTUELLEN
        // Akten-Nummer und schlaegt den Kontakt-Treffer — selbst wenn der neuer ist.
        $withContact = $this->directory()->threadsFor([$channel1, $channel2], [$ma]);
        $this->assertSame($newerPhoneThread, $withContact[$ma]['thread_id'], 'Aktuelle Akten-Nummer schlaegt Kontakt-Treffer.');
    }

    public function test_contact_thread_is_fallback_when_current_number_has_no_thread(): void
    {
        $ma = $this->employee('MA4', '0173 5555555');
        $this->link($ma, 911);
        $channel = $this->channel();

        // Kein Thread zur aktuellen Nummer — der kontakt-verlinkte alte bleibt die Tuer zur Person.
        $contactThread = $this->thread($channel, '+49 999 222000', 911, false, '2026-08-20 10:00:00');

        $found = $this->directory()->threadsFor([$channel], [$ma]);
        $this->assertSame($contactThread, $found[$ma]['thread_id']);
    }

    public function test_matches_any_phone_compares_digit_suffixes(): void
    {
        $this->assertTrue(\Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory::matchesAnyPhone('+491729071626', ['0172 9071626']));
        $this->assertFalse(\Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory::matchesAnyPhone('+491729806050', ['+491729071626']));
        $this->assertFalse(\Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory::matchesAnyPhone('+491729806050', []));
    }

    public function test_unread_by_event_counts_persons_not_records(): void
    {
        // NUR der kanonische Datensatz (rg) ist gebucht; der Thread haengt
        // per TELEFON am ANDEREN Gruppenmitglied (ma) — beide sind ueber
        // denselben Kontakt verlinkt, aber nur ma traegt ein Telefon.
        // Regression: die alte Kanonisierung kannte nur die ANGEFRAGTE id
        // (hier: rg, aus der Buchung) und liess mas Telefon-Treffer auf
        // dessen roher id haengen -> das Ereignis fiel komplett aus dem
        // Ergebnis, statt korrekt als EINE ungelesene Person zu zaehlen.
        $rg = $this->employee('RG4'); $ma = $this->employee('MA4', '0172 5550002');
        $this->link($rg, 920); $this->link($ma, 920);

        $channel = $this->channel();
        $this->thread($channel, '+49 172 5550002', null, true, '2026-08-28 09:00:00');

        $event = RecDispoEvent::create(['einsatz_ref' => 'E-UNREAD-1']);
        $today = now()->toDateString();

        RecDispoAssignment::create([
            'ds_ref' => 'DS-UNREAD-1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG4',
            'rec_employee_id' => $rg, 'datum' => $today, 'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
        ]);

        $result = $this->directory()->unreadByEvent([$channel], [$event->id], $today);

        $this->assertSame([$event->id => 1], $result, 'Telefon-Treffer am NICHT gebuchten Gruppenmitglied zaehlt fuer die gebuchte kanonische Person.');
    }

    public function test_messages_can_be_filtered_by_since(): void
    {
        $channel = $this->channel();
        $threadId = $this->thread($channel, '+49 172 7777777', null, false, '2026-08-28 09:00:00');

        $yesterday = (int) CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => $threadId, 'direction' => 'inbound', 'body' => 'gestern',
        ])->id;
        Capsule::table('comms_whatsapp_messages')->where('id', $yesterday)->update(['created_at' => '2026-08-27 10:00:00']);

        $today = (int) CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => $threadId, 'direction' => 'inbound', 'body' => 'heute',
        ])->id;
        Capsule::table('comms_whatsapp_messages')->where('id', $today)->update(['created_at' => '2026-08-28 09:00:00']);

        $thread = CommsWhatsAppThread::find($threadId);
        $since = new \DateTimeImmutable('2026-08-28 00:00:00');

        $result = $this->directory()->messages($thread, [], $since);

        $this->assertCount(1, $result);
        $this->assertSame('heute', $result[0]['body']);
    }

    public function test_event_channel_wins_over_a_newer_conversation_on_another_channel(): void
    {
        // Befund Tristan 22.09.: wer fuer mehrere Filialen arbeitet, hat je Kanal
        // ein eigenes Gespraech — im VA-Chat gehoert das der Filiale dieser VA.
        $ma = $this->employee('MA-CH', '0178 1888551');
        $mgl = $this->channel();
        $dus = $this->channel();

        $mglThread = $this->thread($mgl, '+49 178 1888551', null, false, '2026-09-22 10:00:00');
        $this->lastMessageAt($mglThread, '2026-09-22 10:00:00');
        $dusThread = $this->thread($dus, '+49 178 1888551', null, false, '2026-09-22 15:00:00');
        $this->lastMessageAt($dusThread, '2026-09-22 15:00:00');

        $ohneKanal = $this->directory()->threadsFor([$mgl, $dus], [$ma]);
        $this->assertSame($dusThread, $ohneKanal[$ma]['thread_id'], 'Ohne Kanal-Vorgabe gewinnt die juengste Nachricht.');

        $mitKanal = $this->directory()->threadsFor([$mgl, $dus], [$ma], $mgl);
        $this->assertSame($mglThread, $mitKanal[$ma]['thread_id'], 'Mit Kanal der VA gewinnt dessen Gespraech.');
    }

    public function test_a_dead_conversation_no_longer_wins_just_because_it_has_no_contact_link(): void
    {
        // Genau der Fehler vom 22.09.: das kontaktlose CGN-Gespraech (zwei Wochen alt)
        // schlug die aktuellen Gespraeche, weil "Telefon-Treffer schlaegt Kontakt".
        $ma = $this->employee('MA-DEAD', '0178 1888551');
        $this->link($ma, 3915);
        $cgn = $this->channel();
        $mgl = $this->channel();

        $altOhneKontakt = $this->thread($cgn, '+49 178 1888551', null, false, '2026-09-08 21:49:00');
        $this->lastMessageAt($altOhneKontakt, '2026-09-08 21:49:00');
        $aktuellMitKontakt = $this->thread($mgl, '+49 178 1888551', 3915, false, '2026-09-22 15:03:00');
        $this->lastMessageAt($aktuellMitKontakt, '2026-09-22 15:03:00');

        $found = $this->directory()->threadsFor([$cgn, $mgl], [$ma]);

        $this->assertSame($aktuellMitKontakt, $found[$ma]['thread_id']);
    }

    public function test_updated_at_does_not_beat_the_last_real_message(): void
    {
        // "als gelesen markieren" setzt updated_at hoch — das darf ein totes
        // Gespraech nicht zum vermeintlich neuesten machen.
        $ma = $this->employee('MA-UPD', '0178 1888552');
        $channel = $this->channel();

        $totMitFrischemUpdatedAt = $this->thread($channel, '+49 178 1888552', null, false, '2026-09-22 15:13:00');
        $this->lastMessageAt($totMitFrischemUpdatedAt, '2026-09-08 21:49:00');
        $aktuell = $this->thread($channel, '0178 1888552', null, false, '2026-09-22 09:00:00');
        $this->lastMessageAt($aktuell, '2026-09-22 15:03:00');

        $found = $this->directory()->threadsFor([$channel], [$ma]);

        $this->assertSame($aktuell, $found[$ma]['thread_id']);
    }

    public function test_all_threads_for_lists_every_conversation_of_the_person(): void
    {
        $ma = $this->employee('MA-ALL', '0178 1888553');
        $a = $this->channel();
        $b = $this->channel();
        $t1 = $this->thread($a, '+49 178 1888553', null, false, '2026-09-22 10:00:00');
        $this->lastMessageAt($t1, '2026-09-22 10:00:00');
        $t2 = $this->thread($b, '+49 178 1888553', null, true, '2026-09-22 11:00:00');
        $this->lastMessageAt($t2, '2026-09-22 11:00:00');

        $all = $this->directory()->allThreadsFor([$a, $b], [$ma]);

        $this->assertCount(2, $all[$ma]);
        $this->assertSame([$t2, $t1], array_column($all[$ma], 'thread_id'), 'Juengste zuerst.');
        $this->assertTrue($all[$ma][0]['is_unread']);
        $this->assertSame($b, $all[$ma][0]['channel_id']);
    }

    public function test_template_message_shows_the_real_text(): void
    {
        // Kunde 23.09.: im Chat stand "Template: t_dispo_bestaetigung" — Markus
        // konnte nicht sehen, was die Leute gelesen haben.
        if (!Capsule::schema()->hasTable('integrations_whatsapp_templates')) {
            $this->markTestSkipped('Integrations-Paket nicht geladen.');
        }
        Capsule::table('integrations_whatsapp_templates')->insert([
            'uuid' => 'tpl-1', 'external_id' => 'ext-1', 'name' => 't_dispo_bestaetigung',
            'language' => 'de', 'status' => 'APPROVED', 'whatsapp_account_id' => 1, 'user_id' => 1,
            'components' => json_encode([
                ['type' => 'BODY', 'text' => 'Hallo {{1}}, am {{2}} bist du bei {{3}} eingeteilt.'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Einsatz ansehen']]],
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $channel = $this->channel();
        $threadId = $this->thread($channel, '+49 172 8888888', null, false, '2026-09-23 09:00:00');
        CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => $threadId, 'direction' => 'outbound',
            'body' => 'Template: t_dispo_bestaetigung',   // so legt der Meta-Dienst es ab
            'message_type' => 'template', 'template_name' => 't_dispo_bestaetigung',
            'template_params' => [
                ['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => 'Tristan'],
                    ['type' => 'text', 'text' => '24.09.2026'],
                    ['type' => 'text', 'text' => 'Messe Düsseldorf'],
                ]],
            ],
        ]);

        $result = $this->directory()->messages(CommsWhatsAppThread::find($threadId), []);

        $this->assertCount(1, $result);
        $this->assertSame('template', $result[0]['kind']);
        $this->assertSame('Hallo Tristan, am 24.09.2026 bist du bei Messe Düsseldorf eingeteilt.', $result[0]['body']);
        $this->assertSame(['Einsatz ansehen'], $result[0]['template_buttons']);
    }

    /** Ohne bekannte Vorlage bleibt die Karte, aber ohne erfundenen Text. */
    public function test_template_message_without_definition_keeps_the_label(): void
    {
        $channel = $this->channel();
        $threadId = $this->thread($channel, '+49 172 8888889', null, false, '2026-09-23 09:00:00');
        CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => $threadId, 'direction' => 'outbound',
            'body' => 'Template: t_wo_bist', 'message_type' => 'template', 'template_name' => 't_wo_bist',
        ]);

        $result = $this->directory()->messages(CommsWhatsAppThread::find($threadId), []);

        $this->assertSame('template', $result[0]['kind']);
        $this->assertSame('', $result[0]['body'], 'Kein technischer Name als vermeintlicher Nachrichtentext.');
        $this->assertNotSame('', (string) $result[0]['template_label']);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CrmContactLink::class);

        $files = [
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
            [$own, 'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
            [$own, 'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php'],
            [$own, 'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php'],
        ];

        // Vorlagen-Texte (Kunde 23.09.) liegen im Integrations-Paket; fehlt es im
        // Testlauf, bleibt der Chat beim Vorlagen-Namen — dann entfaellt der Test.
        if (class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            $integrations = self::packageRootOf(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class);
            $files[] = [$integrations, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'];
        }

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    /** Wurzel des Composer-Pakets einer geladenen Klasse (Modulmuster). */
    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);

        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }
}
