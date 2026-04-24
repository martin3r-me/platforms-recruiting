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
                \Platform\Recruiting\Console\Commands\RelinkOrphanedThreads::class,
                \Platform\Recruiting\Console\Commands\MigrateLegalStatusExtraFields::class,
                \Platform\Recruiting\Console\Commands\CopyHcmContractTemplates::class,
                \Platform\Recruiting\Console\Commands\DeactivateHcmContractTemplates::class,
                \Platform\Recruiting\Console\Commands\CopyHcmContractExtraFields::class,
                \Platform\Recruiting\Console\Commands\SeedRecContractExtraFields::class,
                \Platform\Recruiting\Console\Commands\CreateArbeitsvertragVariants::class,
                \Platform\Recruiting\Console\Commands\DiagnoseContractExtraFields::class,
                \Platform\Recruiting\Console\Commands\DebugContractFieldResolution::class,
            ]);
        }
    }

    public function boot(): void
    {
        // Schedule FIRST — before any DB calls that could fail
        $this->registerSchedule();

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

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/recruiting.php' => config_path('recruiting.php'),
        ], 'config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'recruiting');
        $this->registerLivewireComponents();

        $this->registerCommsListeners();
        $this->registerTools();
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
