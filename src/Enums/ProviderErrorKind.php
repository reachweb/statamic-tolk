<?php

namespace Reach\StatamicTolk\Enums;

enum ProviderErrorKind: string
{
    case Paused = 'paused';
    case Timeout = 'timeout';
    case Unavailable = 'unavailable';
    case Rejected = 'rejected';
    case RequestRejected = 'request_rejected';
}
