<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Core\View;
use PHPUnit\Framework\TestCase;
final class PromotionEditorPresentationTest extends TestCase
{
    public function testEveryPromotionHasEditableFieldsStatusAndSafeConfirmation(): void
    {
        $_SESSION=[];
        $html=(new View())->render('admin.promotions',['title'=>'Promoções','admin'=>['id'=>1,'name'=>'Admin','email'=>'a@example.test'],'plans'=>[['id'=>1,'name'=>'Free']],'promotions'=>[['id'=>7,'title'=>'<script>test</script>','body'=>'Mensagem','cta_label'=>'Ver','cta_url'=>'/conta/plano','image_url'=>null,'placement'=>'dashboard','audience'=>'all','plan_id'=>null,'user_id'=>null,'starts_at'=>null,'ends_at'=>null,'is_active'=>1,'delivery_kind'=>'popup']]])->body();
        self::assertStringContainsString('action="/admin/promocoes/7"',$html);
        self::assertStringContainsString('name="delivery_kind"',$html);
        self::assertStringContainsString('Salvar alterações',$html);
        self::assertStringContainsString('name="confirm"',$html);
        self::assertStringNotContainsString('<script>test</script>',$html);
    }
}
