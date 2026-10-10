<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text'],
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);
    $this->hello = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.'], 'hello');

    $this->makeCollection('news', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->news = $this->makeEntry('news', 'en', ['title' => 'Big news'], 'big-news');

    $this->makeTaxonomy('topics', ['en', 'el', 'de'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food']]);

    $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], ['tagline' => ['type' => 'text', 'localizable' => true]], ['en' => ['tagline' => 'By the sea']]);

    $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book your stay']]]);
});

/**
 * Runs the command without prompts, in this process, and returns its exit code and output.
 *
 * @return array{0: int, 1: string}
 */
function siteCommand(array $options = []): array
{
    $exitCode = Artisan::call('tolk:site', ['site' => 'de', '--yes' => true, '--concurrency' => 1, ...$options]);

    return [$exitCode, Artisan::output()];
}

function titleIsLocalizable(): bool
{
    return Collection::find('articles')->entryBlueprint()->field('title')->isLocalizable();
}

/**
 * The lines of the plan that tolk:translate prints before its question: the targets, the items per content type and
 * site, and the estimate.
 *
 * @return list<string>
 */
function siteCommandPlan(string $output): array
{
    return array_values(preg_grep('/^(Targets: |\s+(Entries|Terms|Global sets|Navigation) → |\s+(Segments|Estimated tokens|Estimated cost) )/', explode("\n", $output)));
}

it('adds the site, fixes the blueprint and translates entries, terms, the global set and the navigation on the interactive path', function () {
    $this->fakeTranslations();

    $this->artisan('tolk:site', ['site' => 'de', '--concurrency' => 1])
        ->expectsOutputToContain('Translating the whole website into German (de).')
        ->expectsOutputToContain('These collections and taxonomies are not enabled for German (de)')
        ->expectsOutputToContain('Collection News (news)')
        ->expectsConfirmation('Enable German (de) for them?', 'yes')
        ->expectsOutputToContain('Global set Site Settings (site_settings): not enabled for the site; it would be translated from English (en)')
        ->expectsConfirmation('Enable German (de) for them, with these origins?', 'yes')
        ->expectsOutputToContain('Collection Articles › Article: Title (title)')
        ->expectsConfirmation('Make Title localizable in Collection Articles › Article?', 'yes')
        ->expectsOutputToContain('Collection Articles › Article: 1 field is now localizable.')
        ->expectsConfirmation('Translate and save them?', 'yes')
        ->expectsOutputToContain('Not translated by this command:')
        ->expectsOutputToContain('php please tolk:lang de')
        ->assertExitCode(0);

    $hello = $this->hello->fresh()->in('de');
    $news = $this->news->fresh()->in('de');

    expect(Collection::find('news')->sites()->all())->toBe(['en', 'el', 'de'])
        ->and(GlobalSet::find('site_settings')->origins()->all())->toBe(['en' => null, 'el' => 'en', 'de' => 'en'])
        ->and(titleIsLocalizable())->toBeTrue()
        ->and($hello->get('title'))->toBe('[T] Hello')
        ->and($hello->get('intro'))->toBe('[T] A short intro.')
        ->and($hello->slug())->toBe('hello')
        ->and($news->get('title'))->toBe('[T] Big news')
        ->and($news->slug())->toBe('big-news')
        ->and(Term::find('topics::food')->in('de')->get('title'))->toBe('[T] Food')
        ->and(GlobalSet::findByHandle('site_settings')->in('de')->get('tagline'))->toBe('[T] By the sea')
        ->and(Nav::findByHandle('main')->in('de')->tree())->toBe([['id' => 'book', 'title' => '[T] Book your stay']])
        ->and($this->hello->fresh()->existsIn('el'))->toBeFalse();
});

