<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesStaticAndParametrizedRoutes(): void
    {
        $router = new Router();
        $router->get('/', fn () => 'home');
        $router->get('/objects/{id}', fn () => 'object');

        $match = $router->match('GET', '/objects/abc-1');
        self::assertSame(200, $match['status']);
        self::assertSame(['id' => 'abc-1'], $match['params']);
        self::assertSame('object', ($match['handler'])());
        self::assertSame(200, $router->match('GET', '/')['status']);
    }

    public function testHeadFallsBackToGet(): void
    {
        $router = new Router();
        $router->get('/login', fn () => 'x');
        self::assertSame(200, $router->match('HEAD', '/login')['status']);
    }

    public function testNotFoundAndMethodNotAllowed(): void
    {
        $router = new Router();
        $router->post('/logout', fn () => 'x');
        self::assertSame(404, $router->match('GET', '/nope')['status']);
        $match = $router->match('GET', '/logout');
        self::assertSame(405, $match['status']);
        self::assertSame(['POST'], $match['allowed']);
    }

    public function testParameterDoesNotCrossSlash(): void
    {
        $router = new Router();
        $router->get('/objects/{id}', fn () => 'x');
        self::assertSame(404, $router->match('GET', '/objects/a/b')['status']);
    }
}
