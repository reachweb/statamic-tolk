<?php

namespace Reach\StatamicTolk\Targets;

use InvalidArgumentException;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Contracts\Auth\User;

final readonly class ApplyOptions
{
    /**
     * @param  bool  $createdEarlier  an earlier attempt at the same plan created the target and threw: a localization is
     *                                finished as one created in this operation, with its published state and slug (§A11)
     */
    public function __construct(
        public ?User $user = null,
        public string $newStatus = 'draft',
        public bool $publishChanges = false,
        public bool $overwriteManual = false,
        public bool $createdEarlier = false,
    ) {
        if (! in_array($newStatus, Settings::NEW_LOCALIZATION_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid new localization status [{$newStatus}]. Use draft or mirror.");
        }
    }
}
