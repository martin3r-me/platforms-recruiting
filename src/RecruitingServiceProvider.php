<?php

namespace Platform\Recruiting;

use Illuminate\Database\Eloquent\Relations\Relation;
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

            // Applicants (Read + Write)
            $registry->register(new \Platform\Recruiting\Tools\ListApplicantsTool());
            $registry->register(new \Platform\Recruiting\Tools\GetApplicantTool());
            $registry->register(new \Platform\Recruiting\Tools\CreateApplicantTool());
            $registry->register(new \Platform\Recruiting\Tools\UpdateApplicantTool());
            $registry->register(new \Platform\Recruiting\Tools\DeleteApplicantTool());

            // Applicant ↔ CRM Contact Links
            $registry->register(new \Platform\Recruiting\Tools\LinkApplicantContactTool());
            $registry->register(new \Platform\Recruiting\Tools\UnlinkApplicantContactTool());
        } catch (\Throwable $e) {
            \Log::warning('Recruiting: Tool-Registrierung fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }
}
