<?php

declare(strict_types=1);

namespace Nordvarr\Ui;

use Nordvarr\Export\LlmContext;
use Nordvarr\Http\Response;
use Nordvarr\Http\Router;
use Nordvarr\I18n;
use Nordvarr\Repository\SettingsRepository;

/**
 * Stránka "Zkopírovat pro LLM" u objektu: Markdown s tlačítkem Kopírovat.
 */
final class LlmExportController
{
    public function __construct(private readonly Ctx $ctx, private readonly string $appRoot)
    {
    }

    public function register(Router $router): void
    {
        $router->get('/objects/{id}/llm', fn (array $p): Response => $this->show($p['id']));
    }

    public static function context(\PDO $pdo, string $appRoot, string $lang): LlmContext
    {
        $tz = (string) ((new SettingsRepository($pdo))->get('timezone', 'Europe/Prague') ?? 'Europe/Prague');

        return new LlmContext($pdo, new I18n($appRoot . '/lang', $lang), in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC');
    }

    private function show(string $id): Response
    {
        $context = self::context($this->ctx->pdo, $this->appRoot, $this->ctx->view->lang());
        $data = $context->build($id);
        if ($data === null) {
            return $this->ctx->notFound();
        }
        $markdown = $context->markdown($data);

        return $this->ctx->page('object_llm', [
            'title' => 'llm.page_title',
            'object' => ['id' => $id, 'name' => $data['object']['name']],
            'markdown' => $markdown,
            'sizeKb' => number_format(strlen($markdown) / 1024, 1, ',', ''),
            'truncated' => $data['truncated'],
        ]);
    }
}
