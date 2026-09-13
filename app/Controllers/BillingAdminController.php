<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Billing\FinancialRepository;
use App\Billing\GatewaySettingsService;
use App\Billing\CouponService;
use App\Core\{Request,Response,Session,View};
use Throwable;

final class BillingAdminController
{
    /** @var callable(int):array<string,mixed>|null */ private $identity;
    public function __construct(private View $view, private FinancialRepository $financial, private GatewaySettingsService $settings, private CouponService $coupons, private \PDO $pdo, callable $identity) { $this->identity = $identity; }
    public function finance(Request $request): Response { return $this->render('billing.admin-finance', 'Financeiro', $this->financial->report(['environment'=>$request->query('environment','production'),'currency'=>$request->query('currency','BRL'),'gateway'=>$request->query('gateway',''),'status'=>$request->query('status',''),'user'=>$request->query('user',''),'plan'=>$request->query('plan',''),'from'=>$request->query('from',''),'to'=>$request->query('to','')])+['summary'=>$this->financial->dashboard()]); }
    public function gateways(Request $request): Response { return $this->render('billing.admin-gateways', 'Gateways de pagamento', ['gateways' => $this->settings->masked()]); }
    public function saveGateway(Request $request, array $parameters): Response
    {
        try { $this->settings->save((string) ($parameters['provider'] ?? ''), (string) ($parameters['environment'] ?? ''), ['secret'=>$request->input('secret',''),'webhook_secret'=>$request->input('webhook_secret',''),'public_key'=>$request->input('public_key',''),'active'=>$request->input('active')==='1'], $this->actor()); Session::flash('admin_flash', ['type'=>'success','message'=>'Configuração do gateway salva.']); }
        catch (Throwable) { Session::flash('admin_flash', ['type'=>'error','message'=>'Não foi possível salvar o gateway. Nenhum segredo foi exibido.']); }
        return Response::redirect('/admin/gateways');
    }
    public function coupons(Request $request):Response {$coupons=$this->pdo->query('SELECT * FROM coupons ORDER BY id DESC')->fetchAll(\PDO::FETCH_ASSOC);foreach($coupons as &$coupon){$s=$this->pdo->prepare('SELECT plan_id FROM coupon_plans WHERE coupon_id=? ORDER BY plan_id');$s->execute([$coupon['id']]);$coupon['plan_ids']=array_map('intval',$s->fetchAll(\PDO::FETCH_COLUMN));}unset($coupon);return $this->render('billing.admin-coupons','Cupons',['coupons'=>$coupons,'plans'=>$this->pdo->query('SELECT id,name FROM plans ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC)]);}
    public function saveCoupon(Request $request,array $parameters=[]):Response {$rawId=$parameters['id']??null;$id=is_string($rawId)&&preg_match('/\A[1-9][0-9]*\z/D',$rawId)?(int)$rawId:null;try{$planIds=$request->input('plan_ids',null);$scope=$request->hasInput('plan_scope_present')?$this->scalar($request->input('plan_scope_present')):'';if($scope===null||!in_array($scope,['','1'],true))throw new \DomainException('Escopo inválido.');if(!$request->hasInput('plan_ids')&&$scope==='1')$planIds=[];if($id!==null&&!$request->hasInput('plan_ids')&&$scope!=='1'){$s=$this->pdo->prepare('SELECT plan_id FROM coupon_plans WHERE coupon_id=? ORDER BY plan_id');$s->execute([$id]);$planIds=$s->fetchAll(\PDO::FETCH_COLUMN);}if(!is_array($planIds))throw new \DomainException('Planos inválidos.');$this->coupons->save(['code'=>$this->required($request,'code'),'discount_type'=>$this->required($request,'discount_type'),'discount_value'=>$this->required($request,'discount_value'),'currency'=>$this->required($request,'currency','BRL'),'starts_at'=>$this->required($request,'starts_at'),'ends_at'=>$this->required($request,'ends_at'),'max_redemptions'=>$this->required($request,'max_redemptions'),'per_user_limit'=>$this->required($request,'per_user_limit','1'),'is_active'=>$this->scalar($request->input('is_active',''))==='1','plan_ids'=>$planIds],$id);Session::flash('admin_flash',['type'=>'success','message'=>'Cupom salvo; histórico financeiro preservado.']);}catch(Throwable){Session::flash('admin_flash',['type'=>'error','message'=>'Dados do cupom inválidos.']);}return Response::redirect('/admin/cupons');}
    private function scalar(mixed $value):?string{return is_string($value)?$value:null;}
    private function required(Request $request,string $key,string $default=''):string {$value=$this->scalar($request->input($key,$default));if($value===null)throw new \DomainException('Campo inválido.');return $value;}
    private function render(string $view, string $title, array $data): Response { $identity = ($this->identity)($this->actor()); if (!is_array($identity)) return Response::redirect('/login'); return $this->view->render($view, $data + ['title'=>$title,'admin'=>$identity,'flash'=>Session::pull('admin_flash')]); }
    private function actor(): int { $id = (int) Session::get('user_id', 0); if ($id < 1) throw new \DomainException('Sessão inválida.'); return $id; }
}
