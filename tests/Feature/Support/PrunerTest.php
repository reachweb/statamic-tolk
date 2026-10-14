<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\Support\Pruner;
use Reach\StatamicTolk\Support\PruneReport;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;

beforeEach(function () {
    $this->useFileCache();
    $this->freezeSecond();
    Queue::fake();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
    ]);

    $this->hello = $this->makeEntry('articles', 'en', ['title' => 'Hello'], 'hello');
});

function prunerState(TargetRef $ref, ?string $sourceSite = null): void
{
    app(StateRepository::class)->update($ref, function (ItemState $state) use ($sourceSite) {
        $state = $state->withField('title', new FieldState(sourceHash: 'a1b2c3d4e5f60718'));

        return $sourceSite === null ? $state : $state->withSourceSite($sourceSite);
    });
}

function pruned(bool $dryRun = false): PruneReport
{
    return app(Pruner::class)->prune($dryRun);
}

function prunerTranslate(EntryContract $origin, string $site): EntryContract
{
    app(TargetTranslator::class)->translate(app(TargetFactory::class)->forEntry($origin, $site), PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class)), new ApplyOptions);

    return Entry::find($origin->id())->in($site);
}

it('keeps the state of content in a site its collection or taxonomy no longer has, also once the Stache is rebuilt, so it reads as before when the site returns', function () {
    $this->fakeTranslations();
    $localization = prunerTranslate($this->hello, 'el');
    $this->makeTaxonomy('topics', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food'], 'el' => ['title' => 'Φαγητό']]);
    prunerState(new TargetRef(ContentType::Term, 'topics::food', 'el'));
    $stored = StateFixtures::stored($localization)->toArray();

    Collection::find('articles')->sites(['en', 'de'])->save();
    Taxonomy::find('topics')->sites(['en'])->save();
    // As stache:clear, stache:warm or a deploy does: core indexes no entry of a site its collection does not list.
    Stache::clear();
    // A new request reads the taxonomy again; Term::taxonomy() keeps it in Blink, which Taxonomy::save() does not clear.
    Blink::forget('taxonomy-topics');

    expect(Entry::find($localization->id()))->toBeNull()
        ->and(pruned()->isEmpty())->toBeTrue()
        ->and(StateFixtures::stored($localization)->toArray())->toBe($stored)
        ->and(app(StateRepository::class)->get(new TargetRef(ContentType::Term, 'topics::food', 'el'))->isEmpty())->toBeFalse();

    Collection::find('articles')->sites(['en', 'el', 'de'])->save();
    Stache::clear();

    expect(StateFixtures::statuses($this->hello))->toBe(['title' => FieldStatus::Current]);
});

it('records the collection and site in an entry\'s state that lacks them, as 1.0.0-beta.1 wrote it, so the state is kept once its site leaves the collection and the Stache is rebuilt', function () {
    $localization = $this->localize($this->hello, 'el', ['title' => 'Γεια']);
    // As 1.0.0-beta.1 wrote an entry's state, before entries' states recorded their collection and site.
    StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια')]);
    $stored = StateFixtures::stored($localization)->toArray();
    $commits = RunFixtures::commits();

    $dryRun = pruned(dryRun: true);
    $untouched = StateFixtures::stored($localization)->toArray();
    $report = pruned();
    $located = StateFixtures::stored($localization)->toArray();

    expect($stored)->not->toHaveKey('collection')
        ->and($dryRun->located)->toBe(1)
        ->and($untouched)->toBe($stored)
        ->and($report->located)->toBe(1)
        ->and($commits->getArrayCopy())->toBe([StateWriter::COMMIT_MESSAGE])
        ->and($located)->toMatchArray(['collection' => 'articles', 'site' => 'el'])
        ->and(Arr::except($located, ['collection', 'site']))->toBe($stored)
        ->and(pruned()->isEmpty())->toBeTrue()
        ->and($commits->getArrayCopy())->toBe([StateWriter::COMMIT_MESSAGE]);

    Collection::find('articles')->sites(['en', 'de'])->save();
    Stache::clear();

    expect(pruned()->isEmpty())->toBeTrue()
        ->and(StateFixtures::stored($localization)->toArray())->toBe($located);

    Collection::find('articles')->sites(['en', 'el', 'de'])->save();
    Stache::clear();

    expect(StateFixtures::statuses($this->hello))->toBe(['title' => FieldStatus::Current]);
});

it('keeps the state of an entry in a site its collection no longer has while core still finds it, and records the collection and site in one that lacks them for the time it no longer does', function () {
    $localization = $this->localize($this->hello, 'el', ['title' => 'Γεια']);
    // As 1.0.0-beta.1 wrote an entry's state, before entries' states recorded their collection and site.
    StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια')]);
    $stored = StateFixtures::stored($localization)->toArray();

    Collection::find('articles')->sites(['en', 'de'])->save();

    expect(Entry::find($localization->id()))->not->toBeNull()
        ->and($stored)->not->toHaveKey('collection')
        ->and(pruned()->located)->toBe(1)
        ->and(StateFixtures::stored($localization)->toArray())->toMatchArray(['collection' => 'articles', 'site' => 'el', 'fields' => $stored['fields']]);

    Stache::clear();

    expect(Entry::find($localization->id()))->toBeNull()
        ->and(pruned()->isEmpty())->toBeTrue()
        ->and(StateFixtures::stored($localization)->fields)->toHaveKey('title');
});

it('deletes the state of an entry deleted from a site its collection has, and of one whose collection is gone', function () {
    $this->fakeTranslations();
    $deleted = prunerTranslate($this->hello, 'el');
    $this->makeCollection('pages', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $about = prunerTranslate($this->makeEntry('pages', 'en', ['title' => 'About'], 'about'), 'el');

    // Without events, as a deployment or a pull removes the files.
    $deleted->deleteQuietly();
    Collection::find('pages')->deleteQuietly();
    Stache::clear();

    expect(pruned()->orphaned(PruneReport::DELETED))->toEqualCanonicalizing(['entry::'.$deleted->id(), 'entry::'.$about->id()])
        ->and(iterator_to_array(app(StateRepository::class)->references(), false))->toBe([]);
});

it('keeps the state of a term, a global set and a navigation in a site the configuration leaves out now', function () {
    $this->makeTaxonomy('topics', ['en', 'de'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food'], 'de' => ['title' => 'Essen']]);
    $this->makeGlobalSet('site_settings', ['en' => null, 'de' => 'en'], ['tagline' => ['type' => 'text', 'localizable' => true]], ['en' => ['tagline' => 'By the sea']]);
    $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book now']], 'de' => [['id' => 'book', 'title' => 'Jetzt buchen']]]);
    $references = ['term::topics::food::de', 'globals::site_settings::de', 'nav::main::de'];

    foreach ($references as $reference) {
        prunerState(TargetRef::parse($reference));
    }

    Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
        'el' => ['name' => 'Greek', 'url' => 'http://localhost/el/', 'locale' => 'el_GR', 'lang' => 'el'],
    ]);

    expect(pruned()->orphans)->toBe([])
        ->and(array_map('strval', iterator_to_array(app(StateRepository::class)->references(), false)))->toEqualCanonicalizing($references);
});

it('deletes the state of a global set\'s site without an origin and of a navigation\'s tree that is gone, and reports each reason', function () {
    $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], ['tagline' => ['type' => 'text', 'localizable' => true]], ['en' => ['tagline' => 'By the sea']]);
    $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book your stay', 'url' => 'https://booking.example']]]);
    prunerState(new TargetRef(ContentType::GlobalSet, 'site_settings', 'el'));
    prunerState(new TargetRef(ContentType::Nav, 'main', 'de'));
    prunerState(new TargetRef(ContentType::Nav, 'footer', 'el'));

    GlobalSet::findByHandle('site_settings')->sites(['en' => null, 'el' => null])->save();

    $dryRun = pruned(dryRun: true);
    $report = pruned();

    expect($dryRun)->toEqual(new PruneReport(...[...get_object_vars($report), 'dryRun' => true]))
        ->and($report->orphaned(PruneReport::NOT_A_LOCALIZATION))->toBe(['globals::site_settings::el'])
        ->and($report->orphaned(PruneReport::DELETED))->toEqualCanonicalizing(['nav::main::de', 'nav::footer::el'])
        ->and(iterator_to_array(app(StateRepository::class)->references(), false))->toBe([]);
});

