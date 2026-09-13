<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Billing\BillingRepository;
use App\Billing\CheckoutService;
use App\Billing\CouponService;
use App\Billing\SubscriptionService;
use App\Billing\BillingReconciliationService;
use App\Billing\GatewaySettingsService;
use App\Core\{Request,Response,Session,View};
use DomainException;
use Throwable;

final class BillingController
{
    /** @var callable(int):array<string,mixed>|null */ private $profile;
    public function __construct(private View $view, private BillingRepository $repository, private CheckoutService $checkout, private GatewaySettingsService $settings, private CouponService $coupons, private SubscriptionService $subscriptions, private BillingReconciliationService $reconciliation, callable $profile, private string $provider, private string $environment) { $this->profile = $profile; }
    public function review(Request $request, array $parameters): Response
    {
        try {$plan = $this->plan($parameters);} catch (DomainException) {return Response::text('Não encontrado.',404);} $key = $this->requestKey((int) $plan['id']);$provider=$this->scalar($request->query('provider',''));$environment=$this->scalar($request->query('environment',''));$coupon=$this->scalar($request->query('coupon',''));$invalid=$provider===null||$environment===null||$coupon===null;$gateway=$invalid?null:$this->gateway($provider,$environment);$coupon=$coupon??'';$quote=null;$couponError=$invalid?'Dados de checkout inválidos.':null;try{if(!$invalid)$quote=$this->coupons->preview($this->userId(),(int)$plan['id'],$coupon,$gateway['environment']??$this->environment);}catch(Throwable){$couponError=$coupon!==''?'Cupom indisponível.':null;}
        return $this->render('billing.review', 'Revisar contratação', ['plan' => $plan, 'quote'=>$quote, 'coupon'=>$coupon,'couponError'=>$couponError,'requestKey' => $key, 'available' => $gateway!==null, 'gateways'=>$this->activeGateways(),'provider'=>$gateway['provider']??'','environment' => $gateway['environment']??$this->environment, 'message' => Session::pull('billing_message')]);
    }
    public function start(Request $request, array $parameters): Response
    {
        try {$plan = $this->plan($parameters);} catch (DomainException) {return Response::text('Não encontrado.',404);} $id = (int) $plan['id']; $key=$this->scalar($request->input('request_key',''));$coupon=$this->scalar($request->input('coupon',''));$gateway=$this->submittedGateway($request);
        if ($key===null||$coupon===null||$gateway===null) { Session::flash('billing_message', 'Dados de checkout inválidos. Nenhuma cobrança foi criada.'); return Response::redirect('/checkout/plano/' . $id); }
        if (!hash_equals($this->requestKey($id), $key) || $gateway===null) { Session::flash('billing_message', 'O checkout não está disponível para esta solicitação.'); return Response::redirect('/checkout/plano/' . $id); }
        try { $attempt = $this->checkout->start($this->userId(), $id, $gateway['provider'], $gateway['environment'], $key, $coupon); } catch (Throwable) { Session::flash('billing_message', 'Não foi possível iniciar o checkout. Nenhum plano foi ativado.'); return Response::redirect('/checkout/plano/' . $id); }
        if (!is_string($attempt['checkout_url'] ?? null) || $attempt['checkout_url'] === '') { Session::flash('billing_message', 'Checkout ainda está sendo confirmado. Nenhum plano foi ativado.'); return Response::redirect('/checkout/plano/' . $id); }
        return Response::redirect($attempt['checkout_url']);
    }
    public function returned(Request $request, array $parameters): Response
    {
        $attempt = $this->attempt($parameters); if ($attempt === null) return Response::text('Não encontrado.', 404);
        return $this->render('billing.status', 'Status do pagamento', ['attempt' => $attempt]);
    }
    public function status(Request $request, array $parameters): Response
    {
        $attempt = $this->attempt($parameters); if ($attempt === null) return Response::text('Não encontrado.', 404);
        return Response::json(['id' => (string) $attempt['id'], 'status' => (string) $attempt['status']])->withHeader('Cache-Control', 'private, no-store');
    }
    public function history(Request $request): Response
    {
        $user = $this->userId();
        return $this->render('billing.history', 'Pagamentos', ['payments' => $this->repository->history($user, $this->page($request->query('page', '1'))), 'subscriptions' => $this->repository->subscriptions($user), 'message'=>Session::pull('billing_message')]);
    }
    public function cancel(Request $request,array $parameters):Response {$id=$parameters['id']??null;$id=is_string($id)&&preg_match('/\A[1-9][0-9]*\z/D',$id)?(int)$id:0;$immediate=$this->scalar($request->input('immediate',''));$confirmed=$this->scalar($request->input('confirm_immediate',''));$subscription=null;foreach($this->repository->subscriptions($this->userId()) as $row)if((int)$row['id']===$id){$subscription=$row;break;}if($subscription!==null&&($subscription['provider']??'')==='pagarme'&&($immediate!=='1'||$confirmed!=='1')){Session::flash('billing_message','O Pagar.me exige confirmação explícita do cancelamento imediato.');return Response::redirect('/conta/pagamentos');}try{$this->subscriptions->cancel($this->userId(),$id,$immediate!=='1');Session::flash('billing_message','Cancelamento confirmado pelo provedor.');}catch(Throwable){Session::flash('billing_message','O provedor ainda não confirmou o cancelamento.');}return Response::redirect('/conta/pagamentos');}
    public function reconcile(Request $request,array $parameters):Response {$attempt=$parameters['attempt']??null;try{if(!is_string($attempt)||$attempt==='')throw new DomainException('Tentativa inválida.');$result=$this->reconciliation->reconcile($this->userId(),$attempt);Session::flash('billing_message','Reconciliação: '.(string)($result['status']??'pending').'.');}catch(Throwable){Session::flash('billing_message','Reconciliação indisponível; nenhum plano foi ativado.');}return Response::redirect('/conta/pagamentos');}
    private function attempt(array $parameters): ?array { $id = (string) ($parameters['attempt'] ?? ''); $row = $id === '' ? null : $this->repository->attemptForUser($id, $this->userId()); return is_array($row) ? $row : null; }
    private function plan(array $parameters): array { $id = $parameters['plan'] ?? ''; if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) !== 1) throw new DomainException('Plano indisponível.'); foreach ($this->repository->activePlans() as $plan) if ((int) $plan['id'] === (int) $id) return $plan; throw new DomainException('Plano indisponível.'); }
    private function requestKey(int $plan): string { $name = 'billing_request_key_' . $plan; $key = Session::get($name); if (is_string($key) && preg_match('/\A[a-zA-Z0-9_-]{32,100}\z/D',$key)===1) {$attempt=$this->repository->one('SELECT status FROM billing_checkout_attempts WHERE user_id=? AND plan_id=? AND request_key=?',[$this->userId(),$plan,$key]);if(is_array($attempt)&&in_array($attempt['status']??'', ['confirmed','expired','failed','canceled','cancelled'],true)){Session::forget($name);$key=null;}}if (!is_string($key) || preg_match('/\A[a-zA-Z0-9_-]{32,100}\z/D', $key) !== 1) { $key = bin2hex(random_bytes(32)); Session::put($name, $key); } return $key; }
    private function activeGateways(): array {return array_values(array_filter($this->settings->masked(),static fn($row)=>(int)($row['is_active']??0)===1&&($row['configuration_status']??'')==='configured'));}
    private function submittedGateway(Request $request):?array
    {
        if($request->hasInput('gateway')) {
            $pair=$this->scalar($request->input('gateway'));
            // Never merge an atomic selection with separate, possibly stale hidden fields.
            if($pair===null || $request->hasInput('provider') || $request->hasInput('environment') || preg_match('/\A(stripe|pagarme):(sandbox|production)\z/D',$pair,$parts)!==1)return null;
            $provider=$parts[1];$environment=$parts[2];
        } else {
            // Explicit legacy callers remain supported; missing fields cannot select a default.
            $provider=$this->scalar($request->input('provider'));$environment=$this->scalar($request->input('environment'));
            if(!in_array($provider,['stripe','pagarme'],true) || !in_array($environment,['sandbox','production'],true))return null;
        }
        return $this->gateway($provider,$environment);
    }
    private function gateway(string $provider='',string $environment=''): ?array {$rows=$this->activeGateways();foreach($rows as $row)if(($provider===''||$row['provider']===$provider)&&($environment===''||$row['environment']===$environment))return $row;return $provider===''&&$environment===''?($rows[0]??null):null;}
    private function scalar(mixed $value): ?string {return is_string($value)?$value:null;}
    private function userId(): int { $id = (int) Session::get('user_id', 0); if ($id < 1) throw new DomainException('Sessão inválida.'); return $id; }
    private function page(mixed $value): int { return is_string($value) && preg_match('/\A[1-9][0-9]{0,4}\z/D', $value) === 1 ? (int) $value : 1; }
    private function render(string $view, string $title, array $data): Response { $user = ($this->profile)($this->userId()); if (!is_array($user)) throw new DomainException('Conta indisponível.'); $user += ['credits' => 0, 'plan_name' => 'Plano atual', 'monthly_minutes' => 0]; return $this->view->render($view, $data + ['title' => $title, 'user' => $user])->withHeader('Cache-Control', 'private, no-store'); }
}
