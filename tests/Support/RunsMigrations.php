<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @module tests/Support/RunsMigrations
 * @description Runs `doctrine:migrations:migrate <version>` on the test database in a kernel of its own
 *   (Doctrine Migrations freezes its plan after one run), for tests that go down and up again.
 * @dependencies App\Kernel, Symfony Console
 */
trait RunsMigrations
{
    private function migrateTo(string $version): void
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        try {
            $app = new Application($kernel);
            $app->setAutoExit(false);
            $out = new BufferedOutput();
            $code = $app->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', 'version' => $version, '--no-interaction' => true, '--allow-no-migration' => true]), $out);
            self::assertSame(0, $code, $out->fetch());
        } finally {
            $kernel->shutdown();
        }
    }
}
