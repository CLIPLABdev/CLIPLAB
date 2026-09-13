<?php
declare(strict_types=1);
namespace App\Account;

use App\Media\Editor\BrandLogoPng;
use PDO;

/** Private, validated PNG avatars. Original filenames and MIME claims are never trusted. */
final class ProfileAvatarService
{
    private \Closure $isUpload;

    public function __construct(private PDO $pdo, private string $directory, ?callable $isUpload=null)
    {
        $this->directory = rtrim($directory,'/\\');
        $this->isUpload = $isUpload===null ? static fn(string $path): bool=>is_uploaded_file($path) : \Closure::fromCallable($isUpload);
    }

    public function store(int $userId,array $upload): void
    {
        $path = $upload['tmp_name']??null;
        if (($upload['error']??null)!==UPLOAD_ERR_OK || !is_string($path) || $path==='' || !(($this->isUpload)($path))) $this->invalid();
        $size = filesize($path);
        if ($size===false || $size<1 || $size>BrandLogoPng::MAX_BYTES) $this->invalid();
        $bytes = file_get_contents($path,false,null,0,BrandLogoPng::MAX_BYTES+1);
        if (!is_string($bytes)) $this->invalid();
        try { BrandLogoPng::dimensions($bytes); } catch (\InvalidArgumentException $error) { $this->invalid(); }
        $this->activeUser($userId);
        $ownerDirectory = $this->directory.'/'.$userId;
        if (!is_dir($ownerDirectory) && !mkdir($ownerDirectory,0700,true) && !is_dir($ownerDirectory)) throw new \RuntimeException('Avatar storage is unavailable.');
        $root = realpath($this->directory);
        $owner = realpath($ownerDirectory);
        if ($root===false || $owner===false || !str_starts_with(str_replace('\\','/',$owner).'/',str_replace('\\','/',$root).'/')) throw new \RuntimeException('Avatar storage is invalid.');
        $filename = bin2hex(random_bytes(24)).'.png';
        $destination = $owner.'/'.$filename;
        $handle = fopen($destination,'xb');
        if ($handle===false) throw new \RuntimeException('Avatar could not be stored.');
        try {
            $written = fwrite($handle,$bytes);
        } finally {
            fclose($handle);
        }
        try {
            if ($written!==strlen($bytes)) throw new \RuntimeException('Avatar could not be stored.');
            $statement = $this->pdo->prepare("UPDATE users SET avatar_path=:path WHERE id=:id AND status='active'");
            $statement->execute(['path'=>$userId.'/'.$filename,'id'=>$userId]);
            if ($statement->rowCount()!==1) throw new ProfileValidationException(['avatar'=>'Conta indisponível para esta alteração.']);
        } catch (\Throwable $exception) {
            // This exact file was created by this request; previous files are never removed.
            if (is_file($destination)) unlink($destination);
            throw $exception;
        }
    }

    public function remove(int $userId): void
    {
        $this->activeUser($userId);
        $statement = $this->pdo->prepare("UPDATE users SET avatar_path=NULL WHERE id=:id AND status='active'");
        $statement->execute(['id'=>$userId]);
    }

    public function pathForUser(int $userId): ?string
    {
        $user = $this->activeUser($userId);
        $relative = (string)($user['avatar_path']??'');
        if (preg_match('#^'.preg_quote((string)$userId,'#').'/[a-f0-9]{48}\.png$#D',$relative)!==1) return null;
        $path = realpath($this->directory.'/'.$relative);
        $owner = realpath($this->directory.'/'.$userId);
        if ($path===false || $owner===false || !is_file($path) || !str_starts_with(str_replace('\\','/',$path),str_replace('\\','/',$owner).'/')) return null;
        return $path;
    }

    private function activeUser(int $userId): array
    {
        $statement = $this->pdo->prepare("SELECT id,avatar_path FROM users WHERE id=:id AND status='active' LIMIT 1");
        $statement->execute(['id'=>$userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if ($user===false) throw new ProfileValidationException(['avatar'=>'Conta indisponível para esta alteração.']);
        return $user;
    }

    private function invalid(): void
    {
        throw new ProfileValidationException(['avatar'=>'Envie um PNG válido de até 2 MiB e 2048 × 2048 pixels.']);
    }
}
