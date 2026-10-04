<?php

// A Group holding a Replicator. Some rows are written the way an import writes them: no `id`, no `enabled`.
return [
    'field' => [
        'type' => 'group',
        'display' => 'Page builder',
        'fields' => [
            ['handle' => 'kicker', 'field' => ['type' => 'text', 'display' => 'Kicker']],
            [
                'handle' => 'blocks',
                'field' => [
                    'type' => 'replicator',
                    'display' => 'Blocks',
                    'sets' => [
                        'content' => [
                            'display' => 'Content',
                            'sets' => [
                                'text' => [
                                    'display' => 'Text',
                                    'fields' => [
                                        ['handle' => 'heading', 'field' => ['type' => 'text', 'display' => 'Heading']],
                                        ['handle' => 'label', 'field' => ['type' => 'text', 'display' => 'Link label', 'default' => 'Read more']],
                                        ['handle' => 'body', 'field' => ['type' => 'bard', 'display' => 'Body', 'buttons' => ['h3', 'bold', 'italic', 'anchor', 'unorderedlist']]],
                                        ['handle' => 'anchor', 'field' => ['type' => 'text', 'display' => 'Anchor', 'instructions' => 'Section id used in links, for example `special-offers`.']],
                                    ],
                                ],
                                'faq' => [
                                    'display' => 'FAQ',
                                    'fields' => [
                                        [
                                            'handle' => 'items',
                                            'field' => [
                                                'type' => 'grid',
                                                'display' => 'Questions',
                                                'fields' => [
                                                    ['handle' => 'question', 'field' => ['type' => 'text', 'display' => 'Question']],
                                                    ['handle' => 'answer', 'field' => ['type' => 'textarea', 'display' => 'Answer']],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'cta' => [
                                    'display' => 'Call to action',
                                    'fields' => [
                                        ['import' => 'tolk_fixtures::button', 'prefix' => 'cta_'],
                                    ],
                                ],
                                'note' => [
                                    'display' => 'Note',
                                    'fields' => [
                                        ['handle' => 'text', 'field' => ['type' => 'textarea', 'display' => 'Text']],
                                        ['handle' => 'internal', 'field' => ['type' => 'text', 'display' => 'Internal note', 'tolk_translate' => false]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'value' => [
        'kicker' => 'Summer 2026',
        'blocks' => [
            [
                'id' => 'est8ctaf',
                'type' => 'text',
                'enabled' => true,
                'heading' => 'Special offers',
                'label' => 'Read more',
                'body' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'Stay '],
                            ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'seven nights'],
                            ['type' => 'text', 'text' => ' and the seventh is on us. See the '],
                            [
                                'type' => 'text',
                                'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::7fa871f4-da60-46fe-9053-a94e5eb8e212']]],
                                'text' => 'rooms',
                            ],
                            ['type' => 'text', 'text' => '.'],
                        ],
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'Valid from April to June.'],
                        ],
                    ],
                ],
                'anchor' => 'special-offers',
            ],
            [
                'type' => 'text',
                'heading' => 'Breakfast like a local',
                'body' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'Start with '],
                            ['type' => 'text', 'marks' => [['type' => 'italic']], 'text' => 'bougatsa'],
                            ['type' => 'text', 'text' => ', a warm pastry filled with sweet cheese.'],
                        ],
                    ],
                ],
                'anchor' => 'breakfast',
            ],
            [
                'id' => 'ut16ypui',
                'type' => 'faq',
                'enabled' => true,
                'items' => [
                    [
                        'id' => 'ka44eyis',
                        'question' => 'Is the tap water safe to drink?',
                        'answer' => 'Yes, although most restaurants serve bottled water.',
                    ],
                    [
                        'question' => 'Do I need to tip?',
                        'answer' => 'Service is included, but leaving 5–10% for good service is common.',
                    ],
                    [
                        'question' => 'When do restaurants open for dinner?',
                        'answer' => 'Most open around 19:00, but Cretans rarely eat before 21:00.',
                    ],
                ],
            ],
            [
                'type' => 'cta',
                'cta_label' => 'Book your stay',
                'cta_url' => 'https://booking.kyma-bay.example/',
                'cta_style' => 'primary',
            ],
            [
                'id' => 'n4vq2bzk',
                'type' => 'note',
                'enabled' => false,
                'text' => 'Prices include breakfast and the city tax.',
                'internal' => 'Ask Nikos before changing the prices.',
            ],
        ],
    ],
];
