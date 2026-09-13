<?php
declare(strict_types=1);
namespace App\Services;

use App\Contracts\PrivateStorage;
use App\Media\Editor\BrandLogoPng;
use App\Media\Editor\EditorOptions;
use App\Media\Editor\EditorTemplateCatalog;
use App\Repositories\EditorLibraryRepository;

final class EditorLibraryService
{
    private \Closure $admitBytes;
    private \Closure $uploadedFile;
    /** $admitBytes must enforce the account's total storage quota on this repository's PDO transaction. */
    public function __construct(private EditorLibraryRepository $repository, private PrivateStorage $storage, callable $admitBytes, ?callable $uploadedFile = null)
    {
        $this->admitBytes = \Closure::fromCallable($admitBytes);
        $this->uploadedFile = $uploadedFile === null ? static fn (string $path): bool => is_uploaded_file($path) : \Closure::fromCallable($uploadedFile);
    }

    public function catalog(int $userId): array
    {
        $logos = array_map(static fn (array $logo): array => ['id' => (int) $logo['id'], 'url' => '/marca/logos/' . (int) $logo['id'],
            'width' => (int) $logo['width'], 'height' => (int) $logo['height'], 'size_bytes' => (int) $logo['size_bytes']], $this->repository->listLogosOwned($userId));
        return ['presets' => EditorTemplateCatalog::all(), 'templates' => $this->repository->listOwned($userId), 'kit' => $this->repository->kitForUser($userId), 'logos' => $logos];
    }

    public function saveTemplate(int $userId, array $input): int
    {
        $id = isset($input['id']) && $input['id'] !== '' ? self::positiveId($input['id']) : null;
        return $this->repository->saveOwned($userId, $id, self::text($input['name'] ?? ''), self::text($input['category'] ?? 'custom'),
            self::options($input['options'] ?? []), self::text($input['aspect_ratio'] ?? '9:16'));
    }

    public function removeTemplate(int $userId, mixed $id): void
    {
        if (!$this->repository->removeOwned(self::positiveId($id), $userId)) throw new \OutOfBoundsException('Template não encontrado.');
    }

    public function applyTemplate(int $userId, mixed $id): void
    {
        $this->repository->withAccountLock($userId, function () use ($userId, $id): void {
            $snapshot = $this->repository->snapshot(self::positiveId($id), $userId);
            if ($snapshot === null) throw new \OutOfBoundsException('Template não encontrado.');
            $this->repository->saveKit($userId, EditorOptions::fromArray($snapshot['options']), $snapshot['aspect_ratio'], $this->repository->kitForUser($userId)['favorites']);
        });
    }

    public function saveKit(int $userId, array $input): void
    {
        $favorites = $input['favorites'] ?? [];
        if (!is_array($favorites)) throw new \InvalidArgumentException('Favoritos inválidos.');
        $this->repository->saveKit($userId, self::options($input['options'] ?? []), self::text($input['aspect_ratio'] ?? '9:16'), array_map([self::class, 'positiveId'], $favorites));
    }

    public function uploadLogo(int $userId, array $file): int
    {
        $path = $file['tmp_name'] ?? null;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($path) || !($this->uploadedFile)($path)) throw new \InvalidArgumentException('Selecione um arquivo PNG válido para enviar.');
        $bytes = @file_get_contents($path, false, null, 0, BrandLogoPng::MAX_BYTES + 1);
        if (!is_string($bytes)) throw new \InvalidArgumentException('Não foi possível ler o PNG.');
        $dimensions = BrandLogoPng::dimensions($bytes);
        $key = 'brand/' . $userId . '/' . bin2hex(random_bytes(24)) . '.png';
        $written = false;
        try {
            return $this->repository->withAccountLock($userId, function () use ($userId, $bytes, $dimensions, $key, &$written): int {
                $this->repository->assertLogoCapacity($userId);
                ($this->admitBytes)($userId, strlen($bytes));
                $stream = fopen('php://temp', 'w+b');
                if ($stream === false) throw new \RuntimeException('Armazenamento indisponível.');
                try {
                    fwrite($stream, $bytes); rewind($stream);
                    $object = $this->storage->putStream($stream, $key, BrandLogoPng::MAX_BYTES); $written = true;
                } finally { fclose($stream); }
                return $this->repository->addLogoOwned($userId, $object, $dimensions['width'], $dimensions['height']);
            });
        } catch (\Throwable $error) {
            // Only the newly created, uncommitted object can be removed. Published logo bytes never change.
            if ($written) $this->storage->delete($key);
            throw $error;
        }
    }

    public function logo(int $assetId, int $userId): ?array
    {
        $logo = $this->repository->findLogoOwned($assetId, $userId);
        if ($logo === null) return null;
        $bytes = @file_get_contents($this->storage->absolutePath($logo['object_key']), false, null, 0, BrandLogoPng::MAX_BYTES + 1);
        if (!is_string($bytes) || strlen($bytes) !== (int) $logo['size_bytes'] || !hash_equals($logo['sha256'], hash('sha256', $bytes))) return null;
        return ['bytes' => $bytes, 'size_bytes' => strlen($bytes)];
    }

    public static function options(mixed $input): EditorOptions
    {
        if (!is_array($input)) throw new \InvalidArgumentException('Opções inválidas.');
        return EditorOptions::fromForm($input);
    }
    public static function positiveId(mixed $value): int
    {
        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,17}\z/D', $value) === 1) $value = (int) $value;
        if (!is_int($value) || $value < 1) throw new \InvalidArgumentException('Identificador inválido.');
        return $value;
    }
    private static function text(mixed $value): string
    {
        if (!is_string($value)) throw new \InvalidArgumentException('Texto inválido.');
        return $value;
    }
}
