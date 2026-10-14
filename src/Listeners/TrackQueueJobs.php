<?php

namespace Reach\StatamicTolk\Listeners;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Reach\StatamicTolk\Runs\RunDispatcher;

/**
 * Follows the queue jobs this process works on, from JobProcessing until JobAttempted, which Laravel fires after every
 * job, failed or not. A queue worker's process ends only when the worker stops, so an update dispatched after the
 * response of a job's save would wait until then: Jobs\UpdateTranslations goes on that job's queue instead (T11.1).
 * A singleton, so the listener the events reach is the one asked.
 */
final class TrackQueueJobs
{
    /**
     * @var list<array{job: Job, connection: string}> the jobs being worked on, the outermost first
     */
    private array $jobs = [];

    public function handleProcessing(JobProcessing $event): void
    {
        $this->jobs[] = ['job' => $event->job, 'connection' => $event->connectionName];
    }

    public function handleAttempted(JobAttempted $event): void
    {
        $this->jobs = array_values(array_filter($this->jobs, fn (array $worked) => $worked['job'] !== $event->job));
    }

    /**
     * The connection and queue of the outermost job this process works on whose connection has a worker, so a job
     * pushed there is worked on; null outside such a job, also inside a job of a `sync` connection in a web request.
     *
     * @return ?array{connection: string, queue: ?string}
     */
    public function worker(): ?array
    {
        foreach ($this->jobs as $worked) {
            if (RunDispatcher::hasWorker($worked['connection'])) {
                return ['connection' => $worked['connection'], 'queue' => $worked['job']->getQueue()];
            }
        }

        return null;
    }
}
