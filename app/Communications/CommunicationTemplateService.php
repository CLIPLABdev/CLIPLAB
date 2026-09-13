<?php
declare(strict_types=1);
namespace App\Communications;
use PDO;

final class CommunicationTemplateService
{
    public function __construct(private PDO $pdo) {}
    public function installDefaults():void {
        foreach ((new CommunicationEventCatalog())->events() as $event=>$definition) {
            $q=$this->pdo->prepare("SELECT id FROM communication_email_templates WHERE event=:event AND locale='pt-BR' LIMIT 1");$q->execute(['event'=>$event]);if($q->fetchColumn()!==false)continue;
            $copy=$this->systemTemplate($event);$subject=$copy['subject_template'];$html=$copy['html_template'];$text=$copy['text_template'];
            $s=$this->pdo->prepare("INSERT INTO communication_email_templates (event,locale,subject_template,html_template,text_template,is_active,version) VALUES (:event,'pt-BR',:subject,:html,:text,1,1)");
            try {$s->execute(['event'=>$event,'subject'=>$subject,'html'=>$html,'text'=>$text]);}
            catch (\PDOException $e) {if(!str_contains($e->getMessage(),'UNIQUE constraint failed') && (int)($e->errorInfo[1]??0)!==1062)throw $e;}
        }
    }
    /** Read-only system suggestion. Saving and activation remain explicit version actions. */
    public function systemTemplate(string $event):array {
        if(!isset((new CommunicationEventCatalog())->events()[$event]))throw new \InvalidArgumentException('Evento não permitido.');
        [$subject,$intro]=match($event) {
            'auth.welcome'=>['Bem-vindo ao ClipForge','Sua próxima ideia começa aqui.'],
            'auth.password_reset'=>['Redefina sua senha do ClipForge','Vamos recuperar seu acesso.'],
            'auth.email_verification'=>['Confirme seu e-mail no ClipForge','Um passo para confirmar que este e-mail é seu.'],
            'account.email_change_requested'=>['Confirme seu novo e-mail','Seu novo endereço precisa da sua confirmação.'],
            'account.email_changed'=>['Seu e-mail foi alterado','O e-mail da sua conta mudou.'],
            'account.password_changed'=>['Sua senha foi alterada','Sua nova senha já está em uso.'],
            'media.processing_completed'=>['Seu conteúdo está pronto','Seu projeto está pronto para o próximo passo.'],
            'media.processing_failed'=>['Seu projeto precisa de atenção','O processamento não foi concluído.'],
            'media.usage_limit_reached'=>['Você atingiu o limite do seu plano','Hora de conferir seu uso.'],
            'billing.payment_approved'=>['Pagamento aprovado','Recebemos a confirmação do seu pagamento.'],
            'billing.payment_pending'=>['Seu pagamento está pendente','Estamos aguardando a confirmação do pagamento.'],
            'billing.payment_failed'=>['Seu pagamento não foi aprovado','Não foi possível confirmar o pagamento.'],
            'billing.subscription_created'=>['Assinatura criada no ClipForge','Sua assinatura foi registrada.'],
            'billing.subscription_renewed'=>['Sua assinatura foi renovada','Um novo ciclo da sua assinatura começou.'],
            'billing.subscription_canceled'=>['Sua assinatura foi cancelada','O cancelamento da sua assinatura foi registrado.'],
            'billing.subscription_changed'=>['Sua assinatura foi atualizada','Os dados da sua assinatura mudaram.'],
            'billing.refund_processed'=>['Reembolso processado','O reembolso do seu pagamento foi processado.'],
            'marketing.campaign'=>['{{titulo}}','Uma novidade para você.'],
        };
        $html='<p>Olá, {{nome_usuario}}.</p><h1>'.$intro.'</h1>';
        $html.=match($event) {
            'auth.welcome'=>'<p>Bem-vindo ao ClipForge. Transforme suas ideias em conteúdo, organize seus projetos e acompanhe cada criação em um só lugar.</p><p>Quando quiser começar, acesse sua conta e crie seu primeiro projeto.</p>',
            'auth.password_reset'=>'<p>Recebemos um pedido para redefinir a senha da sua conta. Use o botão abaixo para escolher uma nova senha.</p><p><a href="{{link_recuperacao}}">Redefinir minha senha</a></p><p>O link expira em 30 minutos. Se você não fez este pedido, ignore a mensagem. Sua senha permanece a mesma.</p>',
            'auth.email_verification'=>'<p>Confirme este endereço para concluir a verificação do e-mail da sua conta ClipForge.</p><p><a href="{{link_confirmacao}}">Confirmar meu e-mail</a></p><p>Se você não criou esta conta, ignore esta mensagem. Não compartilhe este link.</p>',
            'account.email_change_requested'=>'<p>Recebemos um pedido para usar este endereço na sua conta ClipForge. Confirme apenas se foi você quem solicitou a alteração.</p><p><a href="{{link_confirmacao}}">Confirmar novo e-mail</a></p><p>Se você não solicitou a troca, não use o link e revise a segurança da sua conta.</p>',
            'account.email_changed'=>'<p>A alteração de e-mail foi concluída. Use o novo endereço para acessar sua conta ClipForge.</p><p>Não reconhece esta alteração? Procure o suporte do ClipForge para revisar seu acesso.</p>',
            'account.password_changed'=>'<p>A senha da sua conta ClipForge foi alterada. Use a nova senha no próximo acesso.</p><p>Se não foi você, solicite a recuperação de senha na tela de acesso e revise a segurança da sua conta.</p>',
            'media.processing_completed'=>'<p><strong>Projeto: {{nome_projeto}}</strong></p><p>O processamento foi concluído. Acesse seus projetos no ClipForge para conferir o resultado e continuar sua criação.</p>',
            'media.processing_failed'=>'<p><strong>Projeto: {{nome_projeto}}</strong></p><p>Acesse o projeto no ClipForge para conferir os detalhes e as opções disponíveis antes de tentar novamente.</p>',
            'media.usage_limit_reached'=>'<p>Você atingiu o limite de uso do plano <strong>{{nome_plano}}</strong>.</p><p>Confira seu consumo e as opções do seu plano na conta antes de iniciar novos processamentos.</p>',
            'marketing.campaign'=>'<h2>{{titulo}}</h2><p>{{conteudo}}</p>',
            default=>'<table><tr><th>Plano</th><td>{{nome_plano}}</td></tr><tr><th>Valor</th><td>{{valor}} {{moeda}}</td></tr></table><p>{{motivo}}</p><p><a href="{{link_assinatura}}">Consultar minha assinatura</a></p><p>Confira os detalhes atualizados e as opções disponíveis na sua conta.</p>',
        };
        $text=preg_replace('/<a href="([^\"]+)">([^<]+)<\/a>/','$2: $1',$html)??$html;
        $text=trim(html_entity_decode(strip_tags(str_replace(['</p>','</h1>','</h2>','</tr>','</th>','</td>'],["\n\n","\n\n","\n\n","\n",': ',' '],$text)),ENT_QUOTES|ENT_HTML5,'UTF-8'));
        return ['event'=>$event,'subject_template'=>$subject,'html_template'=>$html,'text_template'=>$text,'version'=>0,'is_active'=>0];
    }
    public function validate(string $subject,string $html,string $text):void {
        if(trim($subject)==='' || trim($html)==='' || trim($text)==='' || str_contains($subject,"\r") || str_contains($subject,"\n") || mb_strlen($subject)>255 || strlen($text)>200000)throw new \InvalidArgumentException('Preencha assunto, HTML e texto dentro dos limites.');
        (new EmailHtmlPolicy())->validate($html);
    }
    public function find(int $id):array {
        $s=$this->pdo->prepare('SELECT * FROM communication_email_templates WHERE id=:id');$s->execute(['id'=>$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new \InvalidArgumentException('Template não encontrado.');return $row;
    }
    /** Every save creates an immutable version. Activation is a separate explicit action. */
    public function create(string $event,string $subject,string $html,string $text,int $actor):int {
        $definitions=(new CommunicationEventCatalog())->events();if(!isset($definitions[$event])||$actor<1)throw new \InvalidArgumentException('Evento ou administrador inválido.');
        $this->validate($subject,$html,$text);$variables=$this->exampleVariables($event);$renderer=new EmailTemplateRenderer();$renderer->renderSubject($subject,$variables,array_keys($variables));$renderer->render($html,$variables,array_keys($variables));$renderer->renderText($text,$variables,array_keys($variables));
        return $this->transaction(function()use($event,$subject,$html,$text,$actor):int {
            $s=$this->pdo->prepare("SELECT version FROM communication_email_templates WHERE event=:event AND locale='pt-BR' ORDER BY version DESC".$this->lockClause());$s->execute(['event'=>$event]);$version=(int)$s->fetchColumn()+1;
            $s=$this->pdo->prepare("INSERT INTO communication_email_templates(event,locale,subject_template,html_template,text_template,is_active,version,updated_by) VALUES(:event,'pt-BR',:subject,:html,:text,0,:version,:actor)");$s->execute(compact('event','subject','html','text','version','actor'));return (int)$this->pdo->lastInsertId();
        });
    }
    public function duplicate(int $id,int $actor):int {$r=$this->find($id);return $this->create($r['event'],$r['subject_template'],$r['html_template'],$r['text_template'],$actor);}
    public function activate(int $id,int $actor):void {
        $this->transaction(function()use($id,$actor):void {
            $r=$this->find($id);$this->validate($r['subject_template'],$r['html_template'],$r['text_template']);
            $s=$this->pdo->prepare('SELECT id FROM communication_email_templates WHERE event=:event AND locale=:locale'.$this->lockClause());$s->execute(['event'=>$r['event'],'locale'=>$r['locale']]);$s->fetchAll();
            $s=$this->pdo->prepare('UPDATE communication_email_templates SET is_active=0 WHERE event=:event AND locale=:locale');$s->execute(['event'=>$r['event'],'locale'=>$r['locale']]);
            $s=$this->pdo->prepare('UPDATE communication_email_templates SET is_active=1,updated_by=:actor,updated_at=CURRENT_TIMESTAMP WHERE id=:id');$s->execute(compact('actor','id'));
        });
    }
    public function exampleVariables(string $event):array {
        $events=(new CommunicationEventCatalog())->events();if(!isset($events[$event]))throw new \InvalidArgumentException('Evento não permitido.');$values=[];
        foreach($events[$event]['variables'] as $name)$values[$name]=str_starts_with($name,'link_')?'https://example.test/preview':match($name){'nome_usuario'=>'Pessoa de exemplo','valor'=>'29,90','moeda'=>'BRL','nome_plano'=>'Plano de exemplo',default=>'Exemplo'};return $values;
    }
    private function lockClause():string{return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    private function transaction(callable $operation):mixed {$owns=!$this->pdo->inTransaction();if($owns)$this->pdo->beginTransaction();try{$result=$operation();if($owns)$this->pdo->commit();return $result;}catch(\Throwable $e){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}
}
