<?php

namespace Reach\StatamicTolk\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Form;

/**
 * Forms with their blueprints, for tests of tolk:forms (§A16).
 */
final class FormFixtures
{
    /**
     * A contact form, as the sandbox's: its title, four fields with display texts, instructions, placeholders and
     * options as a map, and two emails, one with Antlers in its subject.
     */
    public static function contact(): FormContract
    {
        return self::make('contact', 'Contact us', [
            'name' => ['type' => 'text', 'display' => 'Your name'],
            'email' => ['type' => 'text', 'input_type' => 'email', 'display' => 'Email address', 'instructions' => 'We only use it to reply to you.', 'placeholder' => 'name@example.com'],
            'topic' => ['type' => 'select', 'display' => 'Topic', 'options' => ['booking' => 'Room booking', 'events' => 'Weddings and events']],
            'message' => ['type' => 'textarea', 'display' => 'Your message', 'placeholder' => 'Hello, we would like to stay in June…'],
        ], [
            ['to' => 'hotel@example.com', 'subject' => 'New enquiry from the website'],
            ['to' => '{{ email }}', 'subject' => 'Thank you, {{ name }}'],
        ]);
    }

    /**
     * A newsletter form that shares `Email address` with the contact form, has options as a list and as rows, and one
     * email config.
     */
    public static function newsletter(): FormContract
    {
        return self::make('newsletter', 'Newsletter', [
            'email' => ['type' => 'text', 'display' => 'Email address'],
            'interests' => ['type' => 'checkboxes', 'display' => 'Interests', 'options' => ['Food', 'Wine']],
            'consent' => ['type' => 'radio', 'display' => 'Consent', 'options' => [['key' => 'yes', 'value' => 'Yes, send me news'], ['key' => 'no', 'value' => null]]],
        ], ['to' => 'news@example.com', 'subject' => 'New subscriber']);
    }

    /**
     * A form whose texts are translation keys, as a site that keeps its form texts in `lang/{lang}/forms.php` writes
     * them: `forms.contact.*` (`missing` is a key that file lacks) and a package's `acme::forms.placeholder`.
     */
    public static function keyed(): FormContract
    {
        return self::make('keyed', 'forms.contact.title', [
            'name' => ['type' => 'text', 'display' => 'forms.contact.name', 'instructions' => 'forms.contact.missing', 'placeholder' => 'acme::forms.placeholder'],
        ], ['to' => 'hotel@example.com', 'subject' => 'forms.contact.subject']);
    }

    /**
     * Writes `lang/{lang}/forms.php` with the keyed form's texts, but not `missing`.
     *
     * @param  array{0: string, 1: string, 2: string}  $lines  the title, the name and the subject
     */
    public static function keys(string $lang, array $lines): void
    {
        (new Filesystem)->ensureDirectoryExists(lang_path($lang));
        file_put_contents(lang_path("{$lang}/forms.php"), '<?php return '.var_export(['contact' => array_combine(['title', 'name', 'subject'], $lines)], true).';');
    }

    /**
     * @param  array<string, array>  $fields  field configs by handle
     */
    public static function make(string $handle, string $title, array $fields, ?array $email = null): FormContract
    {
        Blueprint::make($handle)->setNamespace('forms')->setContents(['tabs' => ['main' => ['sections' => [[
            'fields' => collect($fields)->map(fn (array $field, string $fieldHandle) => ['handle' => $fieldHandle, 'field' => $field])->values()->all(),
        ]]]]])->save();

        $form = Form::make($handle)->title($title);

        if ($email !== null) {
            $form->email($email);
        }

        $form->save();

        return Form::find($handle);
    }
}