it('changes no configuration and no content in a dry run', function () {
    $this->fakeTranslations();
    $blueprint = Collection::find('articles')->entryBlueprint()->contents();

    $exitCode = Artisan::call('tolk:site', ['site' => 'de', '--dry-run' => true, '--concurrency' => 1]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Dry run: nothing is changed. Without --dry-run, this command offers to enable German (de) for them.')
        ->and($output)->toContain('Dry run: nothing is changed. Without --dry-run, this command offers to enable German (de) for them, with these origins.')
        ->and($output)->toContain('Collection Articles › Article: Title (title)')
        ->and($output)->toContain('Dry run: they are left as they are.')
        ->and($output)->toContain('Dry run: ')
        ->and($output)->toContain('Nothing was sent or saved.')
        ->and($output)->toContain('Not translated by this command:')
        ->and($output)->toContain("The app's translation files in lang/: php please tolk:lang de")
        ->and($output)->toContain('The texts of forms: php please tolk:forms de')
        ->and($output)->toContain('Fields of third-party fieldtypes, such as those of SEO addons, until a Tolk handler is registered for their fieldtype')
        ->and($output)->toContain('The alt text of assets, which a later version of Tolk translates')
        ->and(Collection::find('news')->sites()->all())->toBe(['en', 'el'])
        ->and(GlobalSet::find('site_settings')->sites()->all())->toBe(['en', 'el'])
        ->and(Collection::find('articles')->entryBlueprint()->contents())->toBe($blueprint)
        ->and($this->hello->fresh()->existsIn('de'))->toBeFalse()
        ->and($this->news->fresh()->existsIn('de'))->toBeFalse()
        ->and(Term::find('topics::food')->in('de')->get('title'))->toBeNull()
        ->and(Nav::findByHandle('main')->in('de'))->toBeNull()
        ->and(app(RunRepository::class)->recent(10))->toBe([]);
    $this->assertNoModelCalls();
});

it('lists the blueprints of the collections a dry run would enable for the site, and plans their entries', function () {
    $this->makeCollection('notes', ['en'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'body' => ['type' => 'textarea', 'display' => 'Body'],
    ]);
    $this->makeEntry('notes', 'en', ['title' => 'First note', 'body' => 'Shared by every site.'], 'first-note');

    [, $output] = siteCommand(['--dry-run' => true, '--yes' => false]);

    expect($output)->toContain('Collection Notes (notes)')
        ->and($output)->toContain('Collection Notes › Note: Body (body)')
        ->and($output)->toContain('First note → German: title (1 segment')
        ->and($output)->not->toContain('does not have');
});

it('plans and estimates in a dry run what the run translates once steps 2 and 3 enable the site', function () {
    $this->fakeTranslations();

    [, $dry] = siteCommand(['--dry-run' => true]);
    [$exitCode, $real] = siteCommand();

    expect($exitCode)->toBe(0)
        ->and($real)->toContain('Big news → German: done, created')
        ->and($real)->toContain('Site Settings → German: done')
        ->and($dry)->toContain('Big news → German: title (1 segment')
        ->and($dry)->toContain('Site Settings → German: tagline (1 segment')
        ->and($dry)->not->toContain('does not have')
        ->and(siteCommandPlan($dry))->toHaveCount(8)
        ->and(siteCommandPlan($dry))->toBe(siteCommandPlan($real));
});

it('translates when run with php please, whose commands resolve the commands they call by name', function () {
    $this->fakeTranslations();
    $command = Artisan::all()['tolk:site'];
    $command->setRunningInPlease();
    $output = new BufferedOutput;

    $exitCode = $command->run(new ArrayInput(['site' => 'de', '--yes' => true, '--concurrency' => 1]), $output);

    expect($exitCode)->toBe(0)
        ->and($output->fetch())->toContain('Big news → German: done, created');
});

it('plans in a dry run the pages of a structured collection that step 2 would enable, below their home page', function () {
    $this->fakeTranslations();
    $this->makeCollection('pages', ['en'], ['title' => ['type' => 'text', 'localizable' => true]], structured: true)
        ->structureContents(['root' => true])
        ->save();
    $home = $this->makeEntry('pages', 'en', ['title' => 'Home'], 'home');
    $rooms = $this->makeEntry('pages', 'en', ['title' => 'Rooms'], 'rooms');
    Collection::find('pages')->structure()->in('en')->tree([['entry' => $home->id()], ['entry' => $rooms->id()]])->save();

    [, $dry] = siteCommand(['--dry-run' => true]);
    [$exitCode, $real] = siteCommand();

    expect($exitCode)->toBe(0)
        ->and($real)->toContain('Rooms → German: done, created')
        ->and($dry)->toContain('Home → German: title (1 segment')
        ->and($dry)->toContain('Rooms → German: title (1 segment')
        ->and(siteCommandPlan($dry))->toBe(siteCommandPlan($real))
        ->and($this->treeOfSlugs('de'))->toBe(['home' => [], 'rooms' => []]);
});

it('enables the site and translates with --yes, but leaves blueprints alone', function () {
    $this->fakeTranslations();

    [$exitCode, $output] = siteCommand();
    $hello = $this->hello->fresh()->in('de');

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('German (de) is now enabled for them.')
        ->and($output)->toContain('German (de) is now enabled for them, with these origins.')
        ->and($output)->toContain('With --yes: they are left as they are. php please tolk:doctor --fix-localizable makes them localizable, blueprint by blueprint.')
        ->and(titleIsLocalizable())->toBeFalse()
        ->and($hello->get('intro'))->toBe('[T] A short intro.')
        ->and($hello->has('title'))->toBeFalse()
        ->and($this->news->fresh()->in('de')->get('title'))->toBe('[T] Big news')
        ->and(GlobalSet::findByHandle('site_settings')->in('de')->get('tagline'))->toBe('[T] By the sea')
        ->and(app(RunRepository::class)->recent(10)[0]->options['mode'])->toBe('missing');
});

it('leaves collections, taxonomies and global sets alone when the question is declined, and does not ask about their blueprints', function () {
    $this->fakeTranslations();
    $this->makeCollection('events', ['en', 'el'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'summary' => ['type' => 'textarea', 'display' => 'Summary'],
    ]);

    $this->artisan('tolk:site', ['site' => 'de', '--concurrency' => 1])
        ->expectsConfirmation('Enable German (de) for them?', 'no')
        ->expectsOutputToContain('They are left as they are, so their content is not translated.')
        ->expectsConfirmation('Enable German (de) for them, with these origins?', 'no')
        ->expectsOutputToContain('They are left as they are, so they are not translated.')
        ->expectsConfirmation('Make Title localizable in Collection Articles › Article?', 'no')
        ->doesntExpectOutputToContain('Summary')
        ->expectsConfirmation('Translate and save them?', 'yes')
        ->assertExitCode(0);

    expect(Collection::find('news')->sites()->all())->toBe(['en', 'el'])
        ->and(GlobalSet::find('site_settings')->sites()->all())->toBe(['en', 'el'])
        ->and(titleIsLocalizable())->toBeFalse()
        ->and($this->news->fresh()->existsIn('de'))->toBeFalse()
        ->and($this->hello->fresh()->in('de')->get('intro'))->toBe('[T] A short intro.');
});

it('enables the site for a taxonomy whose terms are then translated into it', function () {
    $this->fakeTranslations();
    $this->makeTaxonomy('colours', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->makeTerm('colours', 'red', ['en' => ['title' => 'Red']]);

    [$exitCode, $output] = siteCommand();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Taxonomy Colours (colours)')
        ->and(Taxonomy::find('colours')->sites()->all())->toBe(['en', 'el', 'de'])
        ->and(Term::find('colours::red')->in('de')->get('title'))->toBe('[T] Red')
        ->and(Term::query()->where('taxonomy', 'colours')->where('site', 'de')->get()->map->get('title')->all())->toBe(['[T] Red']);
});

it('enables the site for a structured collection, whose pages are then translated into its tree', function () {
    $this->fakeTranslations();
    $this->makeCollection('pages', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]], structured: true);
    $about = $this->makeEntry('pages', 'en', ['title' => 'About'], 'about');
    $team = $this->makeEntry('pages', 'en', ['title' => 'Team'], 'team');
    Collection::find('pages')->structure()->in('en')->tree([['entry' => $about->id(), 'children' => [['entry' => $team->id()]]]])->save();
    // Asked before the site is enabled, as an earlier command in the same process may have.
    expect(Collection::find('pages')->structure()->in('de'))->toBeNull();

    [$exitCode] = siteCommand();

    expect($exitCode)->toBe(0)
        ->and(Collection::find('pages')->sites()->all())->toBe(['en', 'el', 'de'])
        ->and($about->fresh()->in('de')->get('title'))->toBe('[T] About')
        ->and($this->treeOfSlugs('de'))->toBe(['about' => ['team' => []]]);
});

