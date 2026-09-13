<?php
declare(strict_types=1);
namespace App\Services;

use App\Repositories\PromotionRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class PromotionService
{
    public function __construct(private PromotionRepository $promotions,private DateTimeImmutable $clock) {}

    public function create(array $input): int { return $this->promotions->create($this->normalize($input)); }
    public function update(int $id,array $input): void
    {
        if ($id<=0) throw new InvalidArgumentException('Promotion is invalid.');
        $this->promotions->update($id,$this->normalize($input));
    }
    public function delete(int $id): void
    {
        if ($id<=0) throw new InvalidArgumentException('Promotion is invalid.');
        $this->promotions->delete($id);
    }
    public function all(): array { return $this->promotions->all(); }

    public function visibleForUser(int $userId,array $identity): array
    {
        if ($userId<=0 || ($identity['status']??'')!=='active') return [];
        return array_values(array_filter($this->promotions->activeAt($this->clock->setTimezone(new DateTimeZone('UTC'))),static function(array $item) use($userId,$identity):bool {
            return match($item['audience']) {
                'all'=>true,
                'user'=>(int)$item['user_id']===$userId,
                'plan'=>(int)$item['plan_id']===(int)($identity['plan_id']??0),
                default=>false,
            };
        }));
    }

    private function normalize(array $input): array
    {
        $title=$this->text($input['title']??null,120,true);
        $body=$this->text($input['body']??null,500,true);
        $kind=$this->text($input['delivery_kind']??'banner',10,true);
        $placement=$this->text($input['placement']??null,20,true);
        $audience=$this->text($input['audience']??null,10,true);
        if (!in_array($kind,['banner','popup','notice'],true)||!in_array($placement,['dashboard','projects','account'],true)||!in_array($audience,['all','user','plan'],true)) throw new InvalidArgumentException('Promotion is invalid.');
        $cta=$this->text($input['cta_url']??'',255);
        $label=$this->text($input['cta_label']??'',60);
        if ($cta!==''&&!$this->safeLink($cta)) throw new InvalidArgumentException('Promotion link is invalid.');
        $image=$this->text($input['image_url']??'',255);
        if ($image!==''&&preg_match('#^/assets/images/[a-z0-9][a-z0-9._-]{0,120}\.(?:png|jpe?g|webp)$#iD',$image)!==1) throw new InvalidArgumentException('Promotion image is invalid.');
        $plan=$audience==='plan'?$this->id($input['plan_id']??null):null;
        $user=$audience==='user'?$this->id($input['user_id']??null):null;
        $start=$this->date($input['starts_at']??null);
        $end=$this->date($input['ends_at']??null);
        if ($start!==null&&$end!==null&&$end<=$start) throw new InvalidArgumentException('Promotion dates are invalid.');
        $active=$input['is_active']??false;
        if (!is_bool($active)) throw new InvalidArgumentException('Promotion status is invalid.');
        return ['title'=>$title,'body'=>$body,'cta_label'=>$label,'cta_url'=>$cta?:null,'image_url'=>$image?:null,'delivery_kind'=>$kind,'placement'=>$placement,'audience'=>$audience,'plan_id'=>$plan,'user_id'=>$user,'starts_at'=>$start?->format('Y-m-d H:i:s'),'ends_at'=>$end?->format('Y-m-d H:i:s'),'is_active'=>$active];
    }

    private function text(mixed $value,int $max,bool $required=false): string
    {
        if (!is_string($value)||mb_strlen(trim($value))>$max||($required&&trim($value)==='')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value)) throw new InvalidArgumentException('Promotion field is invalid.');
        return trim($value);
    }
    private function id(mixed $value): int
    {
        if (is_int($value)&&$value>0) return $value;
        if (!is_string($value)||preg_match('/^[1-9][0-9]{0,18}$/D',$value)!==1||filter_var($value,FILTER_VALIDATE_INT)===false) throw new InvalidArgumentException('Promotion audience is invalid.');
        return (int)$value;
    }
    private function safeLink(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7F\\\\]/',$url)) return false;
        if (str_starts_with($url,'/')&&!str_starts_with($url,'//')) return true;
        $parts=parse_url($url);
        return is_array($parts)&&($parts['scheme']??'')==='https'&&!empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass'])&&filter_var($url,FILTER_VALIDATE_URL)!==false;
    }
    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value===null||$value==='') return null;
        if (!is_string($value)) throw new InvalidArgumentException('Promotion date is invalid.');
        foreach(['Y-m-d\TH:i','Y-m-d H:i:s'] as $format) {
            $date=DateTimeImmutable::createFromFormat('!'.$format,$value,new DateTimeZone('UTC'));
            if ($date!==false&&$date->format($format)===$value) return $date;
        }
        throw new InvalidArgumentException('Promotion date is invalid.');
    }
}
