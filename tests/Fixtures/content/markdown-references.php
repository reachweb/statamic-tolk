<?php

// The Markdown constructs whose walk changed after 1.0.0-beta.1: Antlers tags in link destinations and reference
// definitions, attribute lists beyond an id, a class and a name with a value, and definitions in a blockquote, in a list
// item and with a label over two lines.
return [
    'field' => [
        'type' => 'markdown',
        'display' => 'Body',
        'antlers' => true,
    ],
    'value' => <<<'MD'
Write to [the front desk]({{ link to="contact" }}), read [our story] or meet [the team][team].

![The pool]({{ glide:image width="800" }} "The pool")

> Our hours are on [the hours page][hours].
>
> [hours]: {{ link to="hours" }} "Opening hours"

- See [the menu][menu].
- [menu]: /menu

Open *the menu*{x-on:click="open = true"} or *the map*{: .wide} now.

[our
story]: /about

[team]: {{ link to="about" anchor="team" }}
MD,
];
