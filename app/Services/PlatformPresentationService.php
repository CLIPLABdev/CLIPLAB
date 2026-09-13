<?php
declare(strict_types=1);
namespace App\Services;

use App\Repositories\PlatformSettingsRepository;
use App\Repositories\PromotionRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Request-scoped view data; never exposes credential or private storage columns. */
final class PlatformPresentationService
{
    private ?array $branding=null;
    public function __construct(private PDO $pdo,private string $publicRoot,private array $features=[],private ?DateTimeImmutable $now=null)
    {
        $this->publicRoot=rtrim($publicRoot,'/\\');
        $this->now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    }

    public function context(string $view,array $data): array
    {
        if ($this->branding===null) {
            $this->branding=(new PlatformSettingsService(new PlatformSettingsRepository($this->pdo)))->branding();
            $this->branding['logo_url']=$this->localAsset($this->branding['logo_url']);
            $this->branding['favicon_url']=$this->localAsset($this->branding['favicon_url']);
        }
        $context=['platformBranding'=>$this->branding,'platformFeatures'=>$this->features,'notificationUnread'=>0,'headerAvatar'=>false,'currentPromotions'=>[]];
        $id=(int)($data['user']['id']??0);
        if ($id<=0) return $context;
        $identity=$this->pdo->prepare('SELECT id,plan_id,status,avatar_path FROM users WHERE id=:id LIMIT 1');
        $identity->execute(['id'=>$id]);
        $user=$identity->fetch(PDO::FETCH_ASSOC);
        if ($user===false || $user['status']!=='active') return $context;
        $avatar=(string)($user['avatar_path']??'');
        $context['headerAvatar']=preg_match('#^'.preg_quote((string)$id,'#').'/[a-f0-9]{48}\.png$#D',$avatar)===1;
        if (!empty($this->features['communications'])) {
            $unread=$this->pdo->prepare('SELECT COUNT(*) FROM communication_notifications WHERE user_id=:id AND read_at IS NULL');
            $unread->execute(['id'=>$id]);
            $context['notificationUnread']=(int)$unread->fetchColumn();
        }
        $placement=str_starts_with($view,'dashboard.')?'dashboard':(str_starts_with($view,'project')?'projects':'account');
        $promotions=(new PromotionService(new PromotionRepository($this->pdo),$this->now))->visibleForUser($id,$user);
        foreach ($promotions as $item) {
            if ($item['placement']!==$placement) continue;
            $item['id']=(int)$item['id'];
            $item['image_url']=$this->localAsset($item['image_url']??null);
            $item['cta_url']=$this->safeHref($item['cta_url']??null);
            $item['delivery_kind']=in_array($item['delivery_kind']??null,['banner','notice','popup'],true)?$item['delivery_kind']:'banner';
            $context['currentPromotions'][]=$item;
            if (count($context['currentPromotions'])>=3) break;
        }
        return $context;
    }

    private function localAsset(mixed $url): ?string
    {
        if (!is_string($url) || preg_match('#^/assets/images/[a-z0-9][a-z0-9._-]{0,120}\.(?:png|jpe?g|webp|ico)$#iD',$url)!==1) return null;
        $root=realpath($this->publicRoot.'/assets/images');
        $file=realpath($this->publicRoot.$url);
        if ($root===false || $file===false || !is_file($file) || !str_starts_with(str_replace('\\','/',$file),str_replace('\\','/',$root).'/')) return null;
        return $url;
    }

    private function safeHref(mixed $url): ?string
    {
        if (!is_string($url) || $url==='' || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) return null;
        if (str_starts_with($url,'/') && !str_starts_with($url,'//')) return $url;
        $parts=parse_url($url);
        if (!is_array($parts) || ($parts['scheme']??'')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
        return $url;
    }
}
