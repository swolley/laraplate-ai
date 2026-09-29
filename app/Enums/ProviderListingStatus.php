<?php

declare(strict_types=1);

namespace Modules\AI\Enums;

enum ProviderListingStatus: string
{
    case Listed = 'listed';
    case NotConfigured = 'not_configured';
    case Failed = 'failed';
}
