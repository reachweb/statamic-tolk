<?php

namespace Reach\StatamicTolk\State;

use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\ItemStatus;

final readonly class TargetStatus
{
    /**
     * @param  array<string, FieldStatusInfo>  $fields  by handle, in blueprint order
     * @param  array<string, int>  $counts  per field status but `not_applicable`, then `attention` and `untracked`, which count manual fields
     * @param  int  $stateVersion  the format version of the stored state; translations of another version are not reused
     */
    public function __construct(
        public bool $exists,
        public ItemStatus $itemStatus,
        public array $fields,
        public array $counts,
        public int $stateVersion = ItemState::VERSION,
    ) {}

    /**
     * @param  array<string, FieldStatusInfo>  $fields
     */
    public static function of(bool $exists, array $fields, int $stateVersion = ItemState::VERSION): self
    {
        $counts = [];

        foreach (FieldStatus::cases() as $status) {
            if ($status !== FieldStatus::NotApplicable) {
                $counts[$status->value] = 0;
            }
        }

        $counts += ['attention' => 0, 'untracked' => 0];
        $statuses = [];

        foreach ($fields as $field) {
            if (($status = $field->itemStatus()) === null) {
                continue;
            }

            $statuses[] = $status;
            $counts[$field->status->value]++;
            $counts['attention'] += (int) $field->needsAttention();
            $counts['untracked'] += (int) $field->isUntracked();
        }

        return new self($exists, $exists ? ItemStatus::worst(...$statuses) : ItemStatus::Missing, $fields, $counts, $stateVersion);
    }
}
