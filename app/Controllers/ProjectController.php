<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Contracts\ProjectCreator;
use App\Core\Response;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Validation\ProjectValidator;
use App\Media\YoutubeUrlValidator;
use Throwable;

final class ProjectController
{
    /** @var callable(): ProjectCreator */
    private $creatorFactory;

    /** @var callable(int, int): array<int, array<string, mixed>> */
    private $projects;

    /** @var callable(int): array<string, mixed>|null */
    private $user;
    private ?\Closure $uploadLimitForUser;

    /** @param callable(int, int): array<int, array<string, mixed>> $projects @param callable(int): array<string, mixed>|null $user */
    public function __construct(
        private View $view,
        ProjectCreator|callable $creator,
        callable $projects,
        ?callable $user = null,
        private int $maxUploadBytes = 524288000,
        ?callable $uploadLimitForUser = null
    ) {
        $this->creatorFactory = $creator instanceof ProjectCreator ? static fn (): ProjectCreator => $creator : $creator;
        $this->projects = $projects;
        $this->user = $user;
        $this->uploadLimitForUser = $uploadLimitForUser === null ? null : \Closure::fromCallable($uploadLimitForUser);
    }

    public function index(): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        return $this->view->render('projects.index', [
            'title' => 'Projetos',
            'user' => $this->userData($userId),
            'projects' => ($this->projects)($userId, 24),
            'created' => Session::pull('project_created', false),
        ]);
    }

    public function create(): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $errors = Session::pull('project_errors', []);
        $old = Session::pull('project_old', []);

        return $this->view->render('projects.create', [
            'title' => 'Novo projeto',
            'user' => $this->userData($userId),
            'errors' => is_array($errors) ? $errors : [],
            'old' => is_array($old) ? $old : [],
            'idempotencyKey' => bin2hex(random_bytes(24)),
            'maxUploadBytes' => $this->uploadLimitForUser === null ? $this->maxUploadBytes
                : min($this->maxUploadBytes, max(1, (int) ($this->uploadLimitForUser)($userId))),
        ]);
    }

    public function store(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $sourceType = (string) $request->input('source_type', '');
        $input = [
            'name' => trim((string) $request->input('name', '')),
            'source_type' => $sourceType,
            'source_url' => trim((string) $request->input('source_url', '')),
            'youtube_rights_confirmed' => (string) $request->input('youtube_rights_confirmed', ''),
            'idempotency_key' => trim((string) $request->input('idempotency_key', '')),
        ];
        $files = $sourceType === 'upload' && isset($_FILES['video_file']) && is_array($_FILES['video_file'])
            ? ['video_file' => $_FILES['video_file']]
            : [];
        $errors = ProjectValidator::creation($input, $files);
        if ($request->hasInput('auto_render_requested')) {
            $preference = $request->input('auto_render_requested');
            if (!is_string($preference) || !in_array($preference, ['0','1'], true)) {
                $errors['form'] = 'Escolha uma opção válida de exportação automática.';
            } else {
                $input['auto_render_requested'] = $preference;
            }
        }

        if ($input['idempotency_key'] === '') {
            $errors['form'] = 'Atualize a página e tente novamente.';
        }

        if ($errors !== []) {
            return $this->redirectWithErrors($errors, $input);
        }

        try {
            if ($sourceType === 'upload') {
                $this->creator()->fromUpload($userId, $this->creatorInput($input), $files['video_file']);
            } else {
                $this->creator()->fromDirectUrl($userId, $this->creatorInput($input));
            }
        } catch (Throwable $exception) {
            return $this->redirectWithErrors(['form' => $this->publicMessage($exception)], $input);
        }

        Session::flash('project_created', true);

        return Response::redirect('/projetos?created=1');
    }

    private function creator(): ProjectCreator
    {
        return ($this->creatorFactory)();
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    private function creatorInput(array $input): array
    {
        $result = [
            'name' => (string) $input['name'],
            'source_type' => (string) $input['source_type'],
            'idempotency_key' => (string) $input['idempotency_key'],
        ];

        if ($input['source_type'] === 'direct_url') {
            $result['source_url'] = (string) $input['source_url'];
            $result['youtube_rights_confirmed'] = (string) $input['youtube_rights_confirmed'];
        }
        if (isset($input['auto_render_requested'])) {
            $result['auto_render_requested'] = $input['auto_render_requested'];
        }
        return $result;
    }

    /** @param array<string, string> $errors @param array<string, string> $input */
    private function redirectWithErrors(array $errors, array $input): Response
    {
        $old = ['name' => $input['name'], 'source_type' => $input['source_type']];
        if (isset($input['auto_render_requested'])) {
            $old['auto_render_requested'] = $input['auto_render_requested'];
        }
        if (($input['youtube_rights_confirmed'] ?? '') === '1') {
            $old['youtube_rights_confirmed'] = '1';
        }
        if ($this->safeToRepopulateUrl($input['source_url'])) {
            $old['source_url'] = $input['source_url'];
        }

        Session::flash('project_errors', $errors);
        Session::flash('project_old', $old);

        return Response::redirect('/projetos/novo');
    }

    private function safeToRepopulateUrl(string $url): bool
    {
        $parts = $url === '' ? [] : parse_url($url);

        $youtubeUrls = new YoutubeUrlValidator();
        if ($youtubeUrls->recognizes($url)) {
            try {
                return $youtubeUrls->validate($url)->url() === $url;
            } catch (\Throwable) {
                return false;
            }
        }

        return is_array($parts) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']);
    }

    private function publicMessage(Throwable $exception): string
    {
        $code = method_exists($exception, 'publicCode') ? (string) $exception->publicCode() : '';
        $messages = [
            'invalid_upload' => 'O arquivo enviado está vazio ou é inválido. Selecione um arquivo de vídeo válido e envie novamente.',
            'unsupported_extension' => 'Envie um arquivo MP4, MOV ou WEBM.',
            'invalid_media_container' => 'Não foi possível validar esse arquivo de vídeo.',
            'upload_too_large' => 'O arquivo ultrapassa o limite permitido.',
            'upload_limit_exceeded' => 'O arquivo ultrapassa o limite permitido pelo seu plano.',
            'storage_limit_exceeded' => 'O armazenamento do seu plano está esgotado.',
            'account_inactive' => 'Sua conta ou plano está inativo. Entre em contato com a administração.',
            'unsafe_source_url' => 'Informe uma URL HTTPS direta e acessível publicamente.',
            'url_import_unavailable' => 'A importação por URL está indisponível no momento. Envie o arquivo diretamente.',
            'unsupported_youtube_url' => 'Use o link de um único vídeo público do YouTube, sem playlist.',
            'youtube_video_unavailable' => 'Não foi possível importar este vídeo público do YouTube. Tente outro vídeo ou envie o arquivo MP4.',
            'youtube_rights_required' => 'Confirme que você tem autorização para importar este vídeo do YouTube.',
            'youtube_import_unavailable' => 'A importação do YouTube está indisponível no momento. Envie o arquivo MP4.',
        ];

        return $messages[$code] ?? 'Não foi possível criar o projeto agora. Tente novamente.';
    }

    private function userId(): ?int
    {
        $userId = (int) Session::get('user_id', 0);

        return $userId > 0 ? $userId : null;
    }

    /** @return array{id: int, name: string, email: string, credits: int, plan_name: string, monthly_minutes: int} */
    private function userData(int $userId): array
    {
        if ($this->user !== null) {
            $user = ($this->user)($userId);
            if (is_array($user)) {
                return [
                    'id' => $userId,
                    'name' => (string) ($user['name'] ?? 'Conta'),
                    'email' => (string) ($user['email'] ?? ''),
                    'credits' => (int) ($user['credits'] ?? 0),
                    'plan_name' => (string) ($user['plan_name'] ?? 'Plano'),
                    'monthly_minutes' => (int) ($user['monthly_minutes'] ?? 0),
                ];
            }
        }

        return ['id' => $userId, 'name' => 'Conta', 'email' => '', 'credits' => 0, 'plan_name' => 'Plano', 'monthly_minutes' => 0];
    }
}