it('deletes the state of a translation file whose source file is gone, and keeps that of a language no site uses', function () {
    $this->app->useLangPath($this->temporaryPath('lang'));
    (new Filesystem)->ensureDirectoryExists(lang_path('en'));
    file_put_contents(lang_path('en.json'), '{"Book now": "Book now"}');
    file_put_contents(lang_path('de.json'), '{"Book now": "Jetzt buchen"}');
    prunerState(new TargetRef(ContentType::Lang, '_json', 'de'));
    prunerState(new TargetRef(ContentType::Lang, 'messages', 'de'));
    prunerState(new TargetRef(ContentType::Lang, '_json', 'fr'));
    prunerState(new TargetRef(ContentType::Lang, '_json', 'en'));

    $report = pruned();

    expect($report->orphaned(PruneReport::DELETED))->toBe(['lang::messages::de'])
        ->and($report->orphaned(PruneReport::NOT_A_LOCALIZATION))->toBe(['lang::_json::en'])
        ->and(array_map('strval', iterator_to_array(app(StateRepository::class)->references(), false)))->toEqualCanonicalizing(['lang::_json::de', 'lang::_json::fr']);
});

it('keeps the state of a translation file and of form texts whose file cannot be read, and prunes the rest', function () {
    $this->app->useLangPath($this->temporaryPath('lang'));
    (new Filesystem)->ensureDirectoryExists(lang_path());
    file_put_contents(lang_path('en.json'), '{"Book now": "Book now"}');
    file_put_contents(lang_path('de.json'), 'Jetzt buchen');
    // Pending entries make confirm() read the file.
    foreach ([new TargetRef(ContentType::Lang, '_json', 'de'), new TargetRef(ContentType::Forms, 'de')] as $ref) {
        app(StateRepository::class)->update($ref, fn (ItemState $state) => $state->withField('Book now', new FieldState(pending: [StateFixtures::pending('Book now', 'Jetzt buchen')])));
    }
    prunerState(new TargetRef(ContentType::Nav, 'footer', 'el'));

    $report = pruned();

    expect($report->orphaned(PruneReport::DELETED))->toBe(['nav::footer::el'])
        ->and(array_map('strval', iterator_to_array(app(StateRepository::class)->references(), false)))->toEqualCanonicalizing(['lang::_json::de', 'forms::de']);
});

