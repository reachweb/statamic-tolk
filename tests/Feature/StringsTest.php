<?php

use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\ProviderError;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\ProviderErrorKind;
use Reach\StatamicTolk\Enums\Tier;
use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\Exceptions\FieldFailed;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Exceptions\NotALocalization;
use Reach\StatamicTolk\Exceptions\NotSupportedYet;
use Reach\StatamicTolk\Exceptions\ProfileFailed;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Exceptions\ProviderPaused;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\ProviderTimeout;
use Reach\StatamicTolk\Exceptions\ProviderUnavailable;
use Reach\StatamicTolk\Exceptions\RequestRejected;
use Reach\StatamicTolk\Exceptions\RootMissing;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Exceptions\WriterBusy;
use Reach\StatamicTolk\Support\Audit;
use Reach\StatamicTolk\Tests\Support\TemplateStrings;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Findings the heuristic reports that are not user-facing text, as "{path}:{finding}". Add one only with the reason.
 */
const STRINGS_ALLOWED = [];

/**
 * @param  Closure(string, SplFileInfo): list<string>  $scan
 * @return list<string>
 */
function scanCp(Closure $scan): array
{
    $findings = [];

    foreach (TemplateStrings::cpScripts() as $file) {
        foreach ($scan($file->getContents(), $file) as $finding) {
            $findings[] = "{$file->getRelativePathname()}:{$finding}";
        }
    }

    return array_values(array_diff($findings, STRINGS_ALLOWED));
}

describe('the heuristic', function () {
    it('finds text outside __() in a template, and buttons and badges a screen reader cannot name', function () {
        $component = <<<'VUE'
            <script setup>
            const label = 'Not in the template';
            </script>

            <template>
                <div v-if="count > 0" class="flex gap-2" data-tolk-example>
                    Saved
                    <Button icon="sync" />
                    <Button icon="sync" :aria-label="__('Count again')" />
                    <Button :text="__('Translate')" />
                    <Button>{{ __('Review') }}</Button>
                    <Badge color="red" />
                    <Badge v-bind="badge" />
                    <Text :label="'Two words'" title="A title" variant="subtle" />
                    {{ done ? 'Done' : __('Running :what', { what: 'the run' }) }}
                    <Description :class="['mt-1 text-sm', classes]" :data-tolk-state="phase === 'idle' ? 'quiet' : null" />
                    <!-- A comment Is not text -->
                    <span>— · 42</span>
                </div>
            </template>
            VUE;

        expect(TemplateStrings::findings($component))->toBe([
            '7: text outside __(): Saved',
            '8: Button without text or aria-label',
            '12: Badge without text',
            "14: string literal outside __() in :label: 'Two words'",
            '14: static title outside __(): A title',
            "15: string literal outside __(): 'Done'",
            "15: string literal outside __(): 'the run'",
        ]);
    });

    it('finds sentences outside __() in scripts, leaving out comments, thrown errors and class lists', function () {
        $script = <<<'JS'
            // A comment with 'Quoted words in it'
            const classes = 'rounded-sm bg-red-100 dark:bg-red-900/40';
            Statamic.$toast.error('Something went wrong');
            const fine = __('Saved :count fields', { count: 2 });
            throw new Error(`Unknown phase [${phase}].`);
            const summary = `${count} items were saved`;
            const key = 'review';
            JS;

        expect(TemplateStrings::scriptFindings($script, false))->toBe([
            "3: 'Something went wrong'",
            '6: `${count} items were saved`',
        ])->and(TemplateStrings::scriptFindings("<template><p /></template>\n<script setup>\nconst text = 'Not translated here';\n</script>", true))
            ->toBe(["3: 'Not translated here'"]);
    });
});

it('finds no text outside __() and no unnamed button or badge in the templates of resources/js', function () {
    expect(TemplateStrings::cpScripts())->not->toHaveCount(0)
        ->and(scanCp(fn (string $contents, SplFileInfo $file) => $file->getExtension() === 'vue' ? TemplateStrings::findings($contents) : []))->toBe([]);
});

