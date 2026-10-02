<?php

namespace Reach\StatamicTolk\Support;

final readonly class Language
{
    public function __construct(
        public string $site,
        public string $name,
        public string $locale,
        public string $lang,
        public ?string $region,
        public string $direction,
    ) {}

    public function describe(): string
    {
        $name = $this->region === null ? $this->name : "{$this->name} ({$this->region})";

        return "{$name}, locale {$this->locale}";
    }
}
