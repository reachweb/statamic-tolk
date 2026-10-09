<?php

use Illuminate\Support\Facades\Artisan;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\StatusScanner;
use Reach\StatamicTolk\Tests\Support\StateFixtures;

const STATUS_HEADERS = ['Type', 'Handle', 'Site', 'Missing', 'Untranslated', 'Outdated', 'Needs attention', 'Current', 'Untracked fields'];

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);

    $this->makeEntry('articles', 'en', ['title' => 'Missing'], 'missing');
    $this->localize($this->makeEntry('articles', 'en', ['title' => 'Untranslated'], 'untranslated'), 'el');

    $outdated = $this->localize($this->makeEntry('articles', 'en', ['title' => 'Outdated'], 'outdated'), 'el', ['title' => 'Παλιό']);
    StateFixtures::store($outdated, ['title' => StateFixtures::machine('Old', 'Παλιό')]);

    $attention = $this->localize($this->makeEntry('articles', 'en', ['title' => 'Attention'], 'attention'), 'el', ['title' => 'Χειρόγραφο']);
    StateFixtures::store($attention, ['title' => new FieldState(sourceHash: Fingerprint::of('Before'))]);

    $current = $this->localize($this->makeEntry('articles', 'en', ['title' => 'Current', 'intro' => 'An intro.'], 'current'), 'el', ['title' => 'Τρέχον', 'intro' => 'Μια εισαγωγή.']);
    StateFixtures::store($current, ['title' => StateFixtures::machine('Current', 'Τρέχον')]);

    $this->makeTaxonomy('topics', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food']]);
    $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], ['tagline' => ['type' => 'text', 'localizable' => true]], ['en' => ['tagline' => 'By the sea']]);
    $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book your stay', 'url' => 'https://booking.example']]]);
});

/**
 * @return array{0: int, 1: string}
 */
function statusCommand(array $options = []): array
{
    $exitCode = Artisan::call('tolk:status', $options);

    return [$exitCode, Artisan::output()];
}

function statusJson(array $options = []): array
{
    [$exitCode, $output] = statusCommand(['--json' => true, ...$options]);

    expect($exitCode)->toBe(0);

    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
}

describe('table', function () {
    it('prints one row per container and site with the five counts, and points to tolk:baseline for untracked fields', function () {
        $this->artisan('tolk:status')
            ->expectsTable(STATUS_HEADERS, [
                ['Collection', 'articles', 'el', '1', '1', '1', '1', '1', '1'],
                ['Collection', 'articles', 'de', '5', '0', '0', '0', '0', '0'],
                ['Taxonomy', 'topics', 'el', '0', '1', '0', '0', '0', '0'],
                ['Global set', 'site_settings', 'el', '0', '1', '0', '0', '0', '0'],
                ['Navigation', 'main', 'el', '1', '0', '0', '0', '0', '0'],
                ['Navigation', 'main', 'de', '1', '0', '0', '0', '0', '0'],
            ])
            ->expectsOutputToContain('1 field was translated by hand without a record of its origin, so a change to the origin goes unnoticed. Run php please tolk:baseline to record its origin.')
            ->assertExitCode(0);
    });

    it('says what tolk:baseline records when several fields are untracked', function () {
        $this->localize($this->makeEntry('articles', 'en', ['title' => 'By hand'], 'by-hand'), 'el', ['title' => 'Με το χέρι']);

        [$exitCode, $output] = statusCommand(['--site' => ['el'], '--collection' => ['articles']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('2 fields were translated by hand without a record of their origin, so a change to the origin goes unnoticed. Run php please tolk:baseline to record the origin of each.')
            ->and($output)->not->toContain('to record it.');
    });

    it('shows only the sites and containers named', function () {
        $this->artisan('tolk:status', ['--site' => ['de'], '--collection' => ['articles'], '--nav' => ['main']])
            ->expectsTable(STATUS_HEADERS, [
                ['Collection', 'articles', 'de', '5', '0', '0', '0', '0', '0'],
                ['Navigation', 'main', 'de', '1', '0', '0', '0', '0', '0'],
            ])
            ->doesntExpectOutputToContain('tolk:baseline')
            ->assertExitCode(0);
    });

    it('leaves out what the settings exclude unless it is named', function () {
        $this->withSettings(['excluded_collections' => ['articles'], 'excluded_taxonomies' => ['topics']]);

        expect(array_column(statusJson(), 'container'))->toBe(['site_settings', 'main', 'main'])
            ->and(array_column(statusJson(['--collection' => ['articles'], '--taxonomy' => ['topics']]), 'container'))->toBe(['articles', 'articles', 'topics']);
    });

    it('says so when nothing has a site to translate into', function () {
        [$exitCode, $output] = statusCommand(['--site' => ['en']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Nothing to show: no content selected has a site to translate into.');
    });

    it('exits 1 for an unknown site or container', function (array $options, string $message) {
        [$exitCode, $output] = statusCommand($options);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain($message);
    })->with([
        'site' => [['--site' => ['fr']], 'Unknown site [fr].'],
        'collection' => [['--collection' => ['nope']], 'Collection [nope] not found.'],
        'taxonomy' => [['--taxonomy' => ['nope']], 'Taxonomy [nope] not found.'],
        'global set' => [['--globals' => ['nope']], 'Global set [nope] not found.'],
        'navigation' => [['--nav' => ['nope']], 'Navigation [nope] not found.'],
    ]);
});

describe('--json', function () {
    it('prints the rows with their counts, untracked fields and the targets scanned', function () {
        expect(statusJson(['--site' => ['el'], '--collection' => ['articles'], '--globals' => ['site_settings']]))->toBe([
            [
                'type' => 'entry',
                'container' => 'articles',
                'site' => 'el',
                'counts' => ['missing' => 1, 'untranslated' => 1, 'outdated' => 1, 'attention' => 1, 'current' => 1],
                'untracked' => 1,
                'scanned' => 5,
            ],
            [
                'type' => 'globals',
                'container' => 'site_settings',
                'site' => 'el',
                'counts' => ['missing' => 0, 'untranslated' => 1, 'outdated' => 0, 'attention' => 0, 'current' => 0],
                'untracked' => 0,
                'scanned' => 1,
            ],
        ]);
    });

    it('gives the totals of the scanner in slices of any size', function () {
        $scanner = app(StatusScanner::class);

        foreach (statusJson() as $row) {
            $counts = array_fill_keys(array_keys($row['counts']), 0);
            $cursor = null;
            $scanned = 0;

            do {
                $slice = $scanner->scan(ContentType::from($row['type']), $row['container'], $row['site'], $cursor, 0);
                $cursor = $slice->cursor;
                $scanned += $slice->scanned;

                foreach ($slice->counts as $status => $count) {
                    $counts[$status] += $count;
                }
            } while ($cursor !== null);

            expect($counts)->toBe($row['counts'])
                ->and($scanned)->toBe($row['scanned']);
        }
    });

    it('prints an empty list when nothing has a site to translate into', function () {
        expect(statusJson(['--site' => ['en']]))->toBe([]);
    });
});
