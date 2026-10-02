<?php

use Statamic\Facades\Site;
use Statamic\Facades\Term;
use Statamic\Taxonomies\LocalizedTerm;

beforeEach(function () {
    $this->makeTaxonomy('topics', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'description' => ['type' => 'textarea', 'localizable' => true],
    ]);
    $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food', 'description' => 'Eat well']]);
});

it('stores a merge on a site under that site', function () {
    Term::find('topics::food')->in('el')->merge(['title' => 'Φαγητό'])->save();

    $term = Term::find('topics::food');

    expect($term->in('el')->data()->all())->toBe(['title' => 'Φαγητό'])
        ->and($term->in('en')->data()->all())->toBe(['title' => 'Food', 'description' => 'Eat well'])
        ->and($term->in('de')->data()->all())->toBe([])
        ->and($term->term()->fileData())->toBe([
            'title' => 'Food',
            'description' => 'Eat well',
            'localizations' => ['el' => ['title' => 'Φαγητό']],
        ]);
});

it('finds the term in the taxonomy default site whatever the current site is', function () {
    Site::setCurrent('el');

    $term = Term::find('topics::food');

    expect($term)->toBeInstanceOf(LocalizedTerm::class)
        ->and($term->locale())->toBe('en')
        ->and($term->id())->toBe('topics::food');
});

it('takes the first site of the taxonomy as the default site and origin', function () {
    $this->makeTaxonomy('tags', ['el', 'en'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->makeTerm('tags', 'sea', ['el' => ['title' => 'Θάλασσα']]);

    $term = Term::find('tags::sea');

    expect($term->term()->defaultLocale())->toBe('el')
        ->and($term->locale())->toBe('el')
        ->and($term->in('el')->hasOrigin())->toBeFalse()
        ->and($term->in('en')->origin()->locale())->toBe('el')
        ->and($term->in('en')->value('title'))->toBe('Θάλασσα');
});

it('throws on updateLastModified() without a user and stores the user and time with one', function () {
    $localized = Term::find('topics::food')->in('el');

    expect(fn () => $localized->updateLastModified())->toThrow(BadMethodCallException::class, 'LocalizedTerm::remove()');

    $user = $this->signInAdmin();
    $localized->updateLastModified($user)->save();

    expect(Term::find('topics::food')->in('el')->data()->keys()->all())->toBe(['updated_by', 'updated_at'])
        ->and(Term::find('topics::food')->in('el')->get('updated_by'))->toBe($user->id());
});

it('reports has() false for an explicit null that data() holds', function () {
    Term::find('topics::food')->in('el')->merge(['description' => null])->save();

    $localized = Term::find('topics::food')->in('el');

    expect($localized->data()->has('description'))->toBeTrue()
        ->and($localized->has('description'))->toBeFalse()
        ->and($localized->value('description'))->toBeNull()
        ->and($localized->has('title'))->toBeFalse()
        ->and($localized->value('title'))->toBe('Food');
});

it('queries terms of one site as localized terms of that site', function () {
    $terms = Term::query()->where('taxonomy', 'topics')->where('site', 'el')->get();

    expect($terms)->toHaveCount(1)
        ->and($terms->first())->toBeInstanceOf(LocalizedTerm::class)
        ->and($terms->first()->locale())->toBe('el');
});
