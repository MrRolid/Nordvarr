<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Http\Request;
use Nordvarr\Http\Response;
use Nordvarr\Http\Session;
use Nordvarr\InstallController;
use Nordvarr\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class InstallControllerTest extends TestCase
{
    private string $tmp;
    private string $app;
    private Session $session;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create();
        $this->app = TempDir::copyApp($this->tmp);
        $this->session = Session::inMemory();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    /**
     * @param array<string, string> $post
     */
    private function request(string $method, array $post = [], string $docRoot = ''): Response
    {
        $controller = new InstallController($this->app, $docRoot, false);

        return $controller->handle(new Request($method, '/', ['lang' => 'cs'], $post, [], ['host' => 'nordvarr.example.com']), $this->session);
    }

    private function token(): string
    {
        preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $this->request('GET')->body, $m);

        return $m[1];
    }

    /**
     * @return array<string, string>
     */
    private function form(array $override = []): array
    {
        return $override + [
            '_csrf' => $this->token(),
            'data_dir' => $this->tmp . '/nordvarr-data',
            'email' => 'admin@example.com',
            'password' => 'tajne-heslo-1',
            'password_confirm' => 'tajne-heslo-1',
            'timezone' => 'Europe/Prague',
        ];
    }

    public function testFormShowsRequirementsAndDefaultDataDir(): void
    {
        $response = $this->request('GET');
        self::assertSame(200, $response->status);
        self::assertStringContainsString('value="' . $this->tmp . '/nordvarr-data"', $response->body);
        self::assertStringContainsString('pdo_sqlite', $response->body);
        self::assertSame('DENY', $response->headers['X-Frame-Options']);
    }

    public function testCsrfIsRequired(): void
    {
        $response = $this->request('POST', $this->form(['_csrf' => 'spatny']));
        self::assertSame(403, $response->status);
        self::assertFileDoesNotExist($this->app . '/config.php');
    }

    public function testValidationErrorsAreShown(): void
    {
        $response = $this->request('POST', $this->form(['password_confirm' => 'jine-heslo-xyz']));
        self::assertSame(422, $response->status);
        self::assertStringContainsString('Hesla se neshodují.', $response->body);
        self::assertFileDoesNotExist($this->app . '/config.php');
    }

    public function testSuccessfulInstallShowsCronAndLocksInstaller(): void
    {
        $form = $this->form();
        $response = $this->request('POST', $form);
        self::assertSame(200, $response->status, $response->body);
        self::assertStringContainsString('data-test="install-done"', $response->body);
        self::assertStringContainsString(htmlspecialchars($this->app . '/bin/nordvarr.php worker'), $response->body);
        self::assertStringContainsString('Datový adresář leží mimo webroot.', $response->body);
        self::assertFileExists($this->app . '/config.php');

        $again = $this->request('GET');
        self::assertSame(403, $again->status);
        self::assertStringContainsString('data-test="install-locked"', $again->body);
        $post = $this->request('POST', $form);
        self::assertSame(403, $post->status);
    }

    public function testWarnsWhenDataDirIsInsideDocumentRoot(): void
    {
        mkdir($this->tmp . '/www');
        $response = $this->request('POST', $this->form(['data_dir' => $this->tmp . '/www/data']), $this->tmp . '/www');
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Datový adresář leží uvnitř webrootu', $response->body);
    }
}