it('finds no sentence outside __() in the scripts of resources/js', function () {
    expect(scanCp(fn (string $contents, SplFileInfo $file) => TemplateStrings::scriptFindings($contents, $file->getExtension() === 'vue')))->toBe([]);
});

describe('PHP', function () {
    // A locale without translations, whose missing keys come back marked: text that went through __() starts with «.
    beforeEach(function () {
        app()->setLocale('xx');
        app('translator')->handleMissingKeysUsing(fn (string $key) => "«{$key}»");
    });

    afterEach(function () {
        app('translator')->handleMissingKeysUsing(null);
    });

    it('translates every title, explanation and item of the audit', function () {
        $this->useFileCache();
        config([
            'tolk.models.openai' => [['id' => 'gpt-6.1-sol', 'tier' => 'fast']],
            'tolk.settings.fallback_model' => 'gemini:gemini-3.8-flash',
            'statamic.git.enabled' => true,
            'statamic.static_caching.strategy' => 'half',
        ]);
        $this->makeCollection('pages', ['en', 'el'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'intro' => ['type' => 'textarea'],
        ]);
        $this->makeTaxonomy('topics', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeGlobalSet('footer', ['en' => null, 'el' => 'en'], null, []);
        $this->makeGlobalSet('header', ['en' => null, 'el' => null], ['tagline' => ['type' => 'text', 'localizable' => true]], []);

        $texts = collect(app(Audit::class)->run())
            ->flatMap(fn (array $check) => [$check['title'], $check['detail'], ...array_column($check['items'], 'text')]);

        expect($texts->count())->toBeGreaterThan(25)
            ->and($texts->reject(fn (string $text) => str_starts_with($text, '«'))->values()->all())->toBe([]);
    });

    it('translates the messages of the exceptions a user can meet', function () {
        $model = ModelRef::fromString('openai:gpt-6-luna');
        $error = new ProviderError(ProviderErrorKind::Rejected, 'Bad request', 400);

        $messages = collect([
            ApplyFailed::vetoed('entry::1'),
            FieldFailed::for('body', ['s1' => 'invalid']),
            InvalidModelRef::for('gpt'),
            InvalidTarget::sameSite('1', 'el'),
            InvalidTarget::siteNotInCollection('pages', 'el'),
            InvalidTarget::defaultSite('topics::news', 'en'),
            InvalidTarget::siteNotInTaxonomy('topics', 'el'),
            InvalidTarget::navSource('main', 'en'),
            MissingDefaultModel::for('openai', Tier::Quality),
            NoProviderConfigured::make(),
            NotALocalization::for('entry::1'),
            NotSupportedYet::contentType(ContentType::Entry),
            ProfileFailed::from($model, new ProviderError(ProviderErrorKind::Unavailable, 'Down', 503)),
            ProfileFailed::cutOff($model),
            ProfileFailed::noProfile($model),
            ProviderNotConfigured::for('openai'),
            ProviderPaused::for('openai', 30),
            ProviderRejected::from($model, $error),
            ProviderRejected::from($model, new ProviderError(ProviderErrorKind::Rejected, 'No such model', 404)),
            ProviderTimeout::after('openai', 120, 30),
            ProviderUnavailable::connection('openai', 30),
            RequestRejected::from('openai', $error),
            RootMissing::for('1', 'Home', 'Pages', 'Greek'),
            SourceChanged::field('title'),
            TargetNotFound::entry('1'),
            TargetNotFound::term('topics::news', 'el'),
            TargetNotFound::globalSet('footer', 'el'),
            TargetNotFound::nav('main', 'el'),
            UnknownSite::for('fr'),
            WriterBusy::waited(30),
        ])->map->getMessage();

        expect($messages->reject(fn (string $message) => str_starts_with($message, '«'))->values()->all())->toBe([]);
    });
});
