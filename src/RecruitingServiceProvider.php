<?php

namespace Platform\Recruiting;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
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
            ]);
        }
    }

    public function boot(): void
    {
        Relation::morphMap([
            'rec_applicant' => \Platform\Recruiting\Models\RecApplicant::class,
        ]);

        $this->mergeConfigFrom(__DIR__.'/../config/recruiting.php', 'recruiting');

        if (
            config()->has('recruiting.routing') &&
            config()->has('recruiting.navigation') &&
            Schema::hasTable('modules')
        ) {
            PlatformCore::registerModule([
                'key'        => 'recruiting',
                'title'      => 'Recruiting',
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

        Route::prefix('recruiting')->middleware(['web'])->group(function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/public.php');
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/recruiting.php' => config_path('recruiting.php'),
        ], 'config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'recruiting');
        $this->registerLivewireComponents();

        $this->registerTools();
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

            // Applicant ↔ CRM Contact Links
            $registry->register(new \Platform\Recruiting\Tools\LinkApplicantContactTool());
            $registry->register(new \Platform\Recruiting\Tools\UnlinkApplicantContactTool());

            // Applicant ↔ Posting Links
            $registry->register(new \Platform\Recruiting\Tools\LinkApplicantPostingTool());
            $registry->register(new \Platform\Recruiting\Tools\UnlinkApplicantPostingTool());
        } catch (\Throwable $e) {
            \Log::warning('Recruiting: Tool-Registrierung fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }
}
