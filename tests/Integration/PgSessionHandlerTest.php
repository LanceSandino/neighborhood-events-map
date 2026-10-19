<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\Session\PgSessionHandler;
use App\Tests\Support\AppTestCase;

/**
 * Traceability: SESSION_AND_AUTH_LIFECYCLE #7 — sessions are durable in PostgreSQL across app restarts
 * (a new handler/connection reads them back) and are destroyed server-side on logout.
 */
final class PgSessionHandlerTest extends AppTestCase
{
    public function testDurableAcrossHandlerInstancesAndDestroyable(): void
    {
        $id = bin2hex(random_bytes(24));
        $a = new PgSessionHandler($this->db());
        self::assertTrue($a->open('', 'nhm_session'));
        self::assertSame('', $a->read($id));
        self::assertTrue($a->write($id, '_nhm|a:1:{s:4:"user";i:42;}'));
        self::assertTrue($a->close());

        $b = new PgSessionHandler($this->db()); // simulates a fresh PHP worker / container restart
        $b->open('', 'nhm_session');
        self::assertSame('_nhm|a:1:{s:4:"user";i:42;}', $b->read($id));
        self::assertTrue($b->updateTimestamp($id, '_nhm|a:1:{s:4:"user";i:42;}'));
        self::assertTrue($b->validateId($id));
        self::assertTrue($b->destroy($id));
        $b->close();

        $c = new PgSessionHandler($this->db());
        $c->open('', 'nhm_session');
        self::assertSame('', $c->read($id), 'destroyed session cannot be revived');
        self::assertFalse($c->validateId($id));
        self::assertIsInt($c->gc(1));
        $c->close();
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM sessions WHERE sess_id = ?', [$id]));
    }
}
