<?php

declare(strict_types=1);

namespace MauticPlugin\HousekeepingBundle\Integration\Support;

use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use MauticPlugin\HousekeepingBundle\Integration\HousekeepingIntegration;

class ConfigSupport extends HousekeepingIntegration implements ConfigFormInterface
{
    use DefaultConfigFormTrait;
}