it('records no Bard copy of a source without text, which it would not report', function () {
    $this->makeCollection('pages', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true], 'content' => ['type' => 'bard', 'localizable' => true]]);
    $home = $this->makeEntry('pages', 'en', ['title' => 'Home', 'content' => [['type' => 'horizontalRule']]], 'home');
    // Saved without events, as before Tolk was installed, so nothing recorded the copy.
    $localization = $home->makeLocalization('el')->merge(['title' => 'Αρχική', 'content' => [['type' => 'horizontalRule']]]);
    $localization->saveQuietly();
    StateFixtures::store($localization, ['title' => StateFixtures::machine('Home', 'Αρχική')]);

    $report = pruned();

    // It records the entry's collection and site, which the stored state lacks, and nothing else.
    expect($report->resolved)->toBe(0)
        ->and($report->located)->toBe(1)
        ->and(pruned()->isEmpty())->toBeTrue()
        ->and(StateFixtures::stored($localization)->field('content')->isEmpty())->toBeTrue();
});

it('lists the state files that do not parse and keeps them, and prunes the rest', function () {
    $localization = $this->localize($this->hello, 'el', ['title' => 'Γεια']);
    StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια')]);
    $path = StateFixtures::conflict($localization);
    prunerState(new TargetRef(ContentType::Nav, 'footer', 'el'));

    $dryRun = pruned(dryRun: true);
    $report = pruned();

    expect($dryRun->unreadable)->toBe([$path])
        ->and($report->unreadable)->toBe([$path])
        ->and($report->orphaned(PruneReport::DELETED))->toBe(['nav::footer::el'])
        ->and($path)->toBeFile()
        ->and(pruned()->isEmpty())->toBeFalse();
});

