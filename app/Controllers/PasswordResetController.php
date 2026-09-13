<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\PasswordResetService;
use App\Services\RateLimiter;
use Throwable;

final class PasswordResetController
{
    /** @var callable(): PasswordResetService */
    private $serviceFactory;

    /** @var (callable(): RateLimiter)|null */
    private $rateLimiterFactory;

    public function __construct(private View $view, callable $serviceFactory, ?callable $rateLimiterFactory = null)
    {
        $this->serviceFactory = $serviceFactory;
        $this->rateLimiterFactory = $rateLimiterFactory;
    }

    public function showForgotPassword(): Response
    {
        return $this->view->render('auth.forgot-password', $this->formData());
    }

    public function showResetPassword(Request $request): Response
    {
        return $this->view->render('auth.reset-password', $this->formData() + [
            'token' => $this->text($request->query('token')),
        ]);
    }

    public function requestReset(Request $request): Response
    {
        $rawEmail = $request->input('email');
        $email = mb_strtolower(trim($this->text($rawEmail)));

        try {
            $ip = $request->clientIp();
            $allowed = $this->rateLimiterFactory === null;
            if (!$allowed) {
                $limiter = $this->rateLimiter();
                $allowed = $limiter->hit('password-reset-ip', $ip, 10, 3600)
                    && $limiter->hit('password-reset-identity', $ip . '|' . $email, 3, 3600);
            }
            if ($allowed && is_string($rawEmail)) {
                $this->service()->request($email);
            }
        } catch (Throwable) {
            // The public response must not disclose whether a user or mail transport exists.
        }

        Session::flash('password_reset_message', 'Se existir uma conta para este e-mail, você receberá instruções para redefinir sua senha.');

        return Response::redirect('/esqueci-minha-senha');
    }

    public function reset(Request $request): Response
    {
        $rawToken = $request->input('token');
        $token = $this->text($rawToken);
        $password = $this->text($request->input('password'));
        $confirmation = $this->text($request->input('password_confirmation'));

        if (strlen($password) < 12 || !hash_equals($password, $confirmation)) {
            Session::flash('password_reset_errors', ['form' => 'Informe uma senha de ao menos 12 caracteres e confirme-a corretamente.']);

            return Response::redirect('/redefinir-senha?token=' . rawurlencode($token));
        }

        try {
            $reset = is_string($rawToken) && $this->service()->reset($token, $password);
        } catch (Throwable) {
            $reset = false;
        }

        if (!$reset) {
            Session::flash('password_reset_errors', ['form' => 'Este link de redefinição é inválido ou expirou. Solicite um novo link.']);

            return Response::redirect('/esqueci-minha-senha');
        }

        Session::flash('message', 'Sua senha foi redefinida. Entre com sua nova senha.');

        return Response::redirect('/login');
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function service(): PasswordResetService
    {
        return ($this->serviceFactory)();
    }

    private function rateLimiter(): RateLimiter
    {
        return ($this->rateLimiterFactory)();
    }

    /** @return array{errors: array<string, string>, old: array<string, string>, message: string|null} */
    private function formData(): array
    {
        $errors = Session::pull('password_reset_errors', []);
        $old = Session::pull('password_reset_old', []);

        return [
            'errors' => is_array($errors) ? $errors : [],
            'old' => is_array($old) ? $old : [],
            'message' => Session::pull('password_reset_message'),
        ];
    }
}
