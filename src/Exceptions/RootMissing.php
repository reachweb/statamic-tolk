<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

/**
 * The target site's tree of a collection that expects a root is empty, and the entry is not the origin tree's root:
 * the first entry saved into it would become the site's home page (§A11 "Root page").
 */
final class RootMissing extends RuntimeException
{
    private function __construct(string $message, public readonly ?string $rootId)
    {
        parent::__construct($message);
    }

    public static function for(?string $rootId, string $rootTitle, string $siteName): self
    {
        return new self("Translate {$rootTitle} into {$siteName} first.", $rootId);
    }
}
