<?php

return [
    'field' => [
        'type' => 'grid',
        'display' => 'Rooms',
        'mode' => 'table',
        'fields' => [
            ['handle' => 'name', 'field' => ['type' => 'text', 'display' => 'Name']],
            ['handle' => 'description', 'field' => ['type' => 'textarea', 'display' => 'Description']],
            ['handle' => 'size', 'field' => ['type' => 'integer', 'display' => 'Size (m²)']],
            ['handle' => 'icon', 'field' => ['type' => 'text', 'display' => 'Icon']],
            ['handle' => 'booking_url', 'field' => ['type' => 'text', 'display' => 'Booking link', 'input_type' => 'url']],
        ],
    ],
    'value' => [
        [
            'id' => 'r7xk2m9q',
            'name' => 'Garden double',
            'description' => 'A quiet room on the ground floor, with a door to the garden.',
            'size' => 22,
            'icon' => 'icon_garden',
            'booking_url' => 'https://booking.kyma-bay.example/garden',
        ],
        [
            'name' => 'Sea view suite',
            'description' => "Two rooms and a balcony over the harbour.\nThe sofa turns into a bed for one child.",
            'size' => 45,
            'icon' => 'icon_wave',
        ],
        [
            'id' => 'b3hw8tno',
            'name' => 'Honeymoon suite',
            'size' => 60,
        ],
    ],
];