it('keeps the state of the form texts of another language, and deletes that of the source language', function () {
    $this->app->useLangPath($this->temporaryPath('lang'));
    prunerState(new TargetRef(ContentType::Forms, 'de'));
    prunerState(new TargetRef(ContentType::Forms, 'fr'));
    prunerState(new TargetRef(ContentType::Forms, 'en'));

    $report = pruned();

    expect($report->orphaned(PruneReport::NOT_A_LOCALIZATION))->toBe(['forms::en'])
        ->and($report->orphaned(PruneReport::DELETED))->toBe([])
        ->and(array_map('strval', iterator_to_array(app(StateRepository::class)->references(), false)))->toEqualCanonicalizing(['forms::de', 'forms::fr']);
});

it('keeps the state of a navigation, translation files and form texts translated from another site than the default, and of one whose source site is left out now', function () {
    $this->app->useLangPath($this->temporaryPath('lang'));
    (new Filesystem)->ensureDirectoryExists(lang_path('de'));
    file_put_contents(lang_path('de.json'), '{"Jetzt buchen": "Jetzt buchen"}');
    file_put_contents(lang_path('en.json'), '{"Jetzt buchen": "Book now"}');
    file_put_contents(lang_path('de/messages.php'), "<?php return ['book' => 'Jetzt buchen'];");
    $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book now']], 'el' => [['id' => 'book', 'title' => 'Κράτηση']]]);
    $references = [
        // Into the default site's language or tree: `tolk:lang en --from=de`, `tolk:forms en --from=de`, `--nav=main --site=en --from=el`.
        'lang::_json::en' => 'de',
        'forms::en' => 'de',
        'nav::main::en' => 'el',
        // A group the default site's language lacks: `tolk:lang el --from=de`.
        'lang::messages::el' => 'de',
        // A source site taken out of the configuration.
        'lang::_json::el' => 'fr',
        'forms::el' => 'fr',
        'nav::main::el' => 'fr',
    ];

    foreach ($references as $reference => $sourceSite) {
        prunerState(TargetRef::parse($reference), $sourceSite);
    }

    $report = pruned();

    expect($report->orphans)->toBe([])
        ->and(array_map('strval', iterator_to_array(app(StateRepository::class)->references(), false)))->toEqualCanonicalizing(array_keys($references));
});

it('stops a stalled run that is older than the retention instead of deleting it, and counts it once with a dry run', function () {
    $stalled = RunFixtures::started();
    $old = RunFixtures::pending();
    $this->travel(31)->days();

    $dryRun = pruned(dryRun: true);
    $report = pruned();

    expect($dryRun->stoppedRuns)->toBe([$stalled->id])
        ->and($dryRun->deletedRuns)->toBe(1)
        ->and($report->stoppedRuns)->toBe([$stalled->id])
        ->and($report->deletedRuns)->toBe(1)
        ->and(RunFixtures::stored($stalled)->status)->toBe(RunStatus::Aborted)
        ->and(app(RunRepository::class)->find($old->id))->toBeNull();
});

it('completes a running run that has nothing left, and leaves a run that made progress alone', function () {
    $this->fakeTranslations();
    $finished = RunFixtures::started(['entryIds' => [$this->hello->id()]]);
    RunFixtures::finish($finished);
    $finished = RunFixtures::stored($finished);
    $finished->status = RunStatus::Running;
    $finished->finishedAt = $finished->finalizedAt = null;
    app(RunRepository::class)->save($finished);

    $this->travel(config('tolk.runs.claim_ttl') + 1)->seconds();
    $busy = RunFixtures::started();

    $report = pruned();

    expect($report->stoppedRuns)->toBe([$finished->id])
        ->and(RunFixtures::stored($finished)->status)->toBe(RunStatus::Completed)
        ->and(RunFixtures::stored($finished)->error)->toBeNull()
        ->and(RunFixtures::stored($busy)->status)->toBe(RunStatus::Running);
});
