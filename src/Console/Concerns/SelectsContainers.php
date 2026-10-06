<?php

namespace Reach\StatamicTolk\Console\Concerns;

use InvalidArgumentException;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\State\StatusScanner;
use Statamic\Facades\Site;

/**
 * The containers and sites a command covers, from its --site, --collection, --taxonomy, --globals and --nav options:
 * those named, or every container StatusScanner::containers() covers when none is, in each site it has targets in.
 */
trait SelectsContainers
{
    private const array CONTAINER_OPTIONS = ['collection' => ContentType::Entry, 'taxonomy' => ContentType::Term, 'globals' => ContentType::GlobalSet, 'nav' => ContentType::Nav];

    private const array CONTAINER_TYPES = ['entry' => 'Collection', 'term' => 'Taxonomy', 'globals' => 'Global set', 'nav' => 'Navigation'];

    /**
     * Every container and site to cover, each container checked before anything is done.
     *
     * @return list<array{type: ContentType, container: string, site: string}>
     *
     * @throws InvalidArgumentException|UnknownSite
     */
    private function cells(StatusScanner $scanner): array
    {
        $sites = $this->selectedSites();
        $named = [];

        foreach (array_keys(self::CONTAINER_OPTIONS) as $option) {
            $named[$option] = array_values(array_unique($this->option($option)));
        }

        $everything = array_merge(...array_values($named)) === [];
        $cells = [];

        foreach (self::CONTAINER_OPTIONS as $option => $type) {
            $containers = $everything ? $scanner->containers($type) : $named[$option];

            foreach ($containers as $container) {
                foreach ($scanner->sites($type, $container) as $site) {
                    if ($sites === [] || in_array($site, $sites, true)) {
                        $cells[] = compact('type', 'container', 'site');
                    }
                }
            }
        }

        return $cells;
    }

    /**
     * @return list<string> empty for every site
     */
    private function selectedSites(): array
    {
        $sites = array_values(array_unique($this->option('site')));

        foreach ($sites as $site) {
            if (Site::get($site) === null) {
                throw UnknownSite::for($site);
            }
        }

        return $sites;
    }
}
