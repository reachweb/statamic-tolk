<?php

use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\ScanResult;
use Reach\StatamicTolk\State\StatusScanner;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Facades\Nav;

beforeEach(function () {
    $this->useFileCache();
    $this->fakeTranslations();

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

    // The title is current machine output; the intro was translated by hand before the addon kept state: untracked.
    $current = $this->localize($this->makeEntry('articles', 'en', ['title' => 'Current', 'intro' => 'An intro.'], 'current'), 'el', ['title' => 'Τρέχον', 'intro' => 'Μια εισαγωγή.']);
    StateFixtures::store($current, ['title' => StateFixtures::machine('Current', 'Τρέχον')]);
});

function statusScanner(): StatusScanner
{
    return app(StatusScanner::class);
}

function scanAll(ContentType $type, string $container, string $site, int $budgetMs = 10_000): ScanResult
{
    $result = ScanResult::empty();

    do {
        $result = $result->add(statusScanner()->scan($type, $container, $site, $result->cursor, $budgetMs));
    } while (! $result->isFinished());

    return $result;
}

function itemCounts(array $counts): array
{
    return [...ScanResult::empty()->counts, ...$counts];
}

function translateFully(Target $target): void
{
    app(TargetTranslator::class)->translate($target, PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class)), new ApplyOptions);
}

