<?php

namespace Reach\StatamicTolk\Exceptions;

use InvalidArgumentException;

final class InvalidTarget extends InvalidArgumentException
{
    public static function sameSite(string $id, string $site): self
    {
        return new self("Entry [{$id}] is already in the site [{$site}], so it cannot be its own translation target.");
    }

    public static function siteNotInCollection(string $collection, string $site): self
    {
        return new self("The collection [{$collection}] is not available in the site [{$site}].");
    }

    public static function defaultSite(string $id, string $site): self
    {
        return new self("The site [{$site}] is the default site of term [{$id}], which the other sites are translated from.");
    }

    public static function siteNotInTaxonomy(string $taxonomy, string $site): self
    {
        return new self("The taxonomy [{$taxonomy}] is not available in the site [{$site}].");
    }

    public static function description(array $describe): self
    {
        return new self('Invalid target description '.json_encode($describe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'. Use type, source_id and site.');
    }
}
