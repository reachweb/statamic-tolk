<?php

$cell = fn (string $type, array $content) => [
    'type' => $type,
    'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null, 'align' => null],
    'content' => [['type' => 'paragraph', 'attrs' => ['textAlign' => null], 'content' => $content]],
];

return [
    'field' => [
        'type' => 'bard',
        'display' => 'Content',
        'container' => 'assets',
        'buttons' => [
            'h2', 'h3', 'bold', 'italic', 'underline', 'strikethrough', 'small', 'superscript', 'code', 'codeblock',
            'quote', 'anchor', 'unorderedlist', 'orderedlist', 'table', 'image', 'horizontalrule',
            'alignleft', 'aligncenter', 'alignright',
        ],
        'sets' => [
            'main' => [
                'display' => 'Main',
                'sets' => [
                    'quote' => [
                        'display' => 'Quote',
                        'fields' => [
                            ['handle' => 'text', 'field' => ['type' => 'textarea', 'display' => 'Text']],
                            ['handle' => 'author', 'field' => ['type' => 'text', 'display' => 'Author']],
                        ],
                    ],
                    'gallery' => [
                        'display' => 'Gallery',
                        'fields' => [
                            ['handle' => 'images', 'field' => ['type' => 'assets', 'display' => 'Images', 'container' => 'assets']],
                            ['handle' => 'caption', 'field' => ['type' => 'text', 'display' => 'Caption']],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'value' => [
        [
            'type' => 'heading',
            'attrs' => ['level' => 2, 'textAlign' => 'center'],
            'content' => [
                ['type' => 'text', 'text' => 'Welcome to Kyma Bay'],
            ],
        ],
        [
            'type' => 'paragraph',
            'attrs' => ['textAlign' => null],
            'content' => [
                ['type' => 'text', 'text' => 'Since 1987 the Markakis family has welcomed guests to the '],
                ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'old harbour'],
                ['type' => 'text', 'text' => ' of Chania. Breakfast is served until '],
                ['type' => 'text', 'marks' => [['type' => 'italic']], 'text' => "eleven o'clock"],
                ['type' => 'text', 'text' => ', '],
                ['type' => 'text', 'marks' => [['type' => 'bold'], ['type' => 'italic']], 'text' => 'always'],
                ['type' => 'text', 'text' => ' with bread from the oven next door.'],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Browse our '],
                [
                    'type' => 'text',
                    'marks' => [
                        ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::7fa871f4-da60-46fe-9053-a94e5eb8e212', 'rel' => null, 'target' => null, 'title' => null]],
                    ],
                    'text' => 'rooms and suites',
                ],
                ['type' => 'text', 'text' => ', check the '],
                [
                    'type' => 'text',
                    'marks' => [
                        ['type' => 'link', 'attrs' => ['href' => 'https://www.chania-port.example/ferries', 'rel' => 'noopener noreferrer', 'target' => '_blank', 'title' => 'Ferry timetable of the port authority']],
                    ],
                    'text' => 'ferry timetable',
                ],
                ['type' => 'text', 'text' => ' or download the '],
                [
                    'type' => 'text',
                    'marks' => [
                        ['type' => 'bold'],
                        ['type' => 'link', 'attrs' => ['href' => 'statamic://asset::assets::price-list.pdf', 'rel' => null, 'target' => '_blank', 'title' => null]],
                    ],
                    'text' => 'price list',
                ],
                ['type' => 'text', 'text' => '.'],
            ],
        ],
        [
            'type' => 'paragraph',
            'attrs' => ['textAlign' => 'left'],
            'content' => [
                ['type' => 'text', 'text' => 'Kyma Bay Hotel'],
                ['type' => 'hardBreak'],
                ['type' => 'text', 'text' => 'Akti Koundourioti 12, Chania'],
                ['type' => 'hardBreak'],
                ['type' => 'text', 'text' => 'Wi-Fi password: '],
                ['type' => 'text', 'marks' => [['type' => 'code']], 'text' => 'kyma-guest-2026'],
                ['type' => 'text', 'text' => ' (kids < 12 & pets stay free)'],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Look for this sign at the harbour: '],
                ['type' => 'image', 'attrs' => ['src' => 'asset::assets::sign.png', 'alt' => 'A blue wave on a white sign']],
            ],
        ],
        [
            'type' => 'image',
            'attrs' => ['src' => 'asset::assets::pool-terrace.jpg', 'alt' => 'The saltwater pool and sun terrace, with the harbour lighthouse in the background'],
        ],
        [
            'type' => 'set',
            'attrs' => [
                'id' => 'p6rkq4uy',
                'values' => [
                    'type' => 'quote',
                    'text' => 'We came for a week and stayed for three. The staff remembered our names from the first morning.',
                    'author' => 'Hannah, guest from Hamburg',
                ],
            ],
        ],
        [
            'type' => 'heading',
            'attrs' => ['level' => 3],
            'content' => [
                ['type' => 'text', 'text' => 'Good to know'],
            ],
        ],
        [
            'type' => 'bulletList',
            'content' => [
                [
                    'type' => 'listItem',
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Check-in from 15:00, check-out until 11:00']]],
                    ],
                ],
                [
                    'type' => 'listItem',
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Getting here from the airport:']]],
                        [
                            'type' => 'orderedList',
                            'attrs' => ['start' => 1, 'type' => null],
                            'content' => [
                                [
                                    'type' => 'listItem',
                                    'content' => [
                                        [
                                            'type' => 'paragraph',
                                            'content' => [
                                                ['type' => 'text', 'text' => 'Take the airport bus to the '],
                                                ['type' => 'text', 'marks' => [['type' => 'underline']], 'text' => 'central station'],
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'type' => 'listItem',
                                    'content' => [
                                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Walk ten minutes down to the harbour']]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'listItem',
                    'content' => [
                        [
                            'type' => 'paragraph',
                            'content' => [
                                ['type' => 'text', 'marks' => [['type' => 'strike']], 'text' => 'Pets on request.'],
                                ['type' => 'text', 'text' => ' Pets are welcome in the garden rooms.'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        [
            'type' => 'blockquote',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Kyma means wave in Greek.']]],
            ],
        ],
        [
            'type' => 'table',
            'content' => [
                [
                    'type' => 'tableRow',
                    'content' => [
                        $cell('tableHeader', [['type' => 'text', 'text' => 'Room']]),
                        $cell('tableHeader', [['type' => 'text', 'text' => 'Size']]),
                        $cell('tableHeader', [['type' => 'text', 'text' => 'Price per night']]),
                    ],
                ],
                [
                    'type' => 'tableRow',
                    'content' => [
                        $cell('tableCell', [['type' => 'text', 'text' => 'Garden double']]),
                        $cell('tableCell', [['type' => 'text', 'text' => '22 m'], ['type' => 'text', 'marks' => [['type' => 'superscript']], 'text' => '2']]),
                        $cell('tableCell', [['type' => 'text', 'text' => '€120']]),
                    ],
                ],
                [
                    'type' => 'tableRow',
                    'content' => [
                        $cell('tableCell', [['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'Sea view suite']]),
                        $cell('tableCell', [['type' => 'text', 'text' => '45 m'], ['type' => 'text', 'marks' => [['type' => 'superscript']], 'text' => '2']]),
                        $cell('tableCell', [['type' => 'text', 'text' => 'from €240']]),
                    ],
                ],
            ],
        ],
        [
            'type' => 'codeBlock',
            'attrs' => ['language' => null],
            'content' => [
                ['type' => 'text', 'text' => "Network: KymaBay-Guests\nPassword: kyma-guest-2026"],
            ],
        ],
        [
            'type' => 'horizontalRule',
        ],
        [
            'type' => 'set',
            'attrs' => [
                'id' => 'w2nd8qhe',
                'enabled' => false,
                'values' => [
                    'type' => 'gallery',
                    'images' => ['pool-terrace.jpg', 'garden-breakfast.jpg'],
                    'caption' => 'The pool terrace, and the garden at breakfast time',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'attrs' => ['textAlign' => null],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'marks' => [['type' => 'small']], 'text' => 'Prices include VAT and the city tax of €1.50 per night.'],
            ],
        ],
    ],
];
