<?php

namespace Reach\StatamicTolk\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Reach\StatamicTolk\Support\Sanitizer;
use RuntimeException;
use Throwable;

/**
 * A stored state record that exists but cannot be read, such as a file a git merge left conflict markers or a key twice
 * in. It is never read as empty: the next write would replace what both sides of the merge recorded.
 */
final class UnreadableState extends RuntimeException
{
    public const string CODE = 'unreadable_state';

    public function __construct(string $message, public readonly string $path, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function file(string $path, Throwable $previous): self
    {
        return new self(__('The translation state file :file cannot be read: :error. Fix it, for example by resolving the git merge conflict in it, or delete it, after which Tolk reads its translations as edited by hand.', [
            'file' => self::relativePath($path),
            'error' => rtrim(trim($previous->getMessage()), '.'),
        ]), $path, $previous);
    }

    /**
     * The path from the project's root, or the whole path for a file outside it.
     */
    public static function relativePath(string $path): string
    {
        $base = rtrim(base_path(), '/\\').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * Answers a request of the CP's scripts with the message, which names the file, instead of a bare server error.
     */
    public function render(Request $request): ?JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => Sanitizer::message($this->getMessage()), 'code' => self::CODE], 500)
            : null;
    }
}
