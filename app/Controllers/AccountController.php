<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AccountRepository;
use App\Services\PlanQuotaService;

final class AccountController
{
    /** @var callable():PlanQuotaService */
    private $quotasFactory;

    /** @var callable():AccountRepository */
    private $accountsFactory;

    public function __construct(
        private View $view,
        PlanQuotaService|callable $quotas,
        AccountRepository|callable $accounts
    ) {
        $this->quotasFactory = $quotas instanceof PlanQuotaService
            ? static fn (): PlanQuotaService => $quotas
            : $quotas;
        $this->accountsFactory = $accounts instanceof AccountRepository
            ? static fn (): AccountRepository => $accounts
            : $accounts;
    }

    public function plan(): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }
        $snapshot = $this->quotas()->snapshotForUser($userId);

        return $this->view->render('account.plan', [
            'title' => 'Plano e limites',
            'user' => $this->userData($snapshot),
            'snapshot' => $snapshot,
            'plans' => $this->accounts()->activePlans(),
        ])->withHeader('Cache-Control', 'private, no-store');
    }

    public function credits(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }
        $snapshot = $this->quotas()->snapshotForUser($userId);
        $filter = $this->filter($request->query('filter', 'all'));
        $page = $this->page($request->query('page', '1'));

        return $this->view->render('account.credits', [
            'title' => 'Créditos',
            'user' => $this->userData($snapshot),
            'snapshot' => $snapshot,
            'ledger' => $this->accounts()->ledgerForUser($userId, $filter, $page),
        ])->withHeader('Cache-Control', 'private, no-store');
    }

    private function userId(): ?int
    {
        $userId = (int) Session::get('user_id', 0);

        return $userId > 0 ? $userId : null;
    }

    private function filter(mixed $value): string
    {
        return is_string($value) && in_array($value, ['all', 'additions', 'consumption', 'refunds', 'adjustments'], true)
            ? $value
            : 'all';
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

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function userData(array $snapshot): array
    {
        return [
            'id' => (int) $snapshot['user']['id'],
            'name' => (string) $snapshot['user']['name'],
            'email' => (string) $snapshot['user']['email'],
            'status' => (string) $snapshot['user']['status'],
            'credits' => (int) $snapshot['credits'],
            'plan_name' => (string) $snapshot['plan']['name'],
            'monthly_minutes' => (int) $snapshot['plan']['monthly_minutes'],
        ];
    }

    private function quotas(): PlanQuotaService
    {
        return ($this->quotasFactory)();
    }

    private function accounts(): AccountRepository
    {
        return ($this->accountsFactory)();
    }
}
