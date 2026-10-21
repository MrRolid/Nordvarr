<?php

declare(strict_types=1);

namespace Nordvarr\Http;

/**
 * HTTP odpověď. Bezpečnostní hlavičky se přidávají ke každé odpovědi.
 */
final class Response
{
    public const SECURITY_HEADERS = [
        'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
            . "connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'same-origin',
        'X-Content-Type-Options' => 'nosniff',
    ];

    /** @var list<array{name: string, value: string, options: array<string, mixed>}> */
    public array $cookies = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
        $this->headers += ['Content-Type' => 'text/html; charset=utf-8'];
        $this->headers += self::SECURITY_HEADERS;
    }

    public static function redirect(string $url, int $status = 303): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withCookie(string $name, string $value, array $options): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return $this;
    }

    public function send(): void
    {
        header_remove('X-Powered-By');
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        foreach ($this->cookies as $cookie) {
            setcookie($cookie['name'], $cookie['value'], $cookie['options']);
        }
        echo $this->body;
    }
}
