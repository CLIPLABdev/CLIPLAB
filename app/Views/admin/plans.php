<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Plans\PlanLimits;

/** @var list<array<string,mixed>> $plans */
$csrf = Csrf::token();
ob_start();
?>
<section class="admin-page-head"><div><p class="admin-eyebrow">Catálogo e limites</p><h2>O plano certo para cada conta.</h2><p>Defina preços, minutos, créditos e os recursos disponíveis em cada plano.</p></div></section>
<section class="admin-panel"><h2>Criar plano</h2><form class="admin-form-grid" method="post" action="/admin/planos"><input type="hidden" name="_token" value="<?= e($csrf) ?>"><label>Slug<input required pattern="[a-z][a-z0-9-]{1,62}" name="slug"></label><label>Nome<input required maxlength="100" name="name"></label><label>Preço em centavos<input required min="0" name="price_cents" value="0"></label><label>Minutos mensais<input required min="0" name="monthly_minutes" value="0"></label><label>Créditos<input required min="0" name="credits" value="0"></label><label>Upload máximo<input required min="1" name="max_upload_bytes" value="104857600"></label><label>Armazenamento<input required min="1" name="storage_bytes" value="1073741824"></label><div class="admin-checks"><label><input type="checkbox" name="exports_hd" value="1">Exportação HD</label><label><input type="checkbox" name="priority_processing" value="1">Prioridade</label><label><input type="checkbox" name="team_access" value="1">Equipe</label><label><input type="checkbox" name="is_active" value="1" checked>Ativo</label></div><button class="admin-button">Criar plano</button></form></section>
<section class="admin-plan-grid">
<?php foreach ($plans as $plan): if (!is_array($plan)) { continue; }
    $decoded = json_decode((string) ($plan['features'] ?? '{}'), true);
    try { $features = PlanLimits::fromFeatures(is_array($decoded) ? $decoded : [])->toArray(); } catch (\Throwable) { $features = PlanLimits::fromFeatures([])->toArray(); }
?>
    <article class="admin-panel"><div class="admin-panel-head"><div><p class="admin-eyebrow"><?= e((string) ($plan['slug'] ?? 'plano')) ?></p><h2><?= e((string) ($plan['name'] ?? 'Plano')) ?></h2></div><span class="admin-badge admin-badge-<?= (int) ($plan['is_active'] ?? 0) === 1 ? 'active' : 'suspended' ?>"><?= (int) ($plan['is_active'] ?? 0) === 1 ? 'ativo' : 'inativo' ?></span></div>
        <form class="admin-plan-form" method="post" action="/admin/planos/<?= (int) ($plan['id'] ?? 0) ?>">
            <input type="hidden" name="_token" value="<?= e($csrf) ?>">
            <label>Nome<input required maxlength="100" name="name" value="<?= e((string) ($plan['name'] ?? '')) ?>"></label>
            <label>Preço em centavos<input required min="0" inputmode="numeric" name="price_cents" value="<?= (int) ($plan['price_cents'] ?? 0) ?>"></label>
            <label>Minutos mensais<input required min="0" inputmode="numeric" name="monthly_minutes" value="<?= (int) ($plan['monthly_minutes'] ?? 0) ?>"></label>
            <label>Créditos incluídos<input required min="0" inputmode="numeric" name="credits" value="<?= (int) ($plan['credits'] ?? 0) ?>"></label>
            <label>Upload máximo em bytes<input required min="1" inputmode="numeric" name="max_upload_bytes" value="<?= (int) $features['limits']['max_upload_bytes'] ?>"></label>
            <label>Armazenamento em bytes<input required min="1" inputmode="numeric" name="storage_bytes" value="<?= (int) $features['limits']['storage_bytes'] ?>"></label>
            <div class="admin-checks"><label><input type="checkbox" name="exports_hd" value="1"<?= $features['exports_hd'] ? ' checked' : '' ?>>Exportação HD</label><label><input type="checkbox" name="priority_processing" value="1"<?= $features['priority_processing'] ? ' checked' : '' ?>>Prioridade</label><label><input type="checkbox" name="team_access" value="1"<?= $features['team_access'] ? ' checked' : '' ?>>Equipe</label><label><input type="checkbox" name="is_active" value="1"<?= (int) ($plan['is_active'] ?? 0) === 1 ? ' checked' : '' ?>>Plano ativo</label></div>
            <button class="admin-button" type="submit">Salvar plano</button>
        </form>
    </article>
<?php endforeach; ?>
</section>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/admin.php';
