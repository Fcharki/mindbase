<?php

namespace App\Providers;

use App\Models\KnowledgeEntry;
use App\Models\Workspace;
use App\Observers\KnowledgeEntryObserver;
use App\Observers\WorkspaceObserver;
use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Workspace::observe(WorkspaceObserver::class);
        KnowledgeEntry::observe(KnowledgeEntryObserver::class);
    }
}
