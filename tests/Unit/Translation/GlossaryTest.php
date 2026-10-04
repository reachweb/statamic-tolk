<?php

use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Translation\Glossary;

function glossaryOf(array ...$rows): Glossary
{
    return new Glossary(array_map(fn (array $row) => [
        'term' => $row['term'],
        'site' => $row['site'] ?? null,
        'translation' => $row['translation'] ?? '',
        'do_not_translate' => $row['do_not_translate'] ?? false,
        'note' => $row['note'] ?? '',
    ], $rows));
}

describe('rows', function () {
    it('lets a row of the site replace the rows for every site with the same term', function () {
        $settings = $this->withSettings(['glossary' => [
            ['id' => 'g1', 'term' => 'suite', 'translation' => 'Suite'],
            ['id' => 'g2', 'term' => 'Suite', 'site' => ['el'], 'translation' => 'σουίτα'],
            ['id' => 'g3', 'term' => 'Kyma Bay', 'do_not_translate' => true],
            ['id' => 'g4', 'term' => 'kyma bay', 'site' => ['de'], 'translation' => 'Kyma-Bucht'],
            ['id' => 'g5', 'term' => 'check-in', 'site' => ['el'], 'translation' => 'άφιξη'],
        ]]);

        expect(Glossary::fromSettings($settings, 'el')->rows())->toBe([
            ['term' => 'check-in', 'site' => 'el', 'translation' => 'άφιξη', 'do_not_translate' => false, 'note' => ''],
            ['term' => 'Kyma Bay', 'site' => null, 'translation' => '', 'do_not_translate' => true, 'note' => ''],
            ['term' => 'Suite', 'site' => 'el', 'translation' => 'σουίτα', 'do_not_translate' => false, 'note' => ''],
        ])
            ->and(Glossary::fromSettings($settings, 'de')->rows())->toBe([
                ['term' => 'kyma bay', 'site' => 'de', 'translation' => 'Kyma-Bucht', 'do_not_translate' => false, 'note' => ''],
                ['term' => 'suite', 'site' => null, 'translation' => 'Suite', 'do_not_translate' => false, 'note' => ''],
            ]);
    });

    it('keeps every row of the site and every row for all sites of a term the site does not override', function () {
        $rows = glossaryOf(
            ['term' => 'Booking', 'translation' => 'Κράτηση'],
            ['term' => 'booking', 'translation' => 'κράτηση'],
            ['term' => 'room', 'site' => 'el', 'translation' => 'δωμάτιο'],
            ['term' => 'room', 'site' => 'el', 'translation' => 'κατάλυμα'],
        )->rows();

        expect(array_column($rows, 'translation'))->toBe(['Κράτηση', 'κράτηση', 'δωμάτιο', 'κατάλυμα']);
    });

    it('is empty without glossary settings', function () {
        expect(Glossary::fromSettings($this->withSettings(), 'el')->rows())->toBe([]);
    });
});

describe('do not translate', function () {
    it('warns when the translation leaves out a term the source uses', function () {
        $notes = glossaryOf(['term' => 'Kyma Bay', 'do_not_translate' => true])
            ->check('Welcome to Kyma Bay.', 'Καλώς ήρθατε στο Κύμα Μπέι.');

        expect($notes)->toBe([[
            'level' => Glossary::WARNING,
            'message' => 'the glossary keeps "Kyma Bay" untranslated, but the translation does not contain it.',
        ]]);
    });

    it('is satisfied when the translation keeps the term, whatever its case and spacing', function () {
        $glossary = glossaryOf(['term' => 'Kyma Bay', 'do_not_translate' => true]);

        expect($glossary->check('Welcome to Kyma Bay.', 'Καλώς ήρθατε στο Kyma Bay.'))->toBe([])
            ->and($glossary->check('Welcome to KYMA BAY.', "Καλώς ήρθατε στο kyma\u{00A0}bay."))->toBe([]);
    });

    it('says nothing when the source does not use the term', function () {
        $glossary = glossaryOf(['term' => 'Kyma Bay', 'do_not_translate' => true]);

        expect($glossary->check('Welcome to our hotel.', 'Καλώς ήρθατε.'))->toBe([]);
    });

    it('matches the source term on word boundaries only', function () {
        $glossary = glossaryOf(['term' => 'Kyma', 'do_not_translate' => true], ['term' => 'C++', 'do_not_translate' => true]);

        expect($glossary->check('Kymata are waves.', 'Τα κύματα.'))->toBe([])
            ->and($glossary->check('Kymas', 'Κύματα'))->toBe([])
            ->and($glossary->check('The Kyma-shuttle runs daily.', 'Το λεωφορείο.'))->toHaveCount(1)
            ->and($glossary->check('Written in C++, mostly.', 'Γραμμένο σε C.'))->toHaveCount(1)
            ->and($glossary->check('Written in C++.', 'Γραμμένο σε C++.'))->toBe([]);
    });

    it('still matches a term split by a placeholder', function (string $source) {
        $notes = glossaryOf(['term' => 'Kyma Bay', 'do_not_translate' => true])
            ->check($source, 'Καλώς ήρθατε στο <1>Κύμα</1> Μπέι.', SegmentKind::Inline);

        expect($notes)->toHaveCount(1)
            ->and($notes[0]['level'])->toBe(Glossary::WARNING);
    })->with([
        'a formatted word' => '<1>Kyma</1> Bay',
        'a formatted half word' => 'Ky<1>ma Bay</1>',
        'a line break' => 'Kyma<br/>Bay',
    ]);

    it('accepts a kept term split by a placeholder in the translation', function () {
        $notes = glossaryOf(['term' => 'Kyma Bay', 'do_not_translate' => true])
            ->check('Welcome to <1>Kyma Bay</1>.', 'Καλώς ήρθατε στο <1>Kyma</1> Bay.', SegmentKind::Inline);

        expect($notes)->toBe([]);
    });

    it('reads character references in HTML', function () {
        $glossary = glossaryOf(['term' => 'B&B', 'do_not_translate' => true]);

        expect($glossary->check('<p>Our B&amp;B</p>', '<p>Το B&amp;B μας</p>', SegmentKind::Html))->toBe([])
            ->and($glossary->check('<p>Our B&amp;B</p>', '<p>Ο ξενώνας μας</p>', SegmentKind::Html))->toHaveCount(1);
    });
});

