<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\DemoSeedCommand;
use App\Community\CommunityBootstrapper;
use App\Shared\RuntimeConfig;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @module tests/Integration/GoldenApiParityTest
 * @description Halloween parity for the event-type extraction (ROADMAP Stage 1). tests/fixtures/golden/0.9.0-beta.1
 *   holds the public API answers of the two development stacks running 0.9.0-beta.1 (captured on fresh stacks,
 *   before any change): the fictional Maple Hollow demo with its seeded homes and the Ventura Reserve example
 *   (no residents, boundary not approved). This test rebuilds the same communities the same way (bootstrap
 *   command, demo seed) and requires every key of the baseline, with the same value and in the same relative
 *   order; keys may only be added. Environment values are
 *   compared by kind only: the app version, the APP_DEMO switch and each home's updated_at (seed time).
 * @dependencies tests/Support/AppTestCase, Community/CommunityBootstrapper, Command/DemoSeedCommand
 */
final class GoldenApiParityTest extends AppTestCase
{
    private const GOLDEN = __DIR__.'/../fixtures/golden/0.9.0-beta.1';

    public function testTheDemoCommunityAnswersExactlyLikeTheBaseline(): void
    {
        $this->bootstrap('examples/demo/community.yaml', true);
        $this->seedDemoHomes();
        foreach (['event' => '/api/event', 'community' => '/api/community', 'homes' => '/api/homes', 'home-3' => '/api/homes/3'] as $name => $uri) {
            $actual = $this->json('GET', $uri);
            self::assertSame(200, $this->code(), $uri);
            self::assertParity(self::golden('demo-'.$name), $actual, $uri);
        }
    }

    public function testTheVenturaExampleAnswersExactlyLikeTheBaseline(): void
    {
        $this->bootstrap('examples/ventura-reserve/community.yaml', false);
        foreach (['event' => '/api/event', 'community' => '/api/community', 'homes' => '/api/homes'] as $name => $uri) {
            $actual = $this->json('GET', $uri);
            self::assertSame(200, $this->code(), $uri);
            self::assertParity(self::golden('ventura-'.$name), $actual, $uri);
        }
        self::assertParity(self::golden('ventura-home-1'), $this->json('GET', '/api/homes/1'), '/api/homes/1');
        self::assertSame(404, $this->code());
    }

    private function bootstrap(string $file, bool $approve): void
    {
        $result = static::getContainer()->get(CommunityBootstrapper::class)->bootstrap($file, false, $approve);
        self::assertSame('imported', $result['status']);
        $this->repo()->reset();
    }

    /** app:demo:seed exactly as the demo stack runs it, with APP_DEMO=1 for this command only. */
    private function seedDemoHomes(): void
    {
        $demo = new RuntimeConfig('http://localhost', 'Demo <demo@example.test>', 'demo@example.test', str_repeat('s', 32), '', '', 8, 120, [], 'none', '', '', true);
        $ctor = (new \ReflectionClass(DemoSeedCommand::class))->getConstructor();
        \assert(null !== $ctor);
        $args = array_map(static function (\ReflectionParameter $p) use ($demo): object {
            $type = $p->getType();
            \assert($type instanceof \ReflectionNamedType);

            return RuntimeConfig::class === $type->getName() ? $demo : static::getContainer()->get($type->getName());
        }, $ctor->getParameters());
        $tester = new CommandTester((new \ReflectionClass(DemoSeedCommand::class))->newInstanceArgs($args));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('Seeded 14 FICTIONAL demo homes.', $tester->getDisplay());
        $this->repo()->reset();
    }

    /** @return array<mixed> */
    private static function golden(string $name): array
    {
        return json_decode((string) file_get_contents(self::GOLDEN.'/'.$name.'.json'), true, 64, \JSON_THROW_ON_ERROR);
    }

    /**
     * Every baseline key is present with the same value, in the same relative order; lists keep their length.
     *
     * @param array<mixed> $expected
     * @param array<mixed> $actual
     */
    private static function assertParity(array $expected, array $actual, string $path): void
    {
        if (array_is_list($expected) && [] !== $expected) {
            self::assertTrue(array_is_list($actual), "$path is a list");
            self::assertCount(\count($expected), $actual, "$path keeps its length");
        }
        $positions = [];
        foreach ($expected as $key => $value) {
            $at = $path.'.'.$key;
            self::assertArrayHasKey($key, $actual, "$at is still there");
            $positions[] = array_search($key, array_keys($actual), true);
            if (self::isVolatile((string) $key)) {
                self::assertSame(get_debug_type($value), get_debug_type($actual[$key]), "$at keeps its type");
                if ('updated_at' === $key) {
                    self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, (string) $actual[$key]), "$at is an ATOM time");
                }
                continue;
            }
            if (\is_array($value)) {
                self::assertIsArray($actual[$key], "$at is still a list or object");
                self::assertParity($value, $actual[$key], $at);
            } else {
                self::assertSame($value, $actual[$key], $at);
            }
        }
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, "$path keeps the order of its existing keys");
    }

    /** Values that depend on the environment or the moment, not on the code: the release, APP_DEMO and seed times. */
    private static function isVolatile(string $key): bool
    {
        return \in_array($key, ['version', 'demo', 'updated_at'], true);
    }
}
