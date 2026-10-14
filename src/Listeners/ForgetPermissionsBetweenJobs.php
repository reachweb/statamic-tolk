<?php

namespace Reach\StatamicTolk\Listeners;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Facade;
use Statamic\Auth\PermissionCache;
use Statamic\Contracts\Auth\RoleRepository;
use Statamic\Contracts\Auth\UserGroupRepository;
use Statamic\Statamic;

/**
 * Has a queue worker read users' roles, groups and permissions again before each job, as Statamic has it read the Stache
 * again. Core keeps them in singletons that outlive a job, so a site access or edit right revoked in another process
 * would still let an automatic update or a run's lane translate and save with it until the worker restarts (§A14). Only
 * in a worker: a request's jobs on the `sync` queue end with the request.
 */
final readonly class ForgetPermissionsBetweenJobs
{
    public function __construct(private Application $app) {}

    public function handle(JobProcessing $event): void
    {
        if (! Statamic::isWorker()) {
            return;
        }

        $this->app->make(PermissionCache::class)->clear();

        // Built again by the user repository, which makes a new one each time; `queue:work --once` keeps the facades'.
        foreach ([RoleRepository::class, UserGroupRepository::class] as $repository) {
            $this->app->forgetInstance($repository);
            Facade::clearResolvedInstance($repository);
        }
    }
}
