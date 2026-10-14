<?php

// List items that hold HTML tags, which go out as html leaves; 1.0.0-beta.1 sent them as escaped plain text.
return [
    'field' => [
        'type' => 'list',
        'display' => 'Highlights',
    ],
    'value' => [
        'Breakfast served <b>daily</b>',
        '<a href="/spa" title="Spa">Spa</a> access',
        'Kids < 12 eat free',
        'Late check-out on request',
    ],
];
