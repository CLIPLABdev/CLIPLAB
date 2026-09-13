<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Exceptions\MarketingContentValidationException;
use App\Repositories\AdminRepository;
use App\Services\MarketingContentService;
use Throwable;

final class MarketingContentController
{
    public function __construct(
        private View $view,
        private AdminRepository $admins,
        private MarketingContentService $content
    ) {
    }

    public function edit(Request $request): Response
    {
        return $this->render($this->content->testimonialsForAdmin(), Session::pull('admin_flash'));
    }

    public function save(Request $request): Response
    {
        $testimonials = $request->input('testimonials', []);
        try {
            if (!is_array($testimonials)) {
                throw new MarketingContentValidationException('Revise os dados enviados.');
            }
            $this->content->saveTestimonials($this->actorId(), $testimonials);
            Session::flash('admin_flash', ['type' => 'success', 'message' => 'Conteúdo público atualizado.']);
        } catch (MarketingContentValidationException $exception) {
            $errors = [];
            if ($exception->record() !== null && $exception->field() !== null) {
                $errors[$exception->record() . '.' . $exception->field()] = $exception->getMessage();
            }

            return $this->render(
                $this->boundedDraft(is_array($testimonials) ? $testimonials : []),
                ['type' => 'error', 'message' => 'O conteúdo não foi salvo. Corrija o campo indicado.'],
                $errors,
                422
            );
        } catch (Throwable) {
            Session::flash('admin_flash', ['type' => 'error', 'message' => 'Não foi possível salvar. Revise os campos e confirme a autorização.']);
        }

        return Response::redirect('/admin/conteudo');
    }

    /** @param list<array<string,mixed>> $testimonials @param array<string,string>|null $flash @param array<string,string> $errors */
    private function render(array $testimonials, ?array $flash = null, array $errors = [], int $status = 200): Response
    {
        $admin = $this->admins->findIdentity($this->actorId());
        if (!is_array($admin)) {
            return Response::redirect('/login');
        }
        $response = $this->view->render('admin.content', [
            'title' => 'Conteúdo público',
            'admin' => $admin,
            'testimonials' => $testimonials,
            'flash' => $flash,
            'errors' => $errors,
        ]);

        return $status === 200 ? $response : Response::html($response->body(), $status);
    }

    /** @param array<int,mixed> $input @return list<array<string,mixed>> */
    private function boundedDraft(array $input): array
    {
        $limits = ['name' => 100, 'context' => 160, 'quote' => 600, 'result' => 160, 'source_url' => 255];
        $draft = [];
        foreach (array_slice(array_values($input), 0, 3) as $index => $item) {
            if (!is_array($item)) {
                $item = [];
            }
            $record = ['slot' => $index + 1];
            foreach ($limits as $field => $limit) {
                $value = is_string($item[$field] ?? null) ? $item[$field] : '';
                $record[$field] = mb_substr($value, 0, $limit);
            }
            $record['authorization_confirmed'] = $this->checked($item['authorization_confirmed'] ?? false);
            $record['published'] = $this->checked($item['published'] ?? false);
            $draft[] = $record;
        }

        return $draft;
    }

    private function checked(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private function actorId(): int
    {
        $id = (int) Session::get('user_id', 0);
        if ($id < 1) {
            throw new \DomainException('Administrator session is invalid.');
        }

        return $id;
    }
}
