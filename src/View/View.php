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

    /**
     * Číslo s desetinnou čárkou (česky) nebo tečkou (anglicky); null jako "-".
     */
    public function num(?float $value, int $decimals = 0): string
    {
        if ($value === null) {
            return '-';
        }

        return self::escape(number_format($value, $decimals, $this->lang() === 'cs' ? ',' : '.', ''));
    }

    /**
     * Velikost v bajtech v binárních jednotkách (KiB, MiB, GiB, TiB).
     */
    public function bytes(int|float|null $bytes): string
    {
        if ($bytes === null) {
            return '-';
        }
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return $this->num($value, $value >= 100 || $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }

    /**
     * Doba běhu ze sekund: "12 d 3 h", "5 h 20 min", "4 min".
     */
    public function uptime(?int $seconds): string
    {
        if ($seconds === null) {
            return '-';
        }
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);

        return match (true) {
            $d > 0 => $this->t('time.d', ['n' => $d]) . ' ' . $this->t('time.h', ['n' => $h]),
            $h > 0 => $this->t('time.h', ['n' => $h]) . ' ' . $this->t('time.min', ['n' => $m]),
            default => $this->t('time.min', ['n' => $m]),
        };
    }

    /**
     * Délka mezi dvěma časy (do teď, když konec chybí), např. "3 min", "5 h", "2 d".
     */
    public function duration(string $fromUtc, ?string $toUtc): string
    {
        $end = $toUtc === null ? time() : (new DateTimeImmutable($toUtc, new DateTimeZone('UTC')))->getTimestamp();
        if ($end - (new DateTimeImmutable($fromUtc, new DateTimeZone('UTC')))->getTimestamp() < 60) {
            return $this->t('time.under_min');
        }

        return $this->ago($fromUtc, $end);
    }

    /**
     * Odkaz na webovou adresu (jen http a https) do nové karty, jinak jen escapovaný text.
     * $label je text odkazu (výchozí je adresa).
     */
    public function webLink(string $url, ?string $label = null): string
    {
        $url = trim($url);
        $label = self::escape($label ?? $url);
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === ''
            || preg_match('/[\s"<>]/', $url) === 1) {
            return $label;
        }

        return '<a href="' . self::escape($url) . '" target="_blank" rel="noopener noreferrer" class="web-link">' . $label
            . '<svg class="web-link-icon" viewBox="0 0 12 12" width="11" height="11" aria-hidden="true" focusable="false">'
            . '<path d="M4 2h6v6M10 2 3 9"/></svg></a>';
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
