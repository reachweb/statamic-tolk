<?php

use Statamic\Facades\Entry;
use Statamic\Facades\Nav;

beforeEach(function () {
    $this->makeCollection('pages', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]], structured: true);

    $this->about = $this->makeEntry('pages', 'en', ['title' => 'About'], 'about');
    $this->aboutEl = $this->localize($this->about, 'el', ['title' => 'Σχετικά']);
    $this->dining = $this->makeEntry('pages', 'en', ['title' => 'Dining'], 'dining');

    $this->makeNav('main', [
        'en' => [
            ['id' => 'branch-about', 'entry' => $this->about->id()],
            ['id' => 'branch-blog', 'title' => 'Blog', 'url' => '/blog', 'children' => [
                ['id' => 'branch-dining', 'entry' => $this->dining->id(), 'title' => 'Eat with us'],
            ]],
        ],
    ]);
    Nav::findByHandle('main')->collections(['pages'])->save();
});

it('builds a tree for a new site from the tree of another', function () {
    $nav = Nav::findByHandle('main');

    $map = function (array $branches) use (&$map): array {
        return array_map(function (array $branch) use ($map) {
            if (isset($branch['entry'])) {
                $branch['entry'] = Entry::find($branch['entry'])->in('el')?->id() ?? $branch['entry'];
            }
            if (isset($branch['title'])) {
                $branch['title'] = "[el] {$branch['title']}";
            }
            if (isset($branch['children'])) {
                $branch['children'] = $map($branch['children']);
            }

            return $branch;
        }, $branches);
    };

    expect($nav->in('el'))->toBeNull();

    $nav->makeTree('el')->tree($map($nav->in('en')->tree()))->save();

    expect(Nav::findByHandle('main')->in('el')->tree())->toBe([
        ['id' => 'branch-about', 'entry' => $this->aboutEl->id()],
        ['id' => 'branch-blog', 'title' => '[el] Blog', 'url' => '/blog', 'children' => [
            ['id' => 'branch-dining', 'entry' => $this->dining->id(), 'title' => '[el] Eat with us'],
        ]],
    ]);
});

it('resolves a branch holding the localization id to the localized page and one holding the origin id to the origin page', function () {
    Nav::findByHandle('main')->makeTree('el', [
        ['id' => 'greek', 'entry' => $this->aboutEl->id()],
        ['id' => 'english', 'entry' => $this->about->id()],
    ])->save();

    $pages = Nav::findByHandle('main')->in('el')->pages()->all()->keyBy->id();

    expect($pages['greek']->title())->toBe('Σχετικά')
        ->and($pages['greek']->url())->toBe('/el/about')
        ->and($pages['greek']->entry()->locale())->toBe('el')
        ->and($pages['english']->title())->toBe('About')
        ->and($pages['english']->url())->toBe('/about')
        ->and($pages['english']->entry()->locale())->toBe('en');
});

it('keeps a branch title over the entry title', function () {
    $pages = Nav::findByHandle('main')->in('en')->flattenedPages()->keyBy->id();

    expect($pages['branch-dining']->title())->toBe('Eat with us')
        ->and($pages['branch-about']->title())->toBe('About');
});
