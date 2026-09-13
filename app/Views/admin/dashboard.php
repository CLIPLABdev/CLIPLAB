<?php
declare(strict_types=1);
/** Values remain server-rendered snapshots; there is no polling or synthetic growth. */
$groups = [
    ['admin-production-title','Produção','Da fila ao corte final',[
        ['Projetos',$metrics['projects_total']??0,($metrics['projects_processing']??0).' em processamento','folder-open'],
        ['Jobs na fila',$metrics['jobs_queued']??0,($metrics['jobs_running']??0).' executando','list-video'],
        ['Falhas',$metrics['jobs_failed']??0,'jobs que exigem atenção','triangle-alert'],
        ['Vídeos processados',$metrics['videos_processed']??0,'projetos com análise pronta ou concluídos','film'],
        ['Cortes concluídos',$metrics['clips_completed']??0,'somente clipes com status concluído','scissors'],
        ['Armazenamento',number_format(((int)($metrics['storage_bytes']??0))/1073741824,2,',','.').' GB','metadados reais dos projetos','hard-drive'],
    ]],
    ['admin-people-title','Pessoas e planos','Contas, acesso e créditos',[
        ['Usuários',$metrics['users_total']??0,($metrics['users_active']??0).' ativos','users'],
        ['Novos usuários',$metrics['users_new']??0,'cadastros nos últimos 30 dias','user-plus'],
        ['Suspensos',$metrics['users_suspended']??0,'contas sem acesso','user-round-x'],
        ['Créditos em saldo',$metrics['credits_balance']??0,'saldo total das contas','coins'],
        ['Planos em uso',$metrics['plans_in_use']??0,'planos com contas vinculadas','layers-2'],
    ]],
];
if (is_array($financial??null)) {
    $money=static fn(int $cents):string=>'R$ '.number_format($cents/100,2,',','.');
    $groups[]=['admin-financial-title','Financeiro','Produção · BRL',[
        ['Receita líquida total',$money($financial['net_total_cents']),'produção · BRL · pagamentos menos reembolsos','wallet'],
        ['Receita líquida mensal',$money($financial['net_month_cents']),'pagamentos do mês UTC, menos seus reembolsos','calendar-days'],
        ['Assinaturas ativas',$financial['subscriptions_active'],$financial['subscriptions_total'].' no total · '.$financial['subscriptions_canceled'].' canceladas','repeat-2'],
        ['Pagamentos confirmados',$financial['payments_paid'],$financial['payments_pending'].' pendentes · '.$financial['payments_failed'].' falharam','badge-check'],
    ]];
}
ob_start();
?>
<section class="admin-page-head admin-overview-head"><div><p class="admin-eyebrow">Controle da operação</p><h2>O panorama da sua plataforma.</h2><p>Acompanhe a produção, cuide das contas e veja o movimento financeiro.</p></div><a class="admin-button admin-button-secondary" href="/admin"><i data-lucide="refresh-cw" aria-hidden="true"></i>Atualizar visão</a></section>
<p class="admin-snapshot-note"><i data-lucide="clock-3" aria-hidden="true"></i>Indicadores atualizados ao abrir esta página.</p>
<?php if ((int)($metrics['jobs_failed']??0)>0): ?>
<a class="admin-attention" data-admin-attention href="/admin/erros"><span class="admin-attention-icon"><i data-lucide="triangle-alert" aria-hidden="true"></i></span><span><strong><?= (int)$metrics['jobs_failed'] ?> <?= (int)$metrics['jobs_failed']===1?'job precisa':'jobs precisam' ?> de atenção</strong><small>Consulte as falhas para entender o que interrompeu o processamento.</small></span><span class="admin-attention-action">Revisar falhas <i data-lucide="arrow-right" aria-hidden="true"></i></span></a>
<?php endif; ?>
<?php foreach ($groups as [$headingId,$heading,$description,$cards]): ?>
<section class="admin-metric-section" aria-labelledby="<?= e($headingId) ?>">
    <div class="admin-section-heading"><div><h2 id="<?= e($headingId) ?>"><?= e($heading) ?></h2><p><?= e($description) ?></p></div><?php if($headingId==='admin-financial-title' && !empty($platformFeatures['billing'])): ?><a href="/admin/financeiro">Abrir financeiro <i data-lucide="arrow-up-right" aria-hidden="true"></i></a><?php endif; ?></div>
    <section class="admin-metrics" aria-label="<?= e($heading) ?> — indicadores">
        <?php foreach ($cards as [$label,$value,$description,$icon]): ?>
        <article<?= $label==='Falhas' && (int)$value>0?' class="admin-metric-warning"':'' ?>><div class="admin-metric-label"><span><?= e($label) ?></span><i data-lucide="<?= e($icon) ?>" aria-hidden="true"></i></div><strong><?= e((string)$value) ?></strong><small><?= e($description) ?></small></article>
        <?php endforeach; ?>
    </section>
</section>
<?php endforeach; ?>
<div class="admin-overview-bottom">
<section class="admin-panel"><div class="admin-panel-head"><div><p class="admin-eyebrow">Atividade recente</p><h2>Eventos auditados</h2></div><i data-lucide="history" aria-hidden="true"></i></div><?php if(($metrics['recent_activity']??[])===[]): ?><div class="admin-empty"><i data-lucide="history" aria-hidden="true"></i><strong>Nenhuma atividade administrativa registrada.</strong><p>As próximas ações auditadas aparecerão aqui.</p></div><?php else: ?><ul class="admin-activity-list"><?php foreach($metrics['recent_activity'] as $event): ?><li><span class="admin-activity-dot" aria-hidden="true"></span><div><p><?= e((string)$event['public_message']) ?></p><time><?= e((string)$event['created_at']) ?></time></div></li><?php endforeach; ?></ul><?php endif; ?></section>
<section class="admin-panel"><div class="admin-panel-head"><div><p class="admin-eyebrow">Acesso rápido</p><h2>Continue a operação</h2></div></div><div class="admin-action-grid"><a href="/admin/usuarios"><i data-lucide="users" aria-hidden="true"></i><span>Gerenciar usuários<small>Acesso, planos e contas</small></span><i data-lucide="arrow-up-right" aria-hidden="true"></i></a><a href="/admin/jobs"><i data-lucide="list-video" aria-hidden="true"></i><span>Acompanhar jobs<small>Fila e processamento</small></span><i data-lucide="arrow-up-right" aria-hidden="true"></i></a><a href="/admin/erros"><i data-lucide="triangle-alert" aria-hidden="true"></i><span>Revisar falhas<small>Eventos que pedem atenção</small></span><i data-lucide="arrow-up-right" aria-hidden="true"></i></a><a href="/admin/configuracoes/gemini"><i data-lucide="sparkles" aria-hidden="true"></i><span>Configurar Gemini<small>Modelo e conexão de IA</small></span><i data-lucide="arrow-up-right" aria-hidden="true"></i></a></div></section>
</div>
<?php $content=(string)ob_get_clean(); require __DIR__.'/../layouts/admin.php';
