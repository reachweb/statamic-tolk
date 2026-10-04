<?php

use Reach\StatamicTolk\Ai\Prompts\PromptBuilder;
use Reach\StatamicTolk\Ai\Prompts\PromptContext;
use Reach\StatamicTolk\Enums\Formality;
use Reach\StatamicTolk\Support\Language;

function englishLanguage(): Language
{
    return new Language('en', 'English', 'en_US', 'en', 'United States', 'ltr');
}

function greekLanguage(): Language
{
    return new Language('el', 'Greek', 'el_GR', 'el', 'Greece', 'ltr');
}

function glossaryRow(string $term, string $translation = '', bool $doNotTranslate = false, string $note = ''): array
{
    return ['term' => $term, 'site' => null, 'translation' => $translation, 'do_not_translate' => $doNotTranslate, 'note' => $note];
}

function fullPromptContext(array $glossaryRows): PromptContext
{
    return new PromptContext(
        siteProfile: "Kyma Bay is a small family-run hotel in Chania, Crete.\nThe site speaks to couples and families planning a summer holiday.",
        instructions: 'Keep the tone warm and personal. Prefer short sentences.',
        formality: Formality::Formal,
        languageInstructions: 'Use the monotonic system. Write prices as 120 €.',
        glossaryRows: $glossaryRows,
    );
}

function fullGlossaryRows(): array
{
    return [
        glossaryRow('suite', 'σουίτα', note: 'only for the two sea-view rooms'),
        glossaryRow('Kyma Bay', doNotTranslate: true),
        glossaryRow('check-in', 'άφιξη'),
        glossaryRow('Booking', 'Κράτηση'),
    ];
}

it('builds the full translation system prompt', function () {
    $prompt = (new PromptBuilder)->system(englishLanguage(), greekLanguage(), fullPromptContext(fullGlossaryRows()));

    expect($prompt)->toMatchSnapshot();
});

it('leaves every empty section out of the minimal prompt', function () {
    $builder = new PromptBuilder;
    $minimal = $builder->system(englishLanguage(), greekLanguage(), new PromptContext);

    $blank = $builder->system(englishLanguage(), greekLanguage(), new PromptContext(
        siteProfile: "  \n ",
        instructions: "\r\n",
        formality: Formality::Default,
        languageInstructions: ' ',
        glossaryRows: [glossaryRow(''), glossaryRow('orphan')],
    ));

    expect($minimal)->toMatchSnapshot()
        ->and($minimal)->not->toContain('<site>', '<instructions>', '<language_instructions>', '<glossary>')
        ->and($blank)->toBe($minimal);
});

it('builds the full review system prompt', function () {
    $prompt = (new PromptBuilder)->reviewSystem(englishLanguage(), greekLanguage(), fullPromptContext(fullGlossaryRows()));

    expect($prompt)->toMatchSnapshot();
});

it('gives the review prompt the context sections of the translation prompt', function (PromptContext $context) {
    $builder = new PromptBuilder;
    $sections = fn (string $prompt) => str($prompt)->after("\n\n")->after("\n\n")->before('<rules>')->toString();

    $translation = $builder->system(englishLanguage(), greekLanguage(), $context);
    $review = $builder->reviewSystem(englishLanguage(), greekLanguage(), $context);

    expect($review)->toStartWith('You are a senior translation reviewer.')
        ->and($review)->toContain('Review translations from English (United States), locale en_US into Greek (Greece), locale el_GR.')
        ->and($sections($review))->toBe($sections($translation))
        ->and($review)->toEndWith("5. When a segment has `max_length`, a correction must not exceed that many characters.\n</rules>");
})->with([
    'full' => fn () => fullPromptContext(fullGlossaryRows()),
    'minimal' => fn () => new PromptContext,
]);

it('writes the formality line alone when there are no language instructions', function (Formality $formality, string $line) {
    $prompt = (new PromptBuilder)->system(englishLanguage(), greekLanguage(), new PromptContext(formality: $formality));

    expect($prompt)->toContain("<language_instructions>\n{$line}\n</language_instructions>");
})->with([
    'formal' => [Formality::Formal, 'Address the reader formally.'],
    'informal' => [Formality::Informal, 'Address the reader informally.'],
]);

it('gives the same prompt whatever order the glossary rows come in', function () {
    $builder = new PromptBuilder;
    $rows = [
        ...fullGlossaryRows(),
        glossaryRow('booking', 'κράτηση'),
        glossaryRow('Suite', 'Σουίτα', note: 'in headings'),
    ];

    $prompts = collect([$rows, array_reverse($rows), [$rows[4], $rows[1], $rows[5], $rows[3], $rows[0], $rows[2]]])
        ->map(fn (array $order) => $builder->system(englishLanguage(), greekLanguage(), fullPromptContext($order)))
        ->unique();

    expect($prompts)->toHaveCount(1)
        ->and($prompts->first())->toContain(implode("\n", [
            'Use these terms exactly.',
            '- "Booking" → "Κράτηση"',
            '- "booking" → "κράτηση"',
            '- "check-in" → "άφιξη"',
            '- "Kyma Bay" → do not translate',
            '- "Suite" → "Σουίτα" (in headings)',
            '- "suite" → "σουίτα" (only for the two sea-view rooms)',
        ]));
});

it('keeps each glossary row on one line', function () {
    $prompt = (new PromptBuilder)->system(englishLanguage(), greekLanguage(), new PromptContext(glossaryRows: [
        glossaryRow('12" pizza', "πίτσα\n12\"", note: "large\n  size"),
    ]));

    expect($prompt)->toContain("<glossary>\nUse these terms exactly.\n- \"12\\\" pizza\" → \"πίτσα\\n12\\\"\" (large size)\n</glossary>");
});

