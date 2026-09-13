<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Csrf, Request, Response, Session, View};
use App\Plans\PlanLimitExceeded;
use App\Services\EditorLibraryService;

final class EditorLibraryController
{
    private ?\Closure $user;
    private ?\Closure $rate;
    private \Closure $uploads;
    public function __construct(private View $view, private EditorLibraryService $service, ?callable $user = null, ?callable $rate = null, ?callable $uploads = null)
    {
        $this->user = $user === null ? null : \Closure::fromCallable($user);
        $this->rate = $rate === null ? null : \Closure::fromCallable($rate);
        $this->uploads = $uploads === null ? static fn (): array => is_array($_FILES['logo'] ?? null) ? $_FILES['logo'] : [] : \Closure::fromCallable($uploads);
    }
    public function catalog(Request $request): Response
    {
        $id = (int) Session::get('user_id', 0);
        return $this->private($id > 0 ? Response::json($this->service->catalog($id)) : Response::json(['error' => 'unauthenticated'], 401));
    }
    public function templates(Request $request): Response { return $this->page($request, 'templates'); }
    public function brand(Request $request): Response { return $this->page($request, 'brand'); }

    public function storeTemplates(Request $request): Response
    {
        return $this->mutate($request, 'templates', function (int $userId) use ($request): void {
            $action = $request->input('action', 'save');
            if ($action === 'remove') $this->service->removeTemplate($userId, $request->input('id'));
            elseif ($action === 'apply') $this->service->applyTemplate($userId, $request->input('id'));
            elseif ($action === 'save') $this->service->saveTemplate($userId, $this->input($request));
            else throw new \InvalidArgumentException('Ação inválida.');
        });
    }
    public function storeBrand(Request $request): Response
    {
        return $this->mutate($request, 'brand', fn (int $userId) => $this->service->saveKit($userId, $this->input($request)));
    }
    public function uploadLogo(Request $request): Response
    {
        return $this->mutate($request, 'brand', fn (int $userId) => $this->service->uploadLogo($userId, ($this->uploads)()));
    }
    public function logo(Request $request, array $parameters): Response
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId < 1) return $this->private(Response::redirect('/login'));
        try { $id = EditorLibraryService::positiveId($parameters['id'] ?? null); }
        catch (\InvalidArgumentException) { return $this->notFound(); }
        $logo = $this->service->logo($id, $userId);
        if ($logo === null) return $this->notFound();
        return $this->private((new Response($logo['bytes']))->withHeader('Content-Type', 'image/png')
            ->withHeader('Content-Length', (string) $logo['size_bytes'])->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', 'inline; filename="logo.png"'));
    }

    private function mutate(Request $request, string $page, callable $operation): Response
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId < 1) return $this->private(Response::redirect('/login'));
        $token = $request->input('_token');
        if (!Csrf::validate(is_string($token) ? $token : null)) return $this->private(Response::text('Sessão expirada. Atualize a página.', 419));
        if ($this->rate !== null && !($this->rate)($userId)) return $this->page($request, $page, 'Muitas alterações. Aguarde um minuto e tente novamente.', 429)->withHeader('Retry-After', '60');
        try { $operation($userId); }
        catch (\OutOfBoundsException) { return $this->notFound(); }
        catch (\InvalidArgumentException | \DomainException | PlanLimitExceeded $error) { return $this->page($request, $page, $error->getMessage(), 422); }
        Session::flash('editor_library_notice', $request->input('action') === 'apply' ? 'Template copiado para os padrões da marca. Os cortes existentes não mudam.' : 'Alterações salvas.');
        return $this->private(Response::redirect($page === 'templates' ? '/templates' : '/marca'));
    }
    private function page(Request $request, string $page, ?string $error = null, int $status = 200): Response
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId < 1) return $this->private(Response::redirect('/login'));
        $catalog = $this->service->catalog($userId); $editing = null;
        $recover = $request->isMethod('POST') && $status >= 400;
        $form = $catalog['kit'];
        if ($page === 'templates') {
            $form = ['options' => $catalog['presets'][2]['options'], 'aspect_ratio' => '9:16', 'name' => '', 'category' => 'custom'];
            $editId = $recover ? $request->input('id') : $request->query('edit');
            if ($editId !== null && $editId !== '') {
                try { $id = EditorLibraryService::positiveId($editId); } catch (\InvalidArgumentException) { return $this->notFound(); }
                foreach ($catalog['templates'] as $template) if ($template['id'] === $id) $editing = $template;
                if ($editing === null) return $this->notFound();
                $form = $editing;
            } elseif (is_string($request->query('preset'))) {
                foreach ($catalog['presets'] as $preset) if ($preset['id'] === $request->query('preset')) $form = $preset;
                unset($form['id']);
            }
        }
        if ($recover && (($page === 'templates' && $request->input('action', 'save') === 'save') || ($page === 'brand' && $request->path() === '/marca'))) {
            foreach (['name', 'category', 'aspect_ratio'] as $key) {
                if (array_key_exists($key, $form) && $request->hasInput($key)) $form[$key] = $this->draftScalar($request->input($key), $form[$key]);
            }
            $submittedOptions = $request->input('options', []);
            if (is_array($submittedOptions)) foreach ($form['options'] as $key => $value) {
                if (array_key_exists($key, $submittedOptions)) $form['options'][$key] = $this->draftScalar($submittedOptions[$key], (string) $value);
            }
            if ($page === 'brand') {
                $favorites = $request->input('favorites', []);
                $form['favorites'] = [];
                if (is_array($favorites)) foreach (array_slice($favorites, 0, 50) as $favorite) {
                    try { $favorite = EditorLibraryService::positiveId($favorite); } catch (\InvalidArgumentException) { continue; }
                    if (in_array($favorite, array_column($catalog['templates'], 'id'), true)) $form['favorites'][] = $favorite;
                }
            }
        }
        $user = $this->user === null ? [] : ($this->user)($userId);
        $user = array_replace(['id' => $userId, 'name' => 'Conta', 'email' => '', 'credits' => 0, 'plan_name' => 'Plano', 'monthly_minutes' => 0], is_array($user) ? $user : []);
        $body = $this->view->render('editor-library.' . $page, ['title' => $page === 'templates' ? 'Templates' : 'Minha marca', 'user' => $user,
            'catalog' => $catalog, 'form' => $form, 'editing' => $editing, 'error' => $error, 'notice' => Session::pull('editor_library_notice')])->body();
        return $this->private(Response::html($body, $status));
    }
    private function input(Request $request): array
    {
        $input = [];
        foreach (['id', 'name', 'category', 'aspect_ratio', 'options', 'favorites'] as $key) if ($request->hasInput($key)) $input[$key] = $request->input($key);
        return $input;
    }
    /** Display-only recovery: never pass these unvalidated values to persistence. */
    private function draftScalar(mixed $value, string $fallback): string
    {
        if (is_int($value)) $value = (string) $value;
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) return $fallback;
        return str_replace("\0", '', mb_strcut($value, 0, 8192, 'UTF-8'));
    }
    private function private(Response $response): Response { return $response->withHeader('Cache-Control', 'private, no-store'); }
    private function notFound(): Response { return $this->private(Response::text('Não encontrado.', 404)); }
}
