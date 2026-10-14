<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;
use Statamic\View\Antlers\Language\Exceptions\AntlersException;
use Throwable;

final class InvalidSettings extends RuntimeException
{
    /**
     * @param  ?string  $file  the file core's file driver keeps the settings in, from the site's root; null for another driver
     */
    public static function unreadable(?string $file, Throwable $previous): self
    {
        $message = $file === null
            ? __('The Tolk settings could not be read: :error', ['error' => $previous->getMessage()])
            : __('The Tolk settings in :file could not be read: :error', ['file' => $file, 'error' => $previous->getMessage()]);

        if ($previous instanceof AntlersException) {
            $message .= ' '.__('Statamic reads {{ and }} in settings as Antlers: to keep them as written in a text, put the whole text between {{ noparse }} and {{ /noparse }}.');
        }

        return new self($message, previous: $previous);
    }
}
