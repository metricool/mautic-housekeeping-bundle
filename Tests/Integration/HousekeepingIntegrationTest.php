<?php

declare(strict_types=1);

namespace MauticPlugin\HousekeepingBundle\Tests\Integration;

use MauticPlugin\HousekeepingBundle\HousekeepingBundle;
use MauticPlugin\HousekeepingBundle\Integration\HousekeepingIntegration;
use PHPUnit\Framework\TestCase;

final class HousekeepingIntegrationTest extends TestCase
{
    /**
     * The integration has the name Mautic stores and the name it shows.
     */
    public function testNamesTheIntegration(): void
    {
        $integration = new HousekeepingIntegration();

        $this->assertSame('housekeeping', $integration->getName());
        $this->assertSame('Mautic Housekeeping', $integration->getDisplayName());
    }

    /**
     * The icon path points to a file inside this plugin's folder.
     */
    public function testIconIsAFileOfThisPlugin(): void
    {
        $prefix = 'plugins/HousekeepingBundle/';
        $icon   = (new HousekeepingIntegration())->getIcon();

        $this->assertStringStartsWith($prefix, $icon);
        $this->assertFileExists(dirname(__DIR__, 2).'/'.substr($icon, strlen($prefix)));
    }

    /**
     * Composer installs the plugin into the folder Mautic expects for the bundle class.
     */
    public function testComposerInstallsIntoTheBundleFolder(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($composer);
        $this->assertSame('metricool/mautic-housekeeping-bundle', $composer['name']);
        $this->assertSame((new \ReflectionClass(HousekeepingBundle::class))->getShortName(), $composer['extra']['install-directory-name']);
    }

    /**
     * Mautic lists the plugin as Mautic Housekeeping.
     */
    public function testPluginConfigNamesThePluginMauticHousekeeping(): void
    {
        $config = require dirname(__DIR__, 2).'/Config/config.php';

        $this->assertSame('Mautic Housekeeping', $config['name']);
        $this->assertSame('Metricool', $config['author']);
    }
}
