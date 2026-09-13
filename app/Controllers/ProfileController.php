<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Account\ProfileAccountService;
use App\Account\ProfileAvatarService;
use App\Account\ProfileValidationException;
use Throwable;

final class ProfileController
{
    /** @var callable(): UserRepository */
    private $usersFactory;

    private $securityFactory;
    private $avatarFactory;
    private $rateLimiterFactory;

    public function __construct(private View $view, UserRepository|callable $users, ?callable $securityFactory=null, ?callable $avatarFactory=null, ?callable $rateLimiterFactory=null)
    {
        $this->usersFactory = $users instanceof UserRepository ? static fn (): UserRepository => $users : $users;
        $this->securityFactory = $securityFactory;
        $this->avatarFactory = $avatarFactory;
        $this->rateLimiterFactory = $rateLimiterFactory;
    }

    public function edit(): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $errors = Session::pull('profile_errors', []);
        $old = Session::pull('profile_old', []);

        return $this->view->render('profile.edit', [
            'title' => 'Perfil',
            'user' => $user,
            'errors' => is_array($errors) ? $errors : [],
            'old' => is_array($old) ? $old : [],
            'message' => Session::pull('message'),
            'pendingEmail' => $this->securityFactory===null ? null : $this->security()->pendingEmail($user['id']),
            'hasAvatar' => $this->avatarFactory!==null && $this->avatars()->pathForUser($user['id'])!==null,
            'securityAvailable' => $this->securityFactory!==null,
        ]);
    }

    public function update(Request $request): Response
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId <= 0) {
            return Response::redirect('/login');
        }

        $input = [
            'name' => trim($this->stringInput($request,'name')),
            'email' => mb_strtolower(trim($this->stringInput($request,'email'))),
        ];
        $errors = $this->validate($input, $userId);
        if ($errors !== []) {
            Session::flash('profile_errors', $errors);
            Session::flash('profile_old', $input);

            return Response::redirect('/perfil');
        }

        try {
            $this->limit($request,'profile-details');
            if ($this->securityFactory !== null) {
                $result = $this->security()->updateDetails($userId,$input['name'],$input['email'],$this->stringInput($request,'current_password'));
            } else {
                $current = $this->users()->findProfileIdentity($userId);
                if ($current===null || strcasecmp($current['email'],$input['email'])!==0) {
                    throw new ProfileValidationException(['email'=>'A confirmação de e-mail ainda não está disponível. Seu endereço não foi alterado.']);
                }
                $this->users()->updateProfile($userId,$input['name'],$current['email']);
                $result = ['email_pending'=>false];
            }
        } catch (ProfileValidationException $exception) {
            Session::flash('profile_errors',$exception->errors());
            Session::flash('profile_old',$input);
            return Response::redirect('/perfil');
        } catch (Throwable) {
            Session::flash('profile_errors', ['form' => 'Não foi possível atualizar seu perfil agora.']);
            Session::flash('profile_old', $input);

            return Response::redirect('/perfil');
        }

        Session::flash('message', $result['email_pending'] ? 'Nome atualizado. A confirmação do novo e-mail foi colocada na fila de envio; seu endereço atual permanece válido até você confirmar.' : 'Perfil atualizado.');

        return Response::redirect('/perfil');
    }

    public function updatePassword(Request $request): Response
    {
        return $this->action($request,'profile-password',function (int $id) use ($request): void {
            $this->security()->changePassword($id,$this->stringInput($request,'current_password'),$this->stringInput($request,'password'),$this->stringInput($request,'password_confirmation'));
            if (session_status()===PHP_SESSION_ACTIVE) session_regenerate_id(true);
            \App\Core\Csrf::rotate();
        },'Senha alterada. Os links pendentes de recuperação e de troca de e-mail foram revogados.');
    }

    public function confirmEmailForm(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user===null) return Response::redirect('/login');
        $token = $request->query('token','');
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/D',$token)!==1) $token='';
        return $this->view->render('profile.confirm-email',['title'=>'Confirmar e-mail','user'=>$user,'token'=>$token])
            ->withHeader('Cache-Control','no-store')->withHeader('Referrer-Policy','no-referrer');
    }

    public function confirmEmail(Request $request): Response
    {
        return $this->action($request,'profile-email-confirm',function (int $id) use ($request): void {
            if (!$this->security()->confirmEmail($id,$this->stringInput($request,'token'))) throw new ProfileValidationException(['form'=>'Este link é inválido, já foi usado ou expirou. Solicite a troca novamente no seu perfil.']);
        },'Novo e-mail confirmado. Use esse endereço no próximo acesso.');
    }

    public function cancelEmailChange(Request $request): Response
    {
        return $this->action($request,'profile-email-cancel',fn(int $id)=>$this->security()->cancelEmailChange($id),'Solicitação de troca de e-mail cancelada.');
    }

    public function storeAvatar(Request $request, ?array $upload=null): Response
    {
        return $this->action($request,'profile-avatar',fn(int $id)=>$this->avatars()->store($id,$upload??(is_array($_FILES['avatar']??null)?$_FILES['avatar']:[])),'Foto do perfil atualizada.');
    }

    public function removeAvatar(Request $request): Response
    {
        return $this->action($request,'profile-avatar-remove',fn(int $id)=>$this->avatars()->remove($id),'Foto removida do perfil.');
    }

    public function avatar(): Response
    {
        $id=(int)Session::get('user_id',0);
        if ($id<=0) return Response::redirect('/login');
        $path=$this->avatars()->pathForUser($id);
        if ($path===null) return Response::text('Foto não encontrada.',404)->withHeader('Cache-Control','no-store');
        return Response::stream(static function () use ($path): void { readfile($path); })
            ->withHeader('Content-Type','image/png')->withHeader('Content-Length',(string)filesize($path))
            ->withHeader('Content-Disposition','inline; filename="avatar.png"')->withHeader('Cache-Control','private, no-store')
            ->withHeader('X-Content-Type-Options','nosniff')->withHeader('Content-Security-Policy',"default-src 'none'; sandbox");
    }

    private function action(Request $request,string $action,callable $operation,string $message): Response
    {
        $id=(int)Session::get('user_id',0);
        if ($id<=0) return Response::redirect('/login');
        try {
            $this->limit($request,$action);
            $operation($id);
            Session::flash('message',$message);
        } catch (ProfileValidationException $exception) {
            Session::flash('profile_errors',$exception->errors());
        } catch (Throwable $exception) {
            Session::flash('profile_errors',['form'=>'Não foi possível concluir esta alteração. Confira a configuração de envio nas configurações e tente novamente. Nenhuma confirmação foi presumida.']);
        }
        return Response::redirect('/perfil');
    }

    private function limit(Request $request,string $action): void
    {
        if ($this->rateLimiterFactory!==null && !(($this->rateLimiterFactory)())->hit($action,(string)Session::get('user_id',0).'|'.$request->clientIp(),10,900)) throw new ProfileValidationException(['form'=>'Muitas tentativas. Aguarde alguns minutos antes de tentar novamente.']);
    }

    private function stringInput(Request $request,string $key): string
    {
        $value=$request->input($key,'');
        return is_string($value)?$value:'';
    }

    private function security(): ProfileAccountService
    {
        if ($this->securityFactory===null) throw new \RuntimeException('Profile security is unavailable.');
        return ($this->securityFactory)();
    }

    private function avatars(): ProfileAvatarService
    {
        if ($this->avatarFactory===null) throw new \RuntimeException('Avatar storage is unavailable.');
        return ($this->avatarFactory)();
    }

    /** @param array{name: string, email: string} $input @return array<string, string> */
    private function validate(array $input, int $userId): array
    {
        $errors = [];
        if (mb_strlen($input['name']) < 2 || mb_strlen($input['name']) > 120) {
            $errors['name'] = 'Informe um nome entre 2 e 120 caracteres.';
        }
        if ($input['email'] === '' || filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($input['email']) > 254) {
            $errors['email'] = 'Informe um e-mail válido.';
        } elseif ($this->users()->emailTakenByAnotherUser($input['email'], $userId)) {
            $errors['email'] = 'Este e-mail já está em uso.';
        }

        return $errors;
    }

    /** @return array{id: int, name: string, email: string, credits: int, plan_name: string, monthly_minutes: int}|null */
    private function currentUser(): ?array
    {
        $userId = (int) Session::get('user_id', 0);

        return $userId > 0 ? $this->users()->findDashboardProfile($userId) : null;
    }

    private function users(): UserRepository
    {
        return ($this->usersFactory)();
    }
}
