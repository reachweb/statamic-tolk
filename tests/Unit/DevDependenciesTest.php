<?php

use Composer\InstalledVersions;
use Composer\Semver\Semver;

it('asks for a Pint that passes the committed code style', function () {
    $constraint = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true)['require-dev']['laravel/pint'];
    $reason = 'Pint 1.2.0 to 1.16.0 fail `pint --test` on the committed code and would reformat it; 1.16.1 and later pass it.';

    expect(Semver::satisfies('1.2.0', $constraint))->toBeFalse($reason)
        ->and(Semver::satisfies('1.16.0', $constraint))->toBeFalse($reason)
        ->and(Semver::satisfies('1.16.1', $constraint))->toBeTrue()
        ->and(version_compare((string) InstalledVersions::getVersion('laravel/pint'), '1.16.1', '>='))->toBeTrue($reason);
});
