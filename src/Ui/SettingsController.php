<?php

declare(strict_types=1);

namespace Nordvarr\Ui;

use InvalidArgumentException;
use JsonException;
use Nordvarr\Alerts\Notifier;
use Nordvarr\Api\ApiTokens;
use Nordvarr\Db\Migrator;
use Nordvarr\Http\Response;
use Nordvarr\Http\Router;
use Nordvarr\I18n;
use Nordvarr\Installer;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Inventory\Transfer;
use Nordvarr\Inventory\ValidationException;
use Nordvarr\Repository\ApiTokenRepository;
use Nordvarr\Repository\ChannelRepository;
use Nordvarr\Repository\SettingsRepository;

/**
 * Nastavení: časová zóna, API tokeny, export a import inventáře, řádek pro cron.
 */
final class SettingsController
{
    public const MAX_IMPORT = 20971520;

    public function __construct(private readonly Ctx $ctx, private readonly string $appRoot)
    {
    }

    public function register(Router $router): void
    {
        $router->get('/settings', fn (): Response => $this->index());
        $router->post('/settings/timezone', fn (): Response => $this->timezone());
        $router->post('/settings/notifications', fn (): Response => $this->notifications());
        $router->post('/settings/tokens', fn (): Response => $this->createToken());
        $router->post('/settings/tokens/{id}/revoke', fn (array $p): Response => $this->revokeToken($p['id']));
        $router->get('/settings/export', fn (): Response => $this->export());
        $router->post('/settings/import', fn (): Response => $this->import());
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function index(array $extra = [], int $status = 200): Response
    {
        $settings = new SettingsRepository($this->ctx->pdo);

        return $this->ctx->page('settings', $extra + [
            'title' => 'nav.settings',
            'timezone' => $settings->get('timezone', 'Europe/Prague'),
            'timezones' => timezone_identifiers_list(),
            'tokens' => (new ApiTokenRepository($this->ctx->pdo))->all(),
            'channels' => (new ChannelRepository($this->ctx->pdo))->all(),
            'reminderHours' => (int) ($settings->get('alert_reminder_hours') ?? Notifier::DEFAULT_REMINDER_HOURS),
            'baseUrl' => (string) $settings->get('base_url', ''),
            'notificationLang' => I18n::resolve($settings->get('notification_lang')),
            'scopes' => ApiTokens::SCOPES,
            'cronLine' => (new Installer($this->appRoot))->cronLine(),
            'newToken' => null,
            'tokenError' => null,
            'tokenValues' => ['name' => '', 'source' => '', 'scopes' => ['inventory:write', 'events:write']],
        ], $status);
    }

    private function timezone(): Response
    {
        $tz = $this->ctx->request->input('timezone');
        if (!in_array($tz, timezone_identifiers_list(), true)) {
            $this->ctx->flash('err.invalid_timezone');

            return $this->ctx->redirect('/settings');
        }
        $settings = new SettingsRepository($this->ctx->pdo);
        $old = $settings->get('timezone');
        $settings->set('timezone', $tz);
        $this->ctx->audit('settings.timezone', ['old' => $old, 'new' => $tz]);
        $this->ctx->flash('settings.saved');

        return $this->ctx->redirect('/settings');
    }

    private function notifications(): Response
    {
        $r = $this->ctx->request;
        $hours = $r->input('reminder_hours');
        $baseUrl = rtrim(trim($r->input('base_url')), '/');
        $parts = parse_url($baseUrl);
        if (preg_match('/^\d{1,4}$/', $hours) !== 1 || (int) $hours > 720) {
            $this->ctx->flash('err.invalid_reminder');

            return $this->ctx->redirect('/settings');
        }
        if ($baseUrl !== '' && (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ($parts['host'] ?? '') === '')) {
            $this->ctx->flash('err.invalid_url');

            return $this->ctx->redirect('/settings');
        }
        $settings = new SettingsRepository($this->ctx->pdo);
        $new = ['alert_reminder_hours' => (string) (int) $hours, 'base_url' => $baseUrl, 'notification_lang' => I18n::resolve($r->input('notification_lang'))];
        $old = [];
        foreach ($new as $key => $value) {
            $old[$key] = $settings->get($key);
            $settings->set($key, $value);
        }
        $this->ctx->audit('settings.notifications', ['old' => $old, 'new' => $new]);
        $this->ctx->flash('settings.saved');

        return $this->ctx->redirect('/settings');
    }

    private function createToken(): Response
    {
        $r = $this->ctx->request;
        $scopes = $r->post['scopes'] ?? [];
        $scopes = is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
        $values = ['name' => trim($r->input('name')), 'source' => trim($r->input('source')), 'scopes' => $scopes];
        $tokens = new ApiTokens(new ApiTokenRepository($this->ctx->pdo), $this->ctx->config->appSecret());
        try {
            $issued = $tokens->issue($values['name'], $values['source'], $scopes, $this->ctx->userId());
        } catch (InvalidArgumentException $e) {
            return $this->index(['tokenError' => $e->getMessage(), 'tokenValues' => $values], 422);
        }
        $this->ctx->audit('token.create', ['id' => $issued['id'], 'name' => $values['name'], 'source' => $values['source'], 'scopes' => $scopes]);

        // Token se zobrazí jen v této odpovědi, nikam se neukládá v čitelné podobě.
        $response = $this->index(['newToken' => $issued['token']]);
        $response->headers['Cache-Control'] = 'no-store';

        return $response;
    }

    private function revokeToken(string $id): Response
    {
        $repo = new ApiTokenRepository($this->ctx->pdo);
        $token = $repo->find($id);
        if ($token === null) {
            return $this->ctx->notFound();
        }
        if ($repo->revoke($id)) {
            $this->ctx->audit('token.revoke', ['id' => $id, 'name' => $token['name']]);
        }
        $this->ctx->flash('settings.token_revoked', ['name' => (string) $token['name']]);

        return $this->ctx->redirect('/settings');
    }

    private function export(): Response
    {
        $data = (new Transfer(new Inventory($this->ctx->pdo)))->export();
        $this->ctx->audit('inventory.export', ['objects' => count($data['objects'])]);
        $body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $name = 'nordvarr-inventar-' . gmdate('Ymd-His') . '.json';

        return new Response($body . "\n", 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function import(): Response
    {
        $r = $this->ctx->request;
        if ($r->input('confirm') !== '1') {
            $this->ctx->flash('settings.import_confirm_missing');

            return $this->ctx->redirect('/settings');
        }
        $file = $r->uploadedFile('file');
        if ($file === null || filesize($file) > self::MAX_IMPORT) {
            $this->ctx->flash('err.invalid_file');

            return $this->ctx->redirect('/settings');
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->ctx->flash('err.invalid_format');

            return $this->ctx->redirect('/settings');
        }
        if (!is_array($data)) {
            $this->ctx->flash('err.invalid_format');

            return $this->ctx->redirect('/settings');
        }

        // Před nahrazením inventáře záloha databáze.
        $backupDir = $this->ctx->config->dataDir() . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }
        $backup = $backupDir . '/nordvarr-' . gmdate('Ymd-His') . '-pred-importem-' . bin2hex(random_bytes(3)) . '.sqlite';
        (new Migrator($this->ctx->pdo, $this->appRoot . '/migrations'))->backup($backup);

        try {
            $counts = (new Transfer(new Inventory($this->ctx->pdo)))->import($data);
        } catch (ValidationException $e) {
            $this->ctx->flash('err.' . $e->firstError());

            return $this->ctx->redirect('/settings');
        }
        $this->ctx->audit('inventory.import', $counts + ['backup' => basename($backup)]);
        $this->ctx->flash('settings.imported', $counts + ['backup' => basename($backup)]);

        return $this->ctx->redirect('/settings');
    }
}
