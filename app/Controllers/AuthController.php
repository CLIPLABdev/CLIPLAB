<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AuthService;
use App\Services\RateLimiter;
use App\Validation\AuthValidator;
use Throwable;

final class AuthController
{
    /** @var callable(): AuthService */
    private $authServiceFactory;

    /** @var callable(): RateLimiter */
    private $rateLimiterFactory;

    private ErrorHandler $errors;

    public function __construct(View $view, callable $authServiceFactory, callable $rateLimiterFactory, ?ErrorHandler $errors = null)
    {
        $this->view = $view;
        $this->authServiceFactory = $authServiceFactory;
        $this->rateLimiterFactory = $rateLimiterFactory;
        $this->errors = $errors ?? new ErrorHandler();
    }

    private View $view;

    public function showLogin(): Response
    {
        return $this->view->render('auth.login', $this->formData());
    }

    public function showRegister(): Response
    {
        return $this->view->render('auth.register', $this->formData());
    }

    public function login(Request $request): Response
    {
        $rawEmail = $request->input('email');
        $rawPassword = $request->input('password');
        $email = mb_strtolower(trim(is_string($rawEmail) ? $rawEmail : ''));
        $password = is_string($rawPassword) ? $rawPassword : '';

        $ip = $request->clientIp();
        $limiter = $this->rateLimiter();
        if (!$limiter->hit('login-ip', $ip, 20, 900) || !$limiter->hit('login-identity', $ip . '|' . $email, 5, 900)) {
            return $this->errors->renderStatus(429);
        }

        if (!is_string($rawEmail) || !is_string($rawPassword) || !$this->authService()->attempt($email, $password)) {
            return $this->redirectWithError('/login', 'Não foi possível entrar com estas credenciais.', ['email' => $email]);
        }

        return Response::redirect('/dashboard');
    }

    public function register(Request $request): Response
    {
        $text = static fn (mixed $value): string => is_string($value) ? $value : '';
        $input = [
            'name' => trim($text($request->input('name'))),
            'email' => mb_strtolower(trim($text($request->input('email')))),
            'password' => $text($request->input('password')),
            'password_confirmation' => $text($request->input('password_confirmation')),
        ];
        $errors = AuthValidator::registration($input);

        if ($errors !== []) {
            return $this->redirectWithError('/cadastro', $errors, ['name' => $input['name'], 'email' => $input['email']]);
        }

        $ip = $request->clientIp();
        $limiter = $this->rateLimiter();
        if (!$limiter->hit('register-ip', $ip, 10, 3600) || !$limiter->hit('register-identity', $ip . '|' . $input['email'], 3, 3600)) {
            return $this->errors->renderStatus(429);
        }

        try {
            $this->authService()->register($input);
        } catch (Throwable $exception) {
            return $this->redirectWithError('/cadastro', $this->registrationFailureMessage($exception), ['name' => $input['name'], 'email' => $input['email']]);
        }

        return Response::redirect('/dashboard');
    }

    public function logout(): Response
    {
        $this->authService()->logout();
        Session::flash('message', 'Você saiu da sua conta.');

        return Response::redirect('/login');
    }

    private function authService(): AuthService
    {
        return ($this->authServiceFactory)();
    }

    private function rateLimiter(): RateLimiter
    {
        return ($this->rateLimiterFactory)();
    }

    /** @return array{errors: array<string, string>, old: array<string, string>, message: string|null} */
    private function formData(): array
    {
        $errors = Session::pull('auth_errors', []);
        $old = Session::pull('auth_old', []);

        return [
            'errors' => is_array($errors) ? $errors : [],
            'old' => is_array($old) ? $old : [],
            'message' => Session::pull('message'),
        ];
    }

    /** @param array<string, string>|string $errors @param array<string, string> $old */
    private function redirectWithError(string $path, array|string $errors, array $old): Response
    {
        Session::flash('auth_errors', is_array($errors) ? $errors : ['form' => $errors]);
        Session::flash('auth_old', $old);

        return Response::redirect($path);
    }

    private function registrationFailureMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'Application encryption key is invalid.')
            || str_contains($message, 'Secret value is invalid.')
            || str_contains($message, 'Secret could not be encrypted.')
        ) {
            return 'O ambiente de configuração está incompleto. Configure uma APP_ENCRYPTION_KEY válida antes de criar a conta.';
        }

        return 'Não foi possível criar a conta agora.';
    }
}