describe('entries', function () {
    it('counts one target in each status, and the untracked manual fields', function () {
        $result = statusScanner()->scan(ContentType::Entry, 'articles', 'el', null, 10_000);

        expect($result->toArray())->toBe([
            'counts' => ['missing' => 1, 'untranslated' => 1, 'outdated' => 1, 'attention' => 1, 'current' => 1],
            'untracked' => 1,
            'scanned' => 5,
            'cursor' => null,
        ]);
    });

    it('reads every family without a localization in the site as missing', function () {
        expect(statusScanner()->scan(ContentType::Entry, 'articles', 'de', null, 10_000)->toArray())
            ->toBe(['counts' => itemCounts(['missing' => 5]), 'untracked' => 0, 'scanned' => 5, 'cursor' => null]);
    });

    it('returns a cursor when the budget runs out, and resuming from it gives the same totals', function () {
        $first = statusScanner()->scan(ContentType::Entry, 'articles', 'el', null, 0);
        $calls = 1;
        $result = $first;

        while (! $result->isFinished()) {
            $result = $result->add(statusScanner()->scan(ContentType::Entry, 'articles', 'el', $result->cursor, 0));
            $calls++;
        }

        expect($first->scanned)->toBe(1)
            ->and($first->cursor)->toBe('1')
            ->and($calls)->toBe(5)
            ->and($result)->toEqual(statusScanner()->scan(ContentType::Entry, 'articles', 'el', null, 10_000));
    });

    it('loads the roots of more than one chunk as it resolves them, and counts each target once', function () {
        foreach (range(1, StatusScanner::CHUNK + 5) as $number) {
            $this->makeEntry('articles', 'en', ['title' => "More {$number}"], "more-{$number}");
        }

        $sliced = statusScanner()->scan(ContentType::Entry, 'articles', 'el', null, 0);
        $chunks = 1;

        while (! $sliced->isFinished()) {
            $sliced = $sliced->add(statusScanner()->scan(ContentType::Entry, 'articles', 'el', $sliced->cursor, 0));
            $chunks++;
        }

        expect($chunks)->toBe(StatusScanner::CHUNK + 10)
            ->and($sliced)->toEqual(statusScanner()->scan(ContentType::Entry, 'articles', 'el', null, 10_000))
            ->and($sliced->toArray())->toBe([
                'counts' => ['missing' => StatusScanner::CHUNK + 6, 'untranslated' => 1, 'outdated' => 1, 'attention' => 1, 'current' => 1],
                'untracked' => 1,
                'scanned' => StatusScanner::CHUNK + 10,
                'cursor' => null,
            ]);
    });

    it('lists the sites a family\'s root is not in, and finds no targets in the site every root is in', function () {
        expect(statusScanner()->sites(ContentType::Entry, 'articles'))->toBe(['el', 'de'])
            ->and(statusScanner()->scan(ContentType::Entry, 'articles', 'en', null, 10_000))->toEqual(ScanResult::empty());

        $this->makeEntry('articles', 'el', ['title' => 'Μόνο ελληνικά'], 'greek-only');

        expect(statusScanner()->sites(ContentType::Entry, 'articles'))->toBe(['en', 'el', 'de'])
            ->and(scanAll(ContentType::Entry, 'articles', 'en')->toArray())
            ->toBe(['counts' => itemCounts(['missing' => 1]), 'untracked' => 0, 'scanned' => 1, 'cursor' => null])
            ->and(scanAll(ContentType::Entry, 'articles', 'el')->scanned)->toBe(5)
            ->and(scanAll(ContentType::Entry, 'articles', 'de')->scanned)->toBe(6);
    });

    it('finds no sites and no targets in a collection without entries, or in a site the collection lacks', function () {
        $this->makeCollection('news', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeCollection('offers', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeEntry('offers', 'en', ['title' => 'Spring'], 'spring');

        expect(statusScanner()->sites(ContentType::Entry, 'news'))->toBe([])
            ->and(statusScanner()->scan(ContentType::Entry, 'news', 'el', null, 10_000))->toEqual(ScanResult::empty())
            ->and(statusScanner()->sites(ContentType::Entry, 'offers'))->toBe(['el'])
            ->and(statusScanner()->scan(ContentType::Entry, 'offers', 'de', null, 10_000))->toEqual(ScanResult::empty());
    });
});

describe('other content types', function () {
    it('scans a taxonomy\'s terms in every site but its first, which are never missing', function () {
        $this->makeTaxonomy('topics', ['en', 'el', 'de'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTaxonomy('tags', ['en', 'el']);
        $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food']]);
        $this->makeTerm('topics', 'travel', ['en' => ['title' => 'Travel']]);
        translateFully(app(TargetFactory::class)->forTerm('topics::travel', 'el'));

        expect(statusScanner()->sites(ContentType::Term, 'topics'))->toBe(['el', 'de'])
            ->and(scanAll(ContentType::Term, 'topics', 'el')->toArray())
            ->toBe(['counts' => itemCounts(['untranslated' => 1, 'current' => 1]), 'untracked' => 0, 'scanned' => 2, 'cursor' => null])
            ->and(scanAll(ContentType::Term, 'topics', 'de')->counts)->toBe(itemCounts(['untranslated' => 2]))
            ->and(scanAll(ContentType::Term, 'topics', 'en'))->toEqual(ScanResult::empty())
            ->and(statusScanner()->sites(ContentType::Term, 'tags'))->toBe([]);
    });

    it('scans one target per site of a global set that has an origin there and a blueprint', function () {
        $fields = ['tagline' => ['type' => 'text', 'localizable' => true]];
        $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en', 'de' => 'en'], $fields, ['en' => ['tagline' => 'By the sea']]);
        $this->makeGlobalSet('footer', ['en' => null, 'el' => 'en'], null, ['en' => ['note' => 'Hello']]);
        $this->makeGlobalSet('contact', ['en' => null, 'de' => null], ['phone' => ['type' => 'text', 'localizable' => true]], ['en' => ['phone' => 'Call us']]);
        translateFully(app(TargetFactory::class)->forGlobalSet('site_settings', 'el'));

        expect(statusScanner()->sites(ContentType::GlobalSet, 'site_settings'))->toBe(['el', 'de'])
            ->and(scanAll(ContentType::GlobalSet, 'site_settings', 'el')->counts)->toBe(itemCounts(['current' => 1]))
            ->and(scanAll(ContentType::GlobalSet, 'site_settings', 'de')->counts)->toBe(itemCounts(['untranslated' => 1]))
            ->and(scanAll(ContentType::GlobalSet, 'site_settings', 'en'))->toEqual(ScanResult::empty())
            ->and(statusScanner()->sites(ContentType::GlobalSet, 'footer'))->toBe([])
            ->and(scanAll(ContentType::GlobalSet, 'footer', 'el'))->toEqual(ScanResult::empty())
            ->and(statusScanner()->sites(ContentType::GlobalSet, 'contact'))->toBe([])
            ->and(scanAll(ContentType::GlobalSet, 'contact', 'el'))->toEqual(ScanResult::empty());
    });

    it('scans a navigation in every site but the default site, a site without a tree as missing', function () {
        $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book your stay', 'url' => 'https://booking.example']]]);
        $this->makeNav('footer', ['el' => [['id' => 'contact', 'title' => 'Επικοινωνία', 'url' => '/el/contact']]]);
        translateFully(app(TargetFactory::class)->forNav('main', 'el'));

        expect(statusScanner()->sites(ContentType::Nav, 'main'))->toBe(['el', 'de'])
            ->and(scanAll(ContentType::Nav, 'main', 'el')->counts)->toBe(itemCounts(['current' => 1]))
            ->and(scanAll(ContentType::Nav, 'main', 'de')->counts)->toBe(itemCounts(['missing' => 1]))
            ->and(scanAll(ContentType::Nav, 'main', 'en'))->toEqual(ScanResult::empty())
            ->and(statusScanner()->sites(ContentType::Nav, 'footer'))->toBe([])
            ->and(scanAll(ContentType::Nav, 'footer', 'de'))->toEqual(ScanResult::empty());
    });

    it('scans a navigation translated from another site against the tree of the site its state records, the default site\'s once that site has none', function () {
        $this->makeNav('main', [
            'en' => [['id' => 'book', 'title' => 'Book your stay', 'url' => 'https://booking.example']],
            'de' => [['id' => 'book', 'title' => 'Aufenthalt buchen', 'url' => 'https://booking.example']],
        ]);
        $this->makeNav('footer', ['de' => [['id' => 'legal', 'title' => 'Impressum']]]);
        translateFully(app(TargetFactory::class)->forNav('main', 'el', 'de'));
        translateFully(app(TargetFactory::class)->forNav('footer', 'el', 'de'));

        $fromGerman = [scanAll(ContentType::Nav, 'main', 'el')->counts, statusScanner()->sites(ContentType::Nav, 'footer'), scanAll(ContentType::Nav, 'footer', 'el')->counts];
        Nav::findByHandle('main')->in('de')->delete();
        Nav::findByHandle('footer')->in('de')->delete();

        expect($fromGerman)->toBe([itemCounts(['current' => 1]), ['el'], itemCounts(['current' => 1])])
            ->and(statusScanner()->sites(ContentType::Nav, 'main'))->toBe(['el', 'de'])
            ->and(scanAll(ContentType::Nav, 'main', 'el')->counts)->toBe(itemCounts(['outdated' => 1]))
            ->and(statusScanner()->sites(ContentType::Nav, 'footer'))->toBe([])
            ->and(scanAll(ContentType::Nav, 'footer', 'el'))->toEqual(ScanResult::empty());
    });
});

describe('containers', function () {
    it('covers the multi-site collections and taxonomies the settings do not exclude, multi-site global sets and every navigation', function () {
        $this->makeCollection('news', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeCollection('archive', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTaxonomy('topics', ['en', 'el']);
        $this->makeTaxonomy('tags', ['en', 'el']);
        $this->makeTaxonomy('internal', ['en']);
        $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], ['tagline' => ['type' => 'text', 'localizable' => true]], []);
        $this->makeGlobalSet('english_only', ['en' => null], ['tagline' => ['type' => 'text', 'localizable' => true]], []);
        $this->makeNav('main', ['en' => []]);
        $this->makeNav('footer', []);
        $this->withSettings(['excluded_collections' => ['news'], 'excluded_taxonomies' => ['tags']]);

        expect(statusScanner()->containers(ContentType::Entry))->toBe(['articles'])
            ->and(statusScanner()->containers(ContentType::Term))->toBe(['topics'])
            ->and(statusScanner()->containers(ContentType::GlobalSet))->toBe(['site_settings'])
            ->and(statusScanner()->containers(ContentType::Nav))->toEqualCanonicalizing(['main', 'footer']);
    });
});

describe('input', function () {
    it('refuses an unknown container, site or cursor', function (Closure $scan, string $exception, string $message) {
        expect($scan)->toThrow($exception, $message);
    })->with([
        'collection' => [fn () => statusScanner()->scan(ContentType::Entry, 'nope', 'el', null, 100), InvalidArgumentException::class, 'Collection [nope] not found.'],
        'taxonomy' => [fn () => statusScanner()->sites(ContentType::Term, 'nope'), InvalidArgumentException::class, 'Taxonomy [nope] not found.'],
        'global set' => [fn () => statusScanner()->scan(ContentType::GlobalSet, 'nope', 'el', null, 100), InvalidArgumentException::class, 'Global set [nope] not found.'],
        'navigation' => [fn () => statusScanner()->sites(ContentType::Nav, 'nope'), InvalidArgumentException::class, 'Navigation [nope] not found.'],
        'site' => [fn () => statusScanner()->scan(ContentType::Entry, 'articles', 'fr', null, 100), UnknownSite::class, 'Unknown site [fr].'],
        'cursor' => [fn () => statusScanner()->scan(ContentType::Entry, 'articles', 'el', 'abc', 100), InvalidArgumentException::class, 'Invalid scan cursor [abc].'],
    ]);

    it('finishes at once from a cursor past the last target', function () {
        expect(statusScanner()->scan(ContentType::Entry, 'articles', 'el', '9', 0))->toEqual(ScanResult::empty());
    });
});

describe('ScanResult', function () {
    it('round-trips through an array and reads damaged values as zero', function () {
        $result = statusScanner()->scan(ContentType::Entry, 'articles', 'el', null, 0);

        expect(ScanResult::fromArray($result->toArray()))->toEqual($result)
            ->and(ScanResult::fromArray(['counts' => ['missing' => 'x', 'current' => -2, 'other' => 4], 'untracked' => '3', 'cursor' => 5]))
            ->toEqual(new ScanResult(itemCounts(['missing' => 0]), untracked: 3));
    });
});