it('names the origin of a global set that has the site without one, and translates it from that origin', function () {
    $this->fakeTranslations();
    GlobalSet::find('site_settings')->sites(['en' => null, 'el' => 'en', 'de' => null])->save();

    [$exitCode, $output] = siteCommand(['--from' => 'el']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Global set Site Settings (site_settings): it names no origin for the site; it would be translated from Greek (el)')
        ->and(GlobalSet::find('site_settings')->origins()->all())->toBe(['en' => null, 'el' => 'en', 'de' => 'el']);
});

it('names a global set that has no other site to translate the site from, and leaves it alone', function () {
    $this->fakeTranslations();
    GlobalSet::find('site_settings')->sites(['en' => null, 'el' => 'en', 'de' => 'en'])->save();
    $this->makeGlobalSet('german', ['de' => null], ['motto' => ['type' => 'text', 'localizable' => true]], ['de' => ['motto' => 'Gut']]);

    [$exitCode, $output] = siteCommand();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Global set German (german): it has no other site to translate from')
        ->and($output)->not->toContain('German (de) is now enabled for them, with these origins.')
        ->and(GlobalSet::find('german')->origins()->all())->toBe(['de' => null]);
});

it('warns about a collection whose route is set per site but not for the site', function () {
    Collection::find('articles')->routes(['en' => '/articles/{slug}', 'el' => '/arthra/{slug}'])->save();

    [, $output] = siteCommand(['--dry-run' => true]);

    expect($output)->toContain('Collection Articles has a route for each site but none for German (de), so its entries there have no URL.')
        ->and($output)->not->toContain('Collection News has a route');
});

it('names the fields of an imported fieldset, which it does not change', function () {
    $this->fakeTranslations();
    Fieldset::make('seo')->setContents(['fields' => [['handle' => 'description', 'field' => ['type' => 'textarea']]]])->save();
    $this->makeCollection('pages', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        ['import' => 'seo', 'prefix' => 'seo_'],
    ]);

    $this->artisan('tolk:site', ['site' => 'de', '--concurrency' => 1])
        ->expectsConfirmation('Enable German (de) for them?', 'no')
        ->expectsConfirmation('Enable German (de) for them, with these origins?', 'no')
        ->expectsConfirmation('Make Title localizable in Collection Articles › Article?', 'no')
        ->expectsOutputToContain('Collection Pages › Page: Seo Description comes from the fieldset seo, which this command does not change.')
        ->expectsConfirmation('Translate and save them?', 'no')
        ->assertExitCode(0);
});

