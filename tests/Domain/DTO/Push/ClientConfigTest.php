<?php

declare(strict_types=1);

namespace App\Tests\Domain\DTO\Push;

use App\Domain\DTO\Push\ClientConfig;
use PHPUnit\Framework\TestCase;

/**
 * Which Android client of a google-services.json the Session publishes.
 *
 * The file the administrator pastes is whatever their Firebase project holds,
 * in whatever order the console wrote it. Between them, the cases below pin
 * that the Play build wins wherever it is listed, that a project registered
 * before Play still serves the GitHub builds, and that anything else still
 * gets an answer rather than a refusal.
 */
final class ClientConfigTest extends TestCase
{
    public function testThePlayPackageWinsWhereverItIsListed(): void
    {
        $config = ClientConfig::fromGoogleServicesJson(self::file(
            'com.example.fork',
            'de.plmail.google',
            'de.pluetzner.plmail',
        ));

        self::assertSame('de.pluetzner.plmail', $config->packageName);
        self::assertSame('1:42:android:de.pluetzner.plmail', $config->applicationId);
    }

    public function testAProjectRegisteredBeforePlayStillServesTheGitHubBuild(): void
    {
        $config = ClientConfig::fromGoogleServicesJson(self::file('com.example.fork', 'de.plmail.google'));

        self::assertSame('de.plmail.google', $config->packageName);
    }

    public function testAnyOtherPackageFallsBackToTheFirstListed(): void
    {
        $config = ClientConfig::fromGoogleServicesJson(self::file('com.example.fork', 'com.example.other'));

        self::assertSame('com.example.fork', $config->packageName);
    }

    /**
     * One client per package, each with an app id that names it, so an
     * assertion on the id shows the values came from the client picked.
     */
    private static function file(string ...$packages): string
    {
        return (string) json_encode([
            'project_info' => ['project_number' => '42', 'project_id' => 'plmail-test'],
            'client'       => array_map(
                static fn (string $package): array => [
                    'client_info' => [
                        'mobilesdk_app_id'    => '1:42:android:' . $package,
                        'android_client_info' => ['package_name' => $package],
                    ],
                    'api_key' => [['current_key' => 'AIzaSy' . md5($package)]],
                ],
                $packages,
            ),
        ], JSON_THROW_ON_ERROR);
    }
}
