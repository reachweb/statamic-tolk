<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

/**
 * The target site's tree of a collection that expects a root is empty, and the entry is not the origin tree's root:
 * the first entry saved into it would become the site's home page (§A11 "Root page").
 */
final class RootMissing extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly ?string $rootId,
        public readonly string $rootTitle,
        public readonly ?string $rootEditUrl,
    ) {
        parent::__construct($message);
    }

    public static function for(?string $rootId, string $rootTitle, string $collectionTitle, string $siteName, ?string $rootEditUrl = null): self
    {
        $message = __('Translate :title into :site first. The :collection collection has no :site entries yet, and the first one saved becomes its :site home page, so translating the home page first keeps the structure of the collection\'s tree.', [
            'title' => $rootTitle,
            'collection' => $collectionTitle,
            'site' => $siteName,
        ]);

        return new self($message, $rootId, $rootTitle, $rootEditUrl);
    }

    /**
     * @return ?array{title: string, edit_url: string} the root page to open, for a link next to the message
     */
    public function rootPage(): ?array
    {
        return $this->rootEditUrl === null ? null : ['title' => $this->rootTitle, 'edit_url' => $this->rootEditUrl];
    }
}
