<?php

namespace Reach\StatamicTolk\Ai\Prompts;

use Reach\StatamicTolk\Enums\Formality;
use Reach\StatamicTolk\Support\Settings;

final readonly class PromptContext
{
    /**
     * @param  list<array{term: string, translation: string, do_not_translate: bool, note: string}>  $glossaryRows
     */
    public function __construct(
        public string $siteProfile = '',
        public string $instructions = '',
        public Formality $formality = Formality::Default,
        public string $languageInstructions = '',
        public array $glossaryRows = [],
    ) {}

    public static function fromSettings(Settings $settings, string $site): self
    {
        $language = $settings->languageInstructions($site);

        return new self(
            siteProfile: $settings->siteProfile(),
            instructions: $settings->instructions(),
            formality: $language['formality'],
            languageInstructions: $language['instructions'],
            glossaryRows: $settings->glossary($site),
        );
    }
}
