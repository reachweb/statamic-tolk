<?php

namespace Reach\StatamicTolk\Fieldtypes;

use Statamic\Fields\Fieldtype;

/**
 * The site profile setting: a text the editor writes or has a model write from the site's content, stored as a string.
 */
class SiteProfile extends Fieldtype
{
    protected static $handle = 'tolk_site_profile';

    protected $selectable = false;

    public function preProcess($data): string
    {
        return is_string($data) ? $data : '';
    }

    public function process($data): ?string
    {
        return is_string($data) && trim($data) !== '' ? trim($data) : null;
    }
}
