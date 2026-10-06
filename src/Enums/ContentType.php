<?php

namespace Reach\StatamicTolk\Enums;

enum ContentType: string
{
    case Entry = 'entry';
    case Term = 'term';
    case GlobalSet = 'globals';
    case Nav = 'nav';
    case Lang = 'lang';
}
