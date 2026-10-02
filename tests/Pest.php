<?php

use Reach\StatamicTolk\Tests\LiveTestCase;
use Reach\StatamicTolk\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');
uses(LiveTestCase::class)->group('live')->in('Live');
