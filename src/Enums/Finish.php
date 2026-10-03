<?php

namespace Reach\StatamicTolk\Enums;

enum Finish: string
{
    case Stop = 'stop';
    case Length = 'length';
    case Refused = 'refused';
    case Empty = 'empty';
}
