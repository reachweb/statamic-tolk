<?php

return [
    'field' => [
        'type' => 'text',
        'display' => 'Welcome line',
        'antlers' => true,
    ],
    'value' => 'Welcome to {{ site:name }}, {{ current_user:first_name ?? "guest" }}! Book before {{ offer:ends_at format="j F" }} and save {{ offer:discount }}%.',
];