it('encodes the user message without escaping Greek text or slashes', function () {
    $json = (new PromptBuilder)->user(
        ['type' => 'entry', 'title' => 'Καλώς ήρθατε', 'container' => 'Pages', 'url' => 'https://example.com/el/welcome', 'summary' => 'Ένα μικρό ξενοδοχείο.'],
        [['id' => 's1', 'field' => 'Title', 'kind' => 'plain', 'text' => 'Καλημέρα']],
    );

    expect($json)->toBe('{"document":{"type":"entry","title":"Καλώς ήρθατε","container":"Pages","url":"https://example.com/el/welcome","summary":"Ένα μικρό ξενοδοχείο."},"segments":[{"id":"s1","field":"Title","kind":"plain","text":"Καλημέρα"}]}')
        ->and($json)->not->toContain('\u', '\/');
});

it('writes segment keys in the order of the prompt contract', function () {
    $json = (new PromptBuilder)->user(['title' => 'Home'], [
        'b' => [
            'text' => 'Book now',
            'after' => 'Free cancellation.',
            'max_length' => 20,
            'hint' => 'Button label',
            'kind' => 'plain',
            'before' => 'Rooms from 90 €.',
            'field' => 'Hero › Button',
            'id' => 's2',
            'index' => 0,
            'label' => 'Hero › Button',
            'translation' => null,
        ],
        'a' => ['note' => 'Keep <1>.', 'kind' => 'inline', 'text' => '<1>Sea</1> view', 'id' => 's1', 'field' => 'Body', 'hint' => null, 'before' => ''],
        'c' => ['translation' => 'Θέα', 'source' => 'View', 'kind' => 'plain', 'field' => 'Title', 'id' => 's3'],
    ]);

    $segments = json_decode($json, true)['segments'];

    expect(array_map('array_keys', $segments))->toBe([
        ['id', 'field', 'kind', 'hint', 'max_length', 'before', 'after', 'text'],
        ['id', 'field', 'kind', 'text', 'note'],
        ['id', 'field', 'kind', 'source', 'translation'],
    ])
        ->and($segments[0]['max_length'])->toBe(20)
        ->and($segments[1]['text'])->toBe('<1>Sea</1> view');
});

it('builds the context from the settings of the target site', function () {
    $settings = $this->withSettings([
        'site_profile' => 'Kyma Bay is a small hotel in Chania.',
        'instructions' => 'Keep it warm.',
        'language_instructions' => [
            ['id' => 'l1', 'site' => ['de'], 'formality' => 'informal', 'instructions' => 'Use du.'],
            ['id' => 'l2', 'site' => ['el'], 'formality' => 'formal', 'instructions' => 'Use the monotonic system.'],
        ],
        'glossary' => [
            ['id' => 'g1', 'term' => 'suite', 'site' => ['el'], 'translation' => 'σουίτα'],
            ['id' => 'g2', 'term' => 'Kyma Bay', 'do_not_translate' => true],
            ['id' => 'g3', 'term' => 'suite', 'site' => ['de'], 'translation' => 'Suite'],
        ],
    ]);

    $context = PromptContext::fromSettings($settings, 'el');

    expect($context->siteProfile)->toBe('Kyma Bay is a small hotel in Chania.')
        ->and($context->instructions)->toBe('Keep it warm.')
        ->and($context->formality)->toBe(Formality::Formal)
        ->and($context->languageInstructions)->toBe('Use the monotonic system.')
        ->and($context->glossaryRows)->toBe($settings->glossary('el'))
        ->and(array_column($context->glossaryRows, 'term'))->toBe(['Kyma Bay', 'suite']);

    expect(PromptContext::fromSettings($settings, 'de')->formality)->toBe(Formality::Informal);
});

it('renders the glossary rows of the target site from settings', function () {
    $settings = $this->withSettings(['glossary' => [
        ['id' => 'g1', 'term' => 'suite', 'translation' => 'Suite', 'note' => 'all sites'],
        ['id' => 'g2', 'term' => ' Suite ', 'site' => ['el'], 'translation' => 'σουίτα', 'note' => "only the two\nsea-view rooms"],
        ['id' => 'g3', 'term' => 'Kyma Bay', 'do_not_translate' => '1'],
        ['id' => 'g4', 'term' => 'check-in', 'site' => ['de'], 'translation' => 'Anreise'],
        ['id' => 'g5', 'term' => 'Booking', 'site' => ['el'], 'translation' => 'Κράτηση', 'do_not_translate' => false],
        ['id' => 'g6', 'term' => 'breakfast', 'translation' => null, 'do_not_translate' => false],
        ['id' => 'g7', 'term' => null, 'translation' => 'πρωινό'],
    ]]);

    $section = fn (string $site) => str((new PromptBuilder)->system(englishLanguage(), greekLanguage(), PromptContext::fromSettings($settings, $site)))
        ->between("<glossary>\n", "\n</glossary>")
        ->toString();

    expect($section('el'))->toBe(implode("\n", [
        'Use these terms exactly.',
        '- "Booking" → "Κράτηση"',
        '- "Kyma Bay" → do not translate',
        '- "Suite" → "σουίτα" (only the two sea-view rooms)',
    ]))
        ->and($section('de'))->toBe(implode("\n", [
            'Use these terms exactly.',
            '- "check-in" → "Anreise"',
            '- "Kyma Bay" → do not translate',
            '- "suite" → "Suite" (all sites)',
        ]));
});
