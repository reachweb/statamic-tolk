<?php

return [
    'field' => [
        'type' => 'bard',
        'display' => 'Tagline',
        'inline' => true,
        'inline_hard_breaks' => true,
        'buttons' => ['bold', 'italic', 'anchor'],
    ],
    'value' => [
        ['type' => 'text', 'text' => 'Rooms '],
        ['type' => 'text', 'marks' => [['type' => 'italic']], 'text' => 'by the sea'],
        ['type' => 'text', 'text' => ' in '],
        [
            'type' => 'text',
            'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::7fa871f4-da60-46fe-9053-a94e5eb8e212', 'rel' => null, 'target' => null, 'title' => null]],
            ],
            'text' => 'Chania',
        ],
        ['type' => 'hardBreak'],
        ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'since 1987'],
    ],
];
