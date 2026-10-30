<?php

declare(strict_types=1);

namespace App\Tests\Unit\Deploy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Yaml\Yaml;

/**
 * @module tests/Unit/Deploy/FrameworkPolicyTest
 * @description Pins the Symfony line (D-20261030-01): every Symfony component is required and installed on one
 *   minor release, the one Flex is told to use, and a Flex recipe update never moves the migrations away from
 *   the namespace and folder whose version names production databases already record.
 * @dependencies composer.json, composer.lock, config/packages/doctrine_migrations.yaml, migrations/
 */
#[CoversNothing]
final class FrameworkPolicyTest extends TestCase
{
    /** Symfony packages that follow their own version numbers, not the framework's. */
    private const OWN_VERSIONS = ['symfony/flex', 'symfony/monolog-bundle', 'symfony/phpunit-bridge'];

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /** @return array<string, mixed> */
    private static function json(string $path): array
    {
        return json_decode((string) file_get_contents(self::root().'/'.$path), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testSymfonyComponentsShareTheMinorFlexIsToldToUse(): void
    {
        $composer = self::json('composer.json');
        $line = $composer['extra']['symfony']['require'];
        self::assertMatchesRegularExpression('~^8\.\d+\.\*$~', $line, 'the 8.x line (D-20261030-01)');
        $required = array_filter(
            [...$composer['require'], ...$composer['require-dev']],
            static fn (string $name) => str_starts_with($name, 'symfony/') && !\in_array($name, self::OWN_VERSIONS, true),
            \ARRAY_FILTER_USE_KEY,
        );
        self::assertNotEmpty($required);
        foreach ($required as $name => $constraint) {
            self::assertSame($line, $constraint, "$name is required on the framework's minor");
        }

        $lock = self::json('composer.lock');
        $minor = substr($line, 0, -2);
        foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
            if (isset($required[$package['name']])) {
                self::assertStringStartsWith("v$minor.", $package['version'], "{$package['name']} is installed on $minor");
            }
        }
        self::assertSame($minor, Kernel::MAJOR_VERSION.'.'.Kernel::MINOR_VERSION, 'the running kernel');
    }

    public function testMigrationsKeepTheNamespaceProductionDatabasesRecord(): void
    {
        $config = Yaml::parseFile(self::root().'/config/packages/doctrine_migrations.yaml');
        self::assertSame(['DoctrineMigrations' => '%kernel.project_dir%/migrations'], $config['doctrine_migrations']['migrations_paths']);
        $files = glob(self::root().'/migrations/Version*.php') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertStringContainsString("\nnamespace DoctrineMigrations;\n", (string) file_get_contents($file), basename($file));
        }
    }
}
