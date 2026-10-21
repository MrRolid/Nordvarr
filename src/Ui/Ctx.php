<?php

declare(strict_types=1);

namespace Nordvarr\Ui;

use Nordvarr\Config;
use Nordvarr\Http\Request;
use Nordvarr\Http\Response;
use Nordvarr\Http\Session;
use Nordvarr\Repository\AuditLogRepository;
use Nordvarr\View\UrlGenerator;
use Nordvarr\View\View;
use PDO;

/**
 * Společný kontext požadavku pro kontrolery UI (jen přihlášený uživatel).
 */
final class Ctx
{
    /**
     * @param array<string, mixed> $user
     */
    public function __construct(
        public readonly Request $request,
        public readonly Session $session,
        public readonly View $view,
        public readonly UrlGenerator $urls,
        public readonly PDO $pdo,
        public readonly array $user,
        public readonly AuditLogRepository $audit,
        public readonly Config $config,
    ) {
    }

    /**
     * Objekty v údržbě s pozastavenými kontrolami (jejich stav je paused).
     *
     * @return array<string, true>
     */
    public function pausedObjects(): array
    {
        return \Nordvarr\Alerts\MaintenanceState::load($this->pdo, \Nordvarr\Time::toDb(\Nordvarr\Time::now()))->pausedObjects();
    }

    public function userId(): string
    {
        return (string) $this->user['id'];
    }

    public function userEmail(): string
    {
        return (string) $this->user['email'];
    }

    /**
     * @param array<string, string> $query
     */
    public function redirect(string $path, array $query = []): Response
    {
        return Response::redirect($this->urls->route($path, $query));
    }

    /**
     * Jednorázová zpráva zobrazená po přesměrování.
     *
     * @param array<string, string|int> $params
     */
    public function flash(string $key, array $params = []): void
    {
        $this->session->set('flash', [$key, $params]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function page(string $template, array $data, int $status = 200): Response
    {
        return new Response($this->view->page($template, $data), $status);
    }

    public function notFound(): Response
    {
        return $this->page('error', ['title' => 'error.title', 'message' => 'error.not_found'], 404);
    }

    /**
     * @param array<string, mixed> $details
     */
    public function audit(string $action, array $details = []): void
    {
        $this->audit->log($action, $this->userId(), $this->request->ip, $details);
    }
}
