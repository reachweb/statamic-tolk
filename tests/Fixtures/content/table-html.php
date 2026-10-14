<?php

// Table cells that hold HTML tags, which go out as html leaves; 1.0.0-beta.1 sent them as escaped plain text.
return [
    'field' => [
        'type' => 'table',
        'display' => 'Dining',
    ],
    'value' => [
        ['cells' => ['Meal', 'Where']],
        ['cells' => ['Breakfast', 'On the <em>terrace</em>']],
        ['cells' => ['Dinner', 'Kids <strong>eat</strong> free<br>until 20:00']],
    ],
];