describe('nested fields that look like data', function () {
    beforeEach(function () {
        $this->fakeTranslations();
        $this->makeCollection('pages', ['en', 'el', 'de'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'body' => ['type' => 'replicator', 'localizable' => true, 'sets' => ['main' => ['sets' => ['text' => ['fields' => [
                ['handle' => 'anchor', 'field' => ['type' => 'text']],
            ]]]]]],
        ]);
        $this->makeEntry('pages', 'en', ['title' => 'About'], 'about');
    });

    it('lists them and translates nothing when the question is declined', function () {
        $this->artisan('tolk:site', ['site' => 'de', '--concurrency' => 1])
            ->expectsConfirmation('Enable German (de) for them?', 'no')
            ->expectsConfirmation('Enable German (de) for them, with these origins?', 'no')
            ->expectsConfirmation('Make Title localizable in Collection Articles › Article?', 'no')
            ->expectsOutputToContain('Collection Pages › Page: Body › Text › Anchor (body.text.anchor)')
            ->expectsConfirmation('Continue, and translate them as text?', 'no')
            ->expectsOutputToContain('Nothing was translated.')
            ->doesntExpectOutputToContain('Not translated by this command:')
            ->assertExitCode(0);

        expect($this->hello->fresh()->existsIn('de'))->toBeFalse()
            ->and(app(RunRepository::class)->recent(10))->toBe([]);
        $this->assertNoModelCalls();
    });

    it('continues with --yes', function () {
        [$exitCode, $output] = siteCommand();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Collection Pages › Page: Body › Text › Anchor (body.text.anchor)')
            ->and(Entry::query()->where('collection', 'pages')->where('site', 'de')->count())->toBe(1);
    });
});

