<?php

namespace Reach\StatamicTolk\Exceptions;

use InvalidArgumentException;

final class InvalidTarget extends InvalidArgumentException
{
    public static function sameSite(string $id, string $site): self
    {
        return new self(__('Entry [:id] is already in the site [:site], so it cannot be its own translation target.', ['id' => $id, 'site' => $site]));
    }

    public static function siteNotInCollection(string $collection, string $site): self
    {
        return new self(__('The collection [:collection] is not available in the site [:site].', ['collection' => $collection, 'site' => $site]));
    }

    public static function defaultSite(string $id, string $site): self
    {
        return new self(__('The site [:site] is the default site of term [:id], which the other sites are translated from.', ['site' => $site, 'id' => $id]));
    }

    public static function siteNotInTaxonomy(string $taxonomy, string $site): self
    {
        return new self(__('The taxonomy [:taxonomy] is not available in the site [:site].', ['taxonomy' => $taxonomy, 'site' => $site]));
    }

    public static function navSource(string $handle, string $site): self
    {
        return new self(__('The site [:site] is the source of the navigation [:handle], so it cannot be its own translation target.', ['site' => $site, 'handle' => $handle]));
    }

    public static function langSource(string $lang): self
    {
        return new self(__('The language [:lang] is the source language, so it cannot be its own translation target.', ['lang' => $lang]));
    }

    public static function langWithoutSite(string $lang): self
    {
        return new self(__('No site uses the language [:lang].', ['lang' => $lang]));
    }

    public static function description(array $describe): self
    {
        return new self('Invalid target description '.json_encode($describe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'. Use type, source_id and site.');
    }
}
