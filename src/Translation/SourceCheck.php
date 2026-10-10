<?php

namespace Reach\StatamicTolk\Translation;

use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Exceptions\SourceChanged;

/**
 * Whether a field's source is still the value its translation was planned from: the one check that assembly makes
 * (§A8 step 4) and Target::apply() makes again under the write lock (§A11 step 1), so the two never disagree.
 */
final class SourceCheck
{
    /**
     * @param  string  $sourceHash  the fingerprint of the source value the plan was made from
     *
     * @throws SourceChanged when the field is no longer in scope or its source value has another fingerprint
     */
    public static function verify(Target $target, string $handle, string $sourceHash): void
    {
        if (! $target->fields()->has($handle) || Fingerprint::of($target->sourceValue($handle)) !== $sourceHash) {
            throw SourceChanged::field($handle);
        }
    }
}
