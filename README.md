# Tolk for Statamic

Tolk translates multi-site Statamic content from an origin site into its localizations with large language models from Anthropic, OpenAI or Google Gemini. It works in the entry editor, where translations are filled into the open form for the editor to review and save, on single fields, in bulk from listings and from the command line. It tracks which translations are outdated or edited by hand, so it updates only what has changed.

## Requirements

- PHP 8.3 or later
- Statamic 6.30 or later, Pro edition (multi-site needs it)
- Laravel 12.62 or later, or Laravel 13.15 or later
- Laravel AI SDK (`laravel/ai`) 1.0.1 or later, installed with the addon
- An API key for at least one of Anthropic, OpenAI or Google Gemini

## Development

See [`CLAUDE.md`](CLAUDE.md) for the commands, architecture, conventions and the sandbox site used during development.
