<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

final class ClipLibraryController
{
    /** @var callable(int,string,int,int):array<string,mixed> */
    private $library;

    /** @var callable(int):array<string,mixed>|null */
    private $user;

    /**
     * @param callable(int,string,int,int):array<string,mixed> $library
     * @param callable(int):array<string,mixed>|null $user
     */
    public function __construct(private View $view, callable $library, ?callable $user = null)
    {
        $this->library = $library;
        $this->user = $user;
    }

    public function index(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        $filter = $this->filter($request->query('filter', 'recent'));
        $page = $this->page($request->query('page', '1'));
        $library = ($this->library)($userId, $filter, $page, 24);

        return $this->view->render('clips.index', [
            'title' => 'Clipes',
            'user' => $this->userData($userId),
            'library' => $library,
        ])->withHeader('Cache-Control', 'private, no-store');
    }

    private function filter(mixed $value): string
    {
        return is_string($value) && in_array($value, ['recent', 'processing', 'completed', 'failed'], true)
            ? $value
            : 'recent';
    }

    private function page(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 1;
        }
        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            return 1;
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return 1;
        }

        return (int) $value;
    }

    private function userId(): ?int
    {
        $userId = (int) Session::get('user_id', 0);

        return $userId > 0 ? $userId : null;
    }

    /** @return array{id:int,name:string,email:string,credits:int,plan_name:string,monthly_minutes:int} */
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

        return [
            'id' => $userId,
            'name' => 'Conta',
            'email' => '',
            'credits' => 0,
            'plan_name' => 'Plano',
            'monthly_minutes' => 0,
        ];
    }
}