it('passes the translate options through to tolk:translate', function () {
    $this->fakeTranslations();
    $this->fakeReview();
    $this->localize($this->news, 'el', ['title' => 'Μεγάλα νέα']);

    [$exitCode] = siteCommand(['--from' => 'el', '--publish' => true, '--translator' => 'openai:gpt-6-luna', '--reviewer' => 'gemini:gemini-3.8-flash']);
    $run = app(RunRepository::class)->recent(1)[0];

    expect($exitCode)->toBe(0)
        ->and($run->options)->toMatchArray([
            'mode' => 'missing',
            'review' => true,
            'translator' => 'openai:gpt-6-luna',
            'reviewer' => 'gemini:gemini-3.8-flash',
            'new_status' => 'mirror',
        ])
        ->and($this->news->fresh()->in('de')->get('title'))->toBe('[T] Μεγάλα νέα');
});

it('returns the exit code of tolk:translate', function () {
    $this->withSettings(['review_enabled' => true]);
    Sleep::fake(syncWithCarbon: true);
    ScriptedEngine::bind(fn (EngineCall $call) => in_array('Big news', array_column($call->segments, 'text'), true)
        ? throw new RuntimeException('The model broke down.')
        : ScriptedEngine::translated($call));

    [$exitCode, $output] = siteCommand(['--no-review' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Big news → German: failed, The model broke down.')
        ->and($output)->toContain('Not translated by this command:')
        ->and(app(RunRepository::class)->recent(1)[0]->options['review'])->toBeFalse();
});

describe('validation', function () {
    it('exits 1 before changing anything', function (array $options, string $message) {
        $this->fakeTranslations();

        $exitCode = Artisan::call('tolk:site', ['--yes' => true, ...$options]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain($message)
            ->and(Collection::find('news')->sites()->all())->toBe(['en', 'el'])
            ->and(GlobalSet::find('site_settings')->sites()->all())->toBe(['en', 'el'])
            ->and(app(RunRepository::class)->recent(10))->toBe([]);
        $this->assertNoModelCalls();
    })->with([
        'unknown site' => [['site' => 'fr'], 'There is no site [fr]. Add it under Sites in the Control Panel, then run this command again.'],
        'the default site' => [['site' => 'en'], 'English (en) is the site the others are translated from. Name the site to translate into.'],
        'the --from site' => [['site' => 'el', '--from' => 'el'], 'Greek (el) is the site the others are translated from.'],
        'unknown source site' => [['site' => 'de', '--from' => 'fr'], 'Unknown site [fr].'],
        '--no-review with --review' => [['site' => 'de', '--review' => true, '--no-review' => true], '--no-review cannot be combined with --review, --reviewer or --mode=review.'],
        'invalid concurrency' => [['site' => 'de', '--concurrency' => '0'], 'Invalid concurrency [0]. Use a whole number of at least 1.'],
        '--queue on a connection without a worker' => [['site' => 'de', '--queue' => true], 'The queue connection [sync] has no worker'],
        'invalid translator' => [['site' => 'de', '--translator' => 'mistral:large'], 'Invalid model reference [mistral:large].'],
    ]);

    it('checks the reviewer\'s provider before anything runs', function () {
        $this->withProviderKeys(['ai.providers.anthropic.key' => 'test-key']);

        $exitCode = Artisan::call('tolk:site', ['site' => 'de', '--yes' => true, '--reviewer' => 'openai:gpt-6-luna']);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('The AI provider [openai] has no API key.')
            ->and(Collection::find('news')->sites()->all())->toBe(['en', 'el']);
    });
});
