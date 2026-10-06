<?php

namespace Reach\StatamicTolk\Console\Commands;

use Illuminate\Console\Command;
use Reach\StatamicTolk\Enums\AuditLevel;
use Reach\StatamicTolk\Support\Audit;
use Statamic\Console\RunsInPlease;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Prints the checks of Support\Audit and, with --fix-localizable, makes the fields of check `localizable` localizable,
 * asking per blueprint.
 */
class DoctorCommand extends Command
{
    use RunsInPlease;

    private const array LEVELS = [
        'ok' => '<fg=green;options=bold>OK</>',
        'warning' => '<fg=yellow;options=bold>WARNING</>',
        'error' => '<fg=red;options=bold>ERROR</>',
    ];

    private const array MARKS = [
        'ok' => '<fg=green>✓</>',
        'warning' => '<fg=yellow>!</>',
        'error' => '<fg=red>✗</>',
    ];

    protected $signature = 'tolk:doctor
        {--fix-localizable : Offer to make the text fields that are not localizable localizable, blueprint by blueprint}
        {--json : Print the checks as JSON}';

    protected $description = 'Check that this site is set up for translating with Tolk';

    public function handle(Audit $audit): int
    {
        if ($this->option('json') && $this->option('fix-localizable')) {
            $this->components->error('--json and --fix-localizable cannot be combined.');

            return self::FAILURE;
        }

        $checks = $audit->run();
        $exitCode = in_array(AuditLevel::Error->value, array_column($checks, 'level'), true) ? self::FAILURE : self::SUCCESS;

        if ($this->option('json')) {
            $this->line((string) json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exitCode;
        }

        $this->newLine();

        foreach ($checks as $check) {
            $this->printCheck($check);
        }

        if ($this->option('fix-localizable')) {
            $this->fixLocalizable($audit, collect($checks)->firstWhere('id', Audit::LOCALIZABLE)['items']);
        }

        return $exitCode;
    }

    private function printCheck(array $check): void
    {
        $this->components->twoColumnDetail('<options=bold>'.OutputFormatter::escape($check['title']).'</>', self::LEVELS[$check['level']]);

        if ($check['level'] !== AuditLevel::Ok->value) {
            $this->line('  <fg=gray>'.OutputFormatter::escape($check['detail']).'</>');
        }

        foreach ($check['items'] as $item) {
            $this->line('  '.self::MARKS[$item['level']].' '.OutputFormatter::escape($item['text']));
        }

        $this->newLine();
    }

    private function fixLocalizable(Audit $audit, array $items): void
    {
        if ($items === []) {
            $this->components->info('Every text field Tolk translates is localizable.');

            return;
        }

        foreach ($items as $item) {
            $fixable = array_values(array_filter($item['fields'], fn (array $field) => $field['fieldset'] === null));

            foreach (array_filter($item['fields'], fn (array $field) => $field['fieldset'] !== null) as $field) {
                $this->components->warn(sprintf(
                    '%s: %s comes from the fieldset %s, which this command does not change. Make it localizable in the fieldset, which changes every blueprint that imports it.',
                    $item['name'],
                    $field['label'],
                    $field['fieldset'],
                ));
            }

            if ($fixable === [] || ! $this->confirm(sprintf('Make %s localizable in %s?', implode(', ', array_column($fixable, 'label')), $item['name']), false)) {
                continue;
            }

            $count = $audit->makeLocalizable($item['namespace'], $item['blueprint'], array_column($fixable, 'handle'));

            $this->components->info(sprintf(
                '%s: %s localizable. Each localization inherits the origin\'s text until it is translated.',
                $item['name'],
                $count === 1 ? '1 field is now' : "{$count} fields are now",
            ));
        }
    }
}