describe('fixed translations', function () {
    it('notes a translation that does not use the fixed translation', function () {
        $notes = glossaryOf(['term' => 'room', 'translation' => 'δωμάτιο'])
            ->check('Book a room today.', 'Κλείστε ένα κατάλυμα σήμερα.');

        expect($notes)->toBe([[
            'level' => Glossary::INFO,
            'message' => 'the glossary translates "room" as "δωμάτιο", but the translation does not seem to use it.',
        ]]);
    });

    it('accepts inflected Greek forms of the fixed translation', function (string $term, string $fixed, string $source, string $translation) {
        expect(glossaryOf(['term' => $term, 'translation' => $fixed])->check($source, $translation))->toBe([]);
    })->with([
        'genitive with a moved accent' => ['room', 'δωμάτιο', 'The size of the room.', 'Το μέγεθος του δωματίου.'],
        'plural with a moved accent' => ['booking', 'κράτηση', 'All bookings are final.', 'Όλες οι κρατήσεις είναι οριστικές.'],
        'capitals without accents' => ['suite', 'σουίτα', 'Our Suite.', 'Η ΣΟΥΙΤΑ μας.'],
        'a two-word name in the genitive' => ['beach', 'Ακτή Σταλός', 'Stalos beach.', 'Στην ακτή Σταλού.'],
        'a phrase with each word inflected' => ['sea view room', 'δωμάτιο με θέα', 'A sea view room.', 'Τιμές δωματίων με θέα.'],
    ]);

    it('accepts a fixed translation inside a compound word', function () {
        expect(glossaryOf(['term' => 'room', 'translation' => 'Zimmer'])->check('A double room.', 'Ein Doppelzimmer.'))->toBe([]);
    });

    it('notes a fixed translation of which less than 70% is there', function () {
        // "δωμάτιο" needs "δωματ" (5 of 7 characters); "δωμ" is too little.
        expect(glossaryOf(['term' => 'room', 'translation' => 'δωμάτιο'])->check('A room.', 'Ένα δώμα.'))->toHaveCount(1);
    });

    it('does not check a shorter term found only inside a longer one', function () {
        $glossary = glossaryOf(
            ['term' => 'Kyma Bay', 'do_not_translate' => true],
            ['term' => 'bay', 'translation' => 'κόλπος'],
        );

        expect($glossary->check('Welcome to Kyma Bay.', 'Καλώς ήρθατε στο Kyma Bay.'))->toBe([])
            ->and($glossary->check('Kyma Bay faces the bay.', 'Το Kyma Bay βλέπει τη θάλασσα.'))->toBe([[
                'level' => Glossary::INFO,
                'message' => 'the glossary translates "bay" as "κόλπος", but the translation does not seem to use it.',
            ]]);
    });

    it('gives one note per row and never fails on unusual terms', function () {
        $glossary = glossaryOf(
            ['term' => '2024', 'translation' => 'MMXXIV'],
            ['term' => '/(.*)/', 'do_not_translate' => true],
            ['term' => 'suite', 'translation' => 'σουίτα'],
        );

        expect(array_column($glossary->check('Suite in 2024 /(.*)/', 'Δωμάτια το 2024'), 'level'))
            ->toBe([Glossary::WARNING, Glossary::INFO, Glossary::INFO]);
    });
});
