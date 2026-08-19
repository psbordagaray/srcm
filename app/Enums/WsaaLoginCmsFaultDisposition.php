<?php

namespace App\Enums;

enum WsaaLoginCmsFaultDisposition: string
{
    case TransientNotBefore60Seconds =
        'transient_not_before_60_seconds';

    case ActionRequiredNoAutomaticRetry =
        'action_required_no_automatic_retry';
}
