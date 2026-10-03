<?php

namespace Reach\StatamicTolk\Targets;

use InvalidArgumentException;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Contracts\Auth\User;

final readonly class ApplyOptions
{
    public function __construct(
        public ?User $user = null,
        public string $newStatus = 'draft',
        public bool $publishChanges = false,
        public bool $overwriteManual = false,
    ) {
        if (! in_array($newStatus, Settings::NEW_LOCALIZATION_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid new localization status [{$newStatus}]. Use draft or mirror.");
        }
    }
}
