<?php

namespace Platform\Recruiting;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\PlatformCore;
use Platform\Core\Routing\ModuleRouter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class RecruitingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Platform\Recruiting\Console\Commands\ProcessAutoPilotApplicants::class,
                \Platform\Recruiting\Console\Commands\DispatchAutoPilotApplicants::class,
                \Platform\Recruiting\Console\Commands\EnrichInboxApplicants::class,
                \Platform\Recruiting\Console\Commands\DispatchEnrichInboxApplicants::class,
                \Platform\Recruiting\Console\Commands\SyncPhases::class,
                \Platform\Recruiting\Console\Commands\MarkLegacyApplicants::class,
                \Platform\Recruiting\Console\Commands\SendInterviewReminders::class,
                \Platform\Recruiting\Console\Commands\ReleaseStaleSeats::class,
                \Platform\Recruiting\Console\Commands\RelinkOrphanedThreads::class,
                \Platform\Recruiting\Console\Commands\RelinkWhatsAppThreads::class,
                \Platform\Recruiting\Console\Commands\MigrateLegalStatusExtraFields::class,
                \Platform\Recruiting\Console\Commands\CopyHcmContractTemplates::class,
                \Platform\Recruiting\Console\Commands\DeactivateHcmContractTemplates::class,
                \Platform\Recruiting\Console\Commands\CopyHcmContractExtraFields::class,
                \Platform\Recruiting\Console\Commands\SeedRecContractExtraFields::class,
                \Platform\Recruiting\Console\Commands\CreateArbeitsvertragVariants::class,
                \Platform\Recruiting\Console\Commands\DiagnoseContractExtraFields::class,
                \Platform\Recruiting\Console\Commands\DebugContractFieldResolution::class,
                \Platform\Recruiting\Console\Commands\BackfillApplicantCrmFromExtraFields::class,
                \Platform\Recruiting\Console\Commands\CancelContract::class,
                \Platform\Recruiting\Console\Commands\FixAppliedAt::class,
                \Platform\Recruiting\Console\Commands\FixApplicantPhase::class,
                \Platform\Recruiting\Console\Commands\ReconcileApplicantPositions::class,
                \Platform\Recruiting\Console\Commands\DuplicatePosition::class,
                \Platform\Recruiting\Console\Commands\ImportApplicantsCsv::class,
                \Platform\Recruiting\Console\Commands\ZasExportBackfill::class,
                \Platform\Recruiting\Console\Commands\ZasEmployeeExportBackfill::class,
                \Platform\Recruiting\Console\Commands\BackfillImageVariants::class,
                \Platform\Recruiting\Console\Commands\DeleteEmployee::class,
                \Platform\Recruiting\Console\Commands\ZasReExportByBookingDate::class,
                \Platform\Recruiting\Console\Commands\ZasCrmContactBackfill::class,
                \Platform\Recruiting\Console\Commands\NormalizeEmployeePhonesCommand::class,
                \Platform\Recruiting\Console\Commands\SyncEmployeeContactList::class,
                \Platform\Recruiting\Console\Commands\FlynkReconcile::class,
                \Platform\Recruiting\Console\Commands\BackfillEmployeeFieldsFromApplicant::class,
                \Platform\Recruiting\Console\Commands\CleanupInterviewWaitlist::class,
                \Platform\Recruiting\Console\Commands\MigrateNonEuCases::class,
                \Platform\Recruiting\Console\Commands\BackfillPhaseTransitions::class,
                \Platform\Recruiting\Console\Commands\ZasInboundReprocess::class,
                \Platform\Recruiting\Console\Commands\ZasInboundColumns::class,
                \Platform\Recruiting\Console\Commands\DispoReprocessCommand::class,
                \Platform\Recruiting\Console\Commands\DispoEscalateCommand::class,
                \Platform\Recruiting\Console\Commands\DispoResetCommand::class,
                \Platform\Recruiting\Console\Commands\DispoTestVaCommand::class,
                \Platform\Recruiting\Console\Commands\EnableManualBookingForPhases::class,
                \Platform\Recruiting\Console\Commands\BackfillApplicantPosition::class,
            ]);
        }

        // ZAS-Signed-URL-Generator: braucht Sekret + TTL aus der Config,
        // deshalb explizit gebunden statt Auto-Resolution.
        $this->app->singleton(
            \Platform\Recruiting\Services\Zas\ZasSignedUrlGenerator::class,
            fn ($app) => new \Platform\Recruiting\Services\Zas\ZasSignedUrlGenerator(
                secret:  (string) config('recruiting.zas.signed_url_secret', ''),
                ttlDays: (int) config('recruiting.zas.signed_url_ttl_days', 7),
            )
        );

        $this->app->singleton(
            \Platform\Recruiting\Services\Flynk\FlynkClient::class,
            fn ($app) => new \Platform\Recruiting\Services\Flynk\FlynkClient(
                baseUrl: (string) config('recruiting.flynk.base_url', 'https://flynk.on-forge.com/api'),
                token:   (string) config('recruiting.flynk.token', ''),
                timeout: (int) config('recruiting.flynk.timeout', 10),
            )
        );
    }

    public function boot(): void
    {
        // Schedule FIRST — before any DB calls that could fail
        $this->registerSchedule();

        // Zugriffsstufe "Nur Veranstaltungen" (Gate Stufe 1): haengt in der
        // web-Gruppe, ist fuer alle Nicht-Recruiting-Requests ein No-op.
        $this->app['router']->pushMiddlewareToGroup('web', \Platform\Recruiting\Http\Middleware\DispoEventOnlyGate::class);

        Relation::morphMap([
            'rec_applicant' => \Platform\Recruiting\Models\RecApplicant::class,
            'rec_position' => \Platform\Recruiting\Models\RecPosition::class,
            'rec_phase' => \Platform\Recruiting\Models\RecPhase::class,
            'rec_contract_template' => \Platform\Recruiting\Models\RecContractTemplate::class,
            'rec_contract' => \Platform\Recruiting\Models\RecContract::class,
        ]);

        // EntityLinkProvider registrieren (loose Kopplung mit Organization-Modul)
        try {
            resolve(\Platform\Organization\Services\EntityLinkRegistry::class)
                ->register(new \Platform\Recruiting\Organization\RecruitingEntityLinkProvider());
        } catch (\Throwable $e) {
            // Organization-Modul nicht geladen
        }

        $this->mergeConfigFrom(__DIR__.'/../config/recruiting.php', 'recruiting');

        if (
            config()->has('recruiting.routing') &&
            config()->has('recruiting.navigation') &&
            Schema::hasTable('modules')
        ) {
            PlatformCore::registerModule([
                'key'        => 'recruiting',
                'title'      => 'Recruiting',
                'group'      => 'sales',
                'routing'    => config('recruiting.routing'),
                'guard'      => config('recruiting.guard'),
                'navigation' => config('recruiting.navigation'),
            ]);
        }

        if (PlatformCore::getModule('recruiting')) {
            ModuleRouter::group('recruiting', function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
        }

        Route::prefix('recruiting')->middleware(['web', \Platform\Core\Http\Middleware\NoCacheHeaders::class])->group(function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/public.php');
        });

        // ZAS-Export: eigene Route-Gruppe ohne `web`-Middleware
        // (kein Session, kein CSRF). Auth via ZasBearerAuth bzw. signed URL.
        Route::prefix('recruiting/zas')->group(function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/zas.php');
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/recruiting.php' => config_path('recruiting.php'),
        ], 'config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'recruiting');
        $this->registerLivewireComponents();

        $this->registerCommsListeners();
        $this->registerTools();

        // ZAS-Bewerber-Export: Observer markiert export_changed_at bei
        // relevanten Aenderungen. Der Endpoint liefert markierte Datensaetze
        // aus und nullt den Marker. Siehe docs/meingedeck/zas-applicant-export.md
        \Platform\Recruiting\Observers\RecApplicantExportObserver::register();
        \Platform\Recruiting\Observers\RecEmployeeExportObserver::register();

        // Schulung-Warteliste: Slot wird verfügbar → wartende Bewerber
        // benachrichtigen; Bewerber-Dropout → offene Warteliste-Zeile canceln.
        \Platform\Recruiting\Observers\RecInterviewWaitlistObserver::register();

        // Nicht-EU-Abzweig nach der Schulung: attended → HR-Schreibtisch.
        \Platform\Recruiting\Observers\RecInterviewBookingComplianceObserver::register();

        // Buchung → Warteliste und Termin-Abos schließen. Am Model und nicht im
        // Buchungs-Dialog, damit HR-Dialog, MCP-Tool und CSV-Sammelbuchung
        // gleich behandelt werden (der öffentliche Pfad tut es schon selbst).
        \Platform\Recruiting\Observers\RecInterviewBookingWaitlistObserver::register();

        // Phasen-Statistik: schreibt rec_phase_transitions bei jedem
        // Eloquent-Pfad, der rec_phase_id setzt/aendert (Ausnahmen siehe
        // Observer-Docblocks und FixApplicantPhase).
        \Platform\Recruiting\Models\RecApplicant::observe(\Platform\Recruiting\Observers\RecApplicantPhaseObserver::class);

        // Phasen-Statistik: DB-Kaskaden (nullOnDelete/cascadeOnDelete) feuern
        // keine Eloquent-Events auf RecApplicant — diese beiden Observer
        // schreiben die Transition VOR der Kaskade an ihrem Ausgangspunkt.
        \Platform\Recruiting\Models\RecPhase::observe(\Platform\Recruiting\Observers\RecPhaseObserver::class);
        \Platform\Recruiting\Models\RecPosition::observe(\Platform\Recruiting\Observers\RecPositionObserver::class);

        // MA-Kontaktbuch: haelt die sync-verwaltete CRM-Kontaktliste bei
        // Einzel-Aenderungen (is_active/employment_ended_at) aktuell.
        \Platform\Recruiting\Models\RecEmployee::observe(\Platform\Recruiting\Observers\RecEmployeeContactListObserver::class);
    }

    protected function registerSchedule(): void
    {
        Schedule::command('recruiting:dispatch-enrich-inbox-applicants')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->runInBackground();

        Schedule::command('recruiting:dispatch-auto-pilot-applicants')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->runInBackground();

        Schedule::command('recruiting:send-interview-reminders')
            ->everyFiveMinutes()
            ->withoutOverlapping(5)
            ->runInBackground();

        Schedule::command('recruiting:flynk-reconcile')
            ->everyThirtyMinutes()
            ->withoutOverlapping(15)
            ->runInBackground();

        // MA-Kontaktbuch: Konvergenz-Garantie fuer alle Pfade, die der Observer
        // nicht sieht (Link-Anlage, Hard-Deletes). BEWUSST ohne --force —
        // Guard-Faelle sollen liegen bleiben und im Command/Panel auffallen.
        Schedule::command('recruiting:sync-employee-contact-list')
            ->hourly()
            ->withoutOverlapping(10)
            ->runInBackground();

        Schedule::command('recruiting:cleanup-interview-waitlist')
            ->hourly()
            ->withoutOverlapping(10)
            ->runInBackground();

        // Dispo-Eskalation (Runde 2): 14/15 Uhr Reminder, 16 Uhr Rausnahme +
        // Portalsperre + Alarm. No-op solange dispo_escalation_enabled=false.
        Schedule::command('recruiting:dispo-escalate')
            ->everyFiveMinutes()
            ->withoutOverlapping(5)
            ->runInBackground();

        // CRM-Kontakt-Backfill (Runde 4, #0): MA aus dem ZAS-Import haben keinen
        // CRM-Link; ohne Link greift die Dispo-Identitaet nicht (Doppel-MA RG/MA
        // erscheinen als zwei Personen). Idempotent (nur MA ohne Link). Legt fuer
        // MA ohne passenden Kontakt einen neuen Kontakt an. Ausgabe in eigene
        // Log-Datei, Fehler zusaetzlich ueber Log::error im Command.
        // --scheduled: Team-Anker aus recruiting.zas.inbound_team_id (ohne Anker
        // fail-closed = No-op statt Lauf ueber alle Mandanten) und Kill-Switch
        // dispo_contact_backfill_enabled (Disposition -> Einstellungen).
        Schedule::command('recruiting:zas-crm-contact-backfill --scheduled')
            ->hourly()
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/zas-contact-backfill.log'));
    }

    protected function registerLivewireComponents(): void
    {
        $basePath = __DIR__ . '/Livewire';
        $baseNamespace = 'Platform\\Recruiting\\Livewire';
        $prefix = 'recruiting';

        if (!is_dir($basePath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $classPath = str_replace(['/', '.php'], ['\\', ''], $relativePath);
            $class = $baseNamespace . '\\' . $classPath;

            if (!class_exists($class)) {
                continue;
            }

            $segments = explode('/', str_replace(['\\', '.php'], ['/', ''], $relativePath));
            $aliasPath = implode('.', array_map([Str::class, 'kebab'], $segments));
            $alias = $prefix . '.' . $aliasPath;

            Livewire::component($alias, $class);
        }
    }

    protected function registerCommsListeners(): void
    {
        // Email inbound → automatic application creation
        if (class_exists(\Platform\Crm\Events\CommsInboundReceived::class)) {
            \Illuminate\Support\Facades\Event::listen(
                \Platform\Crm\Events\CommsInboundReceived::class,
                \Platform\Recruiting\Listeners\HandleCommsInboundForRecruiting::class
            );
        }

        // WhatsApp inbound → automatic application creation
        if (class_exists(\Platform\Crm\Events\CommsWhatsAppInboundReceived::class)) {
            \Illuminate\Support\Facades\Event::listen(
                \Platform\Crm\Events\CommsWhatsAppInboundReceived::class,
                \Platform\Recruiting\Listeners\HandleWhatsAppInboundForRecruiting::class
            );
        }
    }

    protected function registerTools(): void
    {
        try {
            $registry = resolve(\Platform\Core\Tools\ToolRegistry::class);

            // Overview + Lookups
            $registry->register(new \Platform\Recruiting\Tools\RecruitingOverviewTool());
            $registry->register(new \Platform\Recruiting\Tools\RecruitingLookupsTool());
            $registry->register(new \Platform\Recruiting\Tools\GetRecruitingLookupTool());

            // Positions (CRUD)
            $registry->register(new \Platform\Recruiting\Tools\ListPositionsTool());
            $registry->register(new \Platform\Recruiting\Tools\GetPositionTool());
            $registry->register(new \Platform\Recruiting\Tools\CreatePositionTool());
            $registry->register(new \Platform\Recruiting\Tools\UpdatePositionTool());
            $registry->register(new \Platform\Recruiting\Tools\DeletePositionTool());

            // Postings (CRUD)
            $registry->register(new \Platform\Recruiting\Tools\ListPostingsTool());
            $registry->register(new \Platform\Recruiting\Tools\GetPostingTool());
            $registry->register(new \Platform\Recruiting\Tools\CreatePostingTool());
            $registry->register(new \Platform\Recruiting\Tools\UpdatePostingTool());
            $registry->register(new \Platform\Recruiting\Tools\DeletePostingTool());

            // Applicants (Read + Write)
            $registry->register(new \Platform\Recruiting\Tools\ListApplicantsTool());
            $registry->register(new \Platform\Recruiting\Tools\GetApplicantTool());
            $registry->register(new \Platform\Recruiting\Tools\CreateApplicantTool());
            $registry->register(new \Platform\Recruiting\Tools\UpdateApplicantTool());
            $registry->register(new \Platform\Recruiting\Tools\DeleteApplicantTool());

            // Enrichment Logs
            $registry->register(new \Platform\Recruiting\Tools\GetEnrichmentLogsTool());

            // Schulung-Warteliste (Liste + Reset/Storno)
            $registry->register(new \Platform\Recruiting\Tools\ListWaitlistTool());
            $registry->register(new \Platform\Recruiting\Tools\UpdateWaitlistTool());

            // Applicant ↔ CRM Contact Links
            $registry->register(new \Platform\Recruiting\Tools\LinkApplicantContactTool());
            $registry->register(new \Platform\Recruiting\Tools\UnlinkApplicantContactTool());

            // Applicant ↔ Posting Links
            $registry->register(new \Platform\Recruiting\Tools\LinkApplicantPostingTool());
            $registry->register(new \Platform\Recruiting\Tools\UnlinkApplicantPostingTool());
            $registry->register(new \Platform\Recruiting\Tools\DeleteApplicantPostingTool());

            // Bulk Operations - Applicants
            $registry->register(new \Platform\Recruiting\Tools\BulkCreateApplicantsTool());
            $registry->register(new \Platform\Recruiting\Tools\BulkUpdateApplicantsTool());
            $registry->register(new \Platform\Recruiting\Tools\BulkDeleteApplicantsTool());

            // Bulk Operations - Positions
            $registry->register(new \Platform\Recruiting\Tools\BulkCreatePositionsTool());
            $registry->register(new \Platform\Recruiting\Tools\BulkUpdatePositionsTool());
            $registry->register(new \Platform\Recruiting\Tools\BulkDeletePositionsTool());

            // Bulk Operations - Postings
            $registry->register(new \Platform\Recruiting\Tools\BulkCreatePostingsTool());
            $registry->register(new \Platform\Recruiting\Tools\BulkUpdatePostingsTool());
            $registry->register(new \Platform\Recruiting\Tools\BulkDeletePostingsTool());

            // Contract Templates (CRUD)
            $registry->register(new \Platform\Recruiting\Tools\ListContractTemplatesTool());
            $registry->register(new \Platform\Recruiting\Tools\CreateContractTemplateTool());
            $registry->register(new \Platform\Recruiting\Tools\UpdateContractTemplateTool());
            $registry->register(new \Platform\Recruiting\Tools\DeleteContractTemplateTool());

            // Contracts (CRUD + FillFields + RePersonalize)
            $registry->register(new \Platform\Recruiting\Tools\ListContractsTool());
            $registry->register(new \Platform\Recruiting\Tools\CreateContractTool());
            $registry->register(new \Platform\Recruiting\Tools\UpdateContractTool());
            $registry->register(new \Platform\Recruiting\Tools\DeleteContractTool());
            $registry->register(new \Platform\Recruiting\Tools\FillContractFieldsTool());
            $registry->register(new \Platform\Recruiting\Tools\RePersonalizeContractsTool());
            $registry->register(new \Platform\Recruiting\Tools\SendContractsTool());

            // Event Locations (CRUD-light)
            $registry->register(new \Platform\Recruiting\Tools\ListEventLocationsTool());
            $registry->register(new \Platform\Recruiting\Tools\CreateEventLocationTool());
            $registry->register(new \Platform\Recruiting\Tools\UpdateEventLocationTool());

            // Interview Types (CRUD)
            $registry->register(new \Platform\Recruiting\Tools\ListInterviewTypesTool());
            $registry->register(new \Platform\Recruiting\Tools\CreateInterviewTypeTool());
            $registry->register(new \Platform\Recruiting\Tools\UpdateInterviewTypeTool());
            $registry->register(new \Platform\Recruiting\Tools\DeleteInterviewTypeTool());

            // Interviews (CRUD + Get)
            $registry->register(new \Platform\Recruiting\Tools\ListInterviewsTool());
            $registry->register(new \Platform\Recruiting\Tools\GetInterviewTool());
            $registry->register(new \Platform\Recruiting\Tools\CreateInterviewTool());
            $registry->register(new \Platform\Recruiting\Tools\UpdateInterviewTool());
            $registry->register(new \Platform\Recruiting\Tools\DeleteInterviewTool());

            // Interview Bookings (CRUD)
            $registry->register(new \Platform\Recruiting\Tools\ListInterviewBookingsTool());
            $registry->register(new \Platform\Recruiting\Tools\CreateInterviewBookingTool());
            $registry->register(new \Platform\Recruiting\Tools\UpdateInterviewBookingTool());
            $registry->register(new \Platform\Recruiting\Tools\DeleteInterviewBookingTool());

            // Phases (CRUD + Extra Fields)
            $registry->register(new \Platform\Recruiting\Tools\ListPhasesTool());
            $registry->register(new \Platform\Recruiting\Tools\CreatePhaseTool());
            $registry->register(new \Platform\Recruiting\Tools\UpdatePhaseTool());
            $registry->register(new \Platform\Recruiting\Tools\DeletePhaseTool());
            $registry->register(new \Platform\Recruiting\Tools\ManagePhaseExtraFieldsTool());
        } catch (\Throwable $e) {
            \Log::warning('Recruiting: Tool-Registrierung fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }
}
