<?php

namespace Reach\StatamicTolk\Tests\Support\Fieldtypes;

use Statamic\Fields\Fieldtype;

/**
 * The fieldtype of an addon that boots after Tolk, for the boot-order test only: the per-field options Statamic keeps
 * for it outlive each test's app, so no other test may register it.
 */
final class LateFieldtype extends Fieldtype
{
    protected static $handle = 'tolk_test_late';
}
