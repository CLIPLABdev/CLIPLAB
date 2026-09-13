<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Contracts\PrivateStorage;
use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ClipRepository;
use App\Services\PrivateFileResponseFactory;
use Throwable;

final class ClipAssetController
{
    /** @var callable(int, int, string): ?array */
    private $artifact;

    /** @var callable(): PrivateStorage */
    private $storage;

    /**
     * @param ClipRepository|callable(int, int, string): ?array $clips
     * @param PrivateStorage|callable(): PrivateStorage $storage
     */
    public function __construct(
        ClipRepository|callable $clips,
        PrivateStorage|callable $storage,
        private PrivateFileResponseFactory $files,
        private ?ErrorHandler $errors = null
    ) {
        $this->artifact = $clips instanceof ClipRepository
            ? static fn (int $clipId, int $userId, string $kind): ?array => $clips->artifactForOwnedClip($clipId, $userId, $kind)
            : $clips;
        $this->storage = $storage instanceof PrivateStorage ? static fn (): PrivateStorage => $storage : $storage;
        $this->errors ??= new ErrorHandler();
    }

    /** @param array<string, string> $parameters */
    public function thumbnail(Request $request, array $parameters): Response
    {
        return $this->asset($parameters, 'thumbnail');
    }

    /** @param array<string, string> $parameters */
    public function download(Request $request, array $parameters): Response
    {
        return $this->asset($parameters, 'video');
    }

    /** @param array<string, string> $parameters */
    private function asset(array $parameters, string $kind): Response
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId < 1) {
            return Response::redirect('/login');
        }
        $clipId = $this->clipId($parameters);
        if ($clipId === null) {
            return $this->notFound();
        }

        try {
            $artifact = ($this->artifact)($clipId, $userId, $kind);
            $expectedMime = $kind === 'video' ? 'video/mp4' : 'image/jpeg';
            if (!is_array($artifact)
                || !is_string($artifact['object_key'] ?? null)
                || ($artifact['object_key'] ?? '') === ''
                || !is_int($artifact['size_bytes'] ?? null)
                || $artifact['size_bytes'] < 1
                || ($artifact['mime_type'] ?? null) !== $expectedMime
            ) {
                return $this->notFound();
            }
            $path = ($this->storage)()->absolutePath($artifact['object_key']);
            if (!$this->isAbsolutePath($path)) {
                return $this->notFound();
            }

            return $kind === 'video'
                ? $this->files->download($path, 'clip-' . $clipId . '.mp4', $artifact['size_bytes'])
                : $this->files->thumbnail($path, $artifact['size_bytes']);
        } catch (Throwable) {
            return $this->notFound();
        }
    }

    /** @param array<string, string> $parameters */
    private function clipId(array $parameters): ?int
    {
        $raw = (string) ($parameters['id'] ?? '');
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $raw) !== 1) {
            return null;
        }
        $id = (int) $raw;

        return $id > 0 && (string) $id === $raw ? $id : null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{1,2})/', $path) === 1;
    }

    private function notFound(): Response
    {
        return $this->errors->renderStatus(404);
    }
}
