<?php

declare(strict_types=1);

namespace Nordvarr\View;

use DateTimeImmutable;
use DateTimeZone;
use Nordvarr\I18n;
use RuntimeException;

/**
 * Vykreslování PHP šablon. Veškerý výstup do HTML jde přes e() nebo t(),
 * které escapují. Neescapovaný výstup jen přes raw() u už hotového HTML.
 */
final class View
{
    /** @var array<string, mixed> společná data pro layout */
    private array $shared = [];

    public function __construct(
        private readonly string $templateDir,
        private readonly I18n $i18n,
        private readonly UrlGenerator $urls,
        private readonly string $csrfToken = '',
        private readonly string $timezone = 'Europe/Prague',
    ) {
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public function e(mixed $value): string
    {
        return self::escape($value);
    }

    /**
     * @param array<string, string|int> $params
     */
    public function t(string $key, array $params = []): string
    {
        return self::escape($this->i18n->t($key, $params));
    }

    public function lang(): string
    {
        return $this->i18n->lang();
    }

    /**
     * Escapovaná URL routy aplikace.
     *
     * @param array<string, string> $query
     */
    public function url(string $path, array $query = []): string
    {
        return self::escape($this->urls->route($path, $query));
    }

    public function asset(string $path): string
    {
        return self::escape($this->urls->asset($path));
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::escape($this->csrfToken) . '">';
    }

    public function time(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $time = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($this->timezone));

        return self::escape($time->format('j. n. Y H:i'));
    }

    /**
     * Doba od daného času ("teď", "3 min", "5 h", "2 d").
     */
    public function ago(?string $utc, ?int $now = null): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $seconds = max(0, ($now ?? time()) - (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp());

        return match (true) {
            $seconds < 60 => $this->t('time.now'),
            $seconds < 3600 => $this->t('time.min', ['n' => intdiv($seconds, 60)]),
            $seconds < 172800 => $this->t('time.h', ['n' => intdiv($seconds, 3600)]),
            default => $this->t('time.d', ['n' => intdiv($seconds, 86400)]),
        };
    }

    public function raw(string $html): string
    {
        return $html;
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function shared(string $key, mixed $default = null): mixed
    {
        return $this->shared[$key] ?? $default;
    }

    /**
     * Vykreslí šablonu do řetězce.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->templateDir . '/' . $template . '.php';
        if (preg_match('/^[a-z0-9_\/-]+$/', $template) !== 1 || !is_file($file)) {
            throw new RuntimeException('Šablona neexistuje: ' . $template);
        }
        $v = $this;
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Vykreslí šablonu vloženou do layoutu.
     *
     * @param array<string, mixed> $data
     */
    public function page(string $template, array $data = [], string $layout = 'layout'): string
    {
        $content = $this->render($template, $data);

        return $this->render($layout, ['content' => $content, 'title' => $data['title'] ?? '']);
    }
}
