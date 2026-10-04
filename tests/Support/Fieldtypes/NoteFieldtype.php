<?php

namespace Reach\StatamicTolk\Tests\Support\Fieldtypes;

use Statamic\Fields\Fieldtype;

/**
 * A fieldtype only the tests know. Statamic keeps appended config fields in a static array that outlives each test's
 * app, so tests that register a handle through Tolk use this one and leave core fieldtypes alone.
 */
final class NoteFieldtype extends Fieldtype
{
    protected static $handle = 'tolk_test_note';
}
