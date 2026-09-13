<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Contracts\PrivateStorage;
use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ClipRepository;
use App\Services\PrivateRangeResponseFactory;
use Throwable;

final class ClipSourcePreviewController
{
    /** @var callable(int, int): ?array */
    private $source;

    /** @var callable(): PrivateStorage */
    private $storage;

    /**
     * @param ClipRepository|callable(int, int): ?array $clips
     * @param PrivateStorage|callable(): PrivateStorage $storage
     */
    public function __construct(
        ClipRepository|callable $clips,
        PrivateStorage|callable $storage,
        private PrivateRangeResponseFactory $files,
        private ?ErrorHandler $errors = null
    ) {
        $this->source = $clips instanceof ClipRepository
            ? static fn (int $clipId, int $userId): ?array => $clips->sourceForOwnedPreview($clipId, $userId)
            : $clips;
        $this->storage = $storage instanceof PrivateStorage ? static fn (): PrivateStorage => $storage : $storage;
        $this->errors ??= new ErrorHandler();
    }

    /** @param array<string, string> $parameters */
    public function show(Request $request, array $parameters): Response
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
            $source = ($this->source)($clipId, $userId);
            if (!is_array($source)
                || ($source['storage_disk'] ?? null) !== 'local'
                || !is_string($source['object_key'] ?? null)
                || $source['object_key'] === ''
                || !is_int($source['size_bytes'] ?? null)
                || $source['size_bytes'] < 1
                || !is_string($source['mime_type'] ?? null)
                || $source['mime_type'] === ''
            ) {
                return $this->notFound();
            }

            $path = ($this->storage)()->absolutePath($source['object_key']);
            if (!$this->isAbsolutePath($path)) {
                return $this->notFound();
            }

            return $this->files->preview(
                $path,
                $source['size_bytes'],
                $source['mime_type'],
                $request->header('Range')
            );
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
