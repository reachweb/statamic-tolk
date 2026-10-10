<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

final class TargetNotFound extends RuntimeException
{
    public static function entry(string $id): self
    {
        return new self(__('Entry [:id] not found.', ['id' => $id]));
    }

    public static function term(string $id, ?string $site = null): self
    {
        return new self($site === null ? __('Term [:id] not found.', ['id' => $id]) : __('Term [:id] not found in the site [:site].', ['id' => $id, 'site' => $site]));
    }

    public static function globalSet(string $handle, ?string $site = null): self
    {
        return new self($site === null ? __('Global set [:handle] not found.', ['handle' => $handle]) : __('Global set [:handle] not found in the site [:site].', ['handle' => $handle, 'site' => $site]));
    }

    public static function nav(string $handle, ?string $site = null): self
    {
        return new self($site === null ? __('Navigation [:handle] not found.', ['handle' => $handle]) : __('Navigation [:handle] has no tree in the site [:site].', ['handle' => $handle, 'site' => $site]));
    }

    public static function lang(string $file): self
    {
        return new self(__('Translation file [:file] not found.', ['file' => $file]));
    }

    /**
     * Any type: the target a reference names, such as a plan's, is gone, or what now stands in its place is not the
     * one it names (a localization deleted and created again).
     */
    public static function reference(string $reference): self
    {
        return new self(__('[:reference] no longer exists.', ['reference' => $reference]));
    }
}
