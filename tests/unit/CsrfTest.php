<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Http\Session;
use Nordvarr\Security\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testTokenIsStableAndValidated(): void
    {
        $csrf = new Csrf(Session::inMemory());
        $token = $csrf->token();
        self::assertSame(64, strlen($token));
        self::assertSame($token, $csrf->token());
        self::assertTrue($csrf->isValid($token));
        self::assertFalse($csrf->isValid('x' . $token));
        self::assertFalse($csrf->isValid(null));
        self::assertFalse($csrf->isValid(['pole']));
    }

    public function testMissingSessionTokenRejectsEverything(): void
    {
        $csrf = new Csrf(Session::inMemory());
        self::assertFalse($csrf->isValid(''));
    }

    public function testRotateChangesToken(): void
    {
        $csrf = new Csrf(Session::inMemory());
        $old = $csrf->token();
        $csrf->rotate();
        self::assertNotSame($old, $csrf->token());
        self::assertFalse($csrf->isValid($old));
    }
}
