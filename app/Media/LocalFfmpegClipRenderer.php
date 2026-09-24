<?php

declare(strict_types=1);

namespace App\Media;

use App\Contracts\ClipRenderer;
use App\Contracts\PrivateStorage;
use App\Exceptions\ClipRenderException;
use App\Media\Reframe\FfmpegReframeFilterBuilder;
use App\Media\Editor\VideoEffectsFilterBuilder;
use App\Media\Subtitles\AssDocumentBuilder;
use App\Process\ProcessExecutionException;
use App\Process\ProcessResult;
use App\Process\ProcessRunner;
use Throwable;

final class LocalFfmpegClipRenderer implements ClipRenderer
{
    private string $temporaryDirectory;
    private FfmpegReframeFilterBuilder $reframeFilters;
    private ?\Closure $logoResolver;

    public function __construct(
        private PrivateStorage $storage,
        private ProcessRunner $runner,
        private string $ffmpegBinary,
        string $temporaryDirectory,
        private int $totalTimeoutSeconds,
        private int $processOutputLimitBytes,
        private int $videoMaxBytes,
        private int $thumbnailMaxBytes,
        ?FfmpegReframeFilterBuilder $reframeFilters = null,
        ?callable $logoResolver = null,
        private ?string $ffprobeBinary = null
    ) {
        $this->logoResolver = $logoResolver === null ? null : \Closure::fromCallable($logoResolver);
        $this->reframeFilters = $reframeFilters ?? new FfmpegReframeFilterBuilder();
        $resolvedDirectory = realpath($temporaryDirectory);
        if (trim($ffmpegBinary) === ''
            || $resolvedDirectory === false
            || !is_dir($resolvedDirectory)
            || !is_writable($resolvedDirectory)
            || $totalTimeoutSeconds < 1
            || $processOutputLimitBytes < 1
            || $videoMaxBytes < 1
            || $thumbnailMaxBytes < 1
            || $this->isInsideProjectPublicRoot($resolvedDirectory)) {
            throw new \InvalidArgumentException('FFmpeg render configuration is invalid.');
        }

        $binaryName = strtolower(basename(str_replace('\\', '/', $ffmpegBinary)));
        if (DIRECTORY_SEPARATOR === '\\' && !in_array($binaryName, ['ffmpeg', 'ffmpeg.exe'], true)) {
            throw new \InvalidArgumentException('FFmpeg render configuration is invalid.');
        }
        if ($ffprobeBinary !== null && (trim($ffprobeBinary) === ''
            || (DIRECTORY_SEPARATOR === '\\' && !in_array(strtolower(basename(str_replace('\\', '/', $ffprobeBinary))), ['ffprobe','ffprobe.exe'], true)))) {
            throw new \InvalidArgumentException('FFprobe render configuration is invalid.');
        }

        $this->temporaryDirectory = rtrim($resolvedDirectory, DIRECTORY_SEPARATOR);
    }

    public function render(RenderClipRequest $request): RenderedClipArtifacts
    {
        $videoPath = null;
        $thumbnailPath = null;
        $assPath = null;
        try {
            if ($request->source()->storageDisk() !== 'local') {
                throw ClipRenderException::withCode('render_output_invalid');
            }
            $isOriginal = $request->reframePlan()->aspectRatio()->isOriginal();
            if (!$isOriginal && !$request->source()->hasUsableGeometry()) {
                throw ClipRenderException::withCode('render_output_invalid');
            }
            $sourcePath = $this->resolveSourcePath($request->source());
            $logoPath = $this->resolveLogoPath($request);
            if ($this->ffprobeBinary === null) {
                throw ClipRenderException::withCode('render_unavailable');
            }
            $videoPath = $this->newTemporaryPath('video', 'mp4');
            $thumbnailPath = $this->newTemporaryPath('thumbnail', 'jpg');
            $deadline = hrtime(true) + ($this->totalTimeoutSeconds * 1000000000);
            $filter = null;

            $command = [
                $this->ffmpegBinary,
                '-nostdin',
                '-hide_banner',
                '-loglevel', 'error',
                '-ss', $this->formatSeconds($request->startTime()),
                '-i', $sourcePath,
            ];
            if ($logoPath !== null) array_push($command,'-loop','1','-i',$logoPath);
            array_push($command,'-t',$this->formatSeconds($request->durationSeconds()));
            if ($logoPath === null) array_push($command,'-map','0:v:0','-map','0:a?');
            if (!$isOriginal) {
                try {
                    $filter = $this->reframeFilters->build(
                        $request->reframePlan(),
                        (int) $request->source()->width(),
                        (int) $request->source()->height()
                    );
                } catch (\InvalidArgumentException) {
                    throw ClipRenderException::withCode('render_output_invalid');
                }
            }
            $effectWidth=$isOriginal ? ($request->source()->width() ?? 2) : $request->reframePlan()->aspectRatio()->outputWidth();
            $effectHeight=$isOriginal ? ($request->source()->height() ?? 2) : $request->reframePlan()->aspectRatio()->outputHeight();
            $effects=(new VideoEffectsFilterBuilder())->build($request->editorOptions(),$effectWidth,$effectHeight,(int)round($request->durationSeconds()*1000));
            if ($effects!=='') {
                $this->outputDimensions($request,$isOriginal);
                $filter=$filter===null ? $effects : $filter.','.$effects;
            }
            if ($request->editorOptions()->hasOverlays()) {
                [$width, $height] = $this->outputDimensions($request, $isOriginal);
                $assPath = $this->newTemporaryPath('subtitles', 'ass');
                $document = AssDocumentBuilder::build(
                    $request->transcript(), $request->editorOptions(), $width, $height,
                    (int) round($request->durationSeconds() * 1000)
                );
                if (@file_put_contents($assPath, $document, LOCK_EX) !== strlen($document)) {
                    throw ClipRenderException::withCode('render_failed');
                }
                @chmod($assPath, 0600);
                $subtitleFilter = "subtitles=filename='" . $this->escapeFilterPath($assPath) . "'";
                $filter = $filter === null ? $subtitleFilter : $filter . ',' . $subtitleFilter;
            }
            if ($logoPath !== null) {
                [$width,$height] = $this->outputDimensions($request,$isOriginal);
                $logoInfo = getimagesize($logoPath);
                [$logoWidth,$logoHeight] = LogoDimensions::fit($logoInfo[0],$logoInfo[1],$width,$height,$request->editorOptions()->logoScale());
                $insets = OverlaySafeZone::logoInsets($width,$height);
                [$x,$y] = match ($request->editorOptions()->logoPosition()) {
                    'top_left'=>[(string)$insets['left'],(string)$insets['top']],
                    'bottom_left'=>[(string)$insets['left'],'main_h-overlay_h-'.$insets['bottom']],
                    'bottom_right'=>['main_w-overlay_w-'.$insets['right'],'main_h-overlay_h-'.$insets['bottom']],
                    default=>['main_w-overlay_w-'.$insets['right'],(string)$insets['top']],
                };
                $graph = '[0:v:0]'.($filter ?? 'setpts=PTS-STARTPTS').'[base];[1:v:0]scale='.$logoWidth.':'.$logoHeight.':flags=lanczos,setsar=1,format=rgba[logo];[base][logo]overlay=x='.$x.':y='.$y.':shortest=1[outv]';
                array_push($command,'-filter_complex',$graph,'-map','[outv]','-map','0:a?');
            } elseif ($filter !== null) { $command[] = '-vf'; $command[] = $filter; }
            array_push(
                $command,
                '-c:v', 'libx264',
                '-preset', 'veryfast',
                '-crf', '23',
                '-pix_fmt', 'yuv420p',
                '-movflags', '+faststart',
                '-c:a', 'aac',
                '-b:a', '128k',
                $videoPath
            );
            $this->runProcess($command, $deadline);
            [$videoSize, $videoMime] = $this->validateArtifact($videoPath, $this->videoMaxBytes, 'video/mp4');
            $probe = $this->runProcess([
                $this->ffprobeBinary, '-v', 'error', '-print_format', 'json', '-show_entries',
                'format=duration:stream=codec_type,duration,avg_frame_rate', $videoPath,
            ], $deadline);
            try {
                $metadata = json_decode($probe->stdout, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($metadata)) {
                    throw new \InvalidArgumentException('Rendered metadata is invalid.');
                }
                RenderedDurationValidator::validate($metadata, $request->durationSeconds());
            } catch (\JsonException | \InvalidArgumentException) {
                throw ClipRenderException::withCode('render_output_invalid');
            }

            $this->runProcess([
                $this->ffmpegBinary,
                '-nostdin',
                '-hide_banner',
                '-loglevel', 'error',
                '-ss', $this->formatSeconds($request->durationSeconds() / 2.0),
                '-i', $videoPath,
                '-frames:v', '1',
                '-vf', 'scale=640:-2:force_original_aspect_ratio=decrease',
                '-q:v', '2',
                $thumbnailPath,
            ], $deadline);
            [$thumbnailSize, $thumbnailMime] = $this->validateArtifact($thumbnailPath, $this->thumbnailMaxBytes, 'image/jpeg');

            $artifacts = new RenderedClipArtifacts(
                $videoPath,
                $videoSize,
                $videoMime,
                $thumbnailPath,
                $thumbnailSize,
                $thumbnailMime
            );
            $this->cleanupPaths([$assPath]);
            return $artifacts;
        } catch (ClipRenderException $exception) {
            $this->cleanupPaths([$videoPath, $thumbnailPath, $assPath]);
            throw $exception;
        } catch (Throwable) {
            $this->cleanupPaths([$videoPath, $thumbnailPath, $assPath]);
            throw ClipRenderException::withCode('render_failed');
        }
    }

    private function resolveSourcePath(ProjectSource $source): string
    {
        try {
            $path = $this->storage->absolutePath($source->objectKey());
        } catch (Throwable) {
            throw ClipRenderException::withCode('render_failed');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw ClipRenderException::withCode('render_output_invalid');
        }

        return $path;
    }

    private function resolveLogoPath(RenderClipRequest $request): ?string
    {
        $id=$request->editorOptions()->logoAssetId();
        if ($id===0) return null;
        if ($this->logoResolver===null) throw ClipRenderException::withCode('render_output_invalid');
        $key=($this->logoResolver)($id,$request->source()->projectId());
        if (!is_string($key) || $key==='') throw ClipRenderException::withCode('render_output_invalid');
        $path=$this->storage->absolutePath($key);
        $size=is_file($path) ? filesize($path) : false;
        $image=is_readable($path) ? @getimagesize($path) : false;
        if (!is_int($size) || $size<1 || $size>2*1024*1024 || !is_array($image)
            || ($image[2] ?? null)!==IMAGETYPE_PNG || $image[0]>2048 || $image[1]>2048) {
            throw ClipRenderException::withCode('render_output_invalid');
        }
        return $path;
    }

    /** @param list<string> $command */
    private function runProcess(array $command, int $deadline): ProcessResult
    {
        $timeoutSeconds = $this->remainingTimeoutSeconds($deadline);
        try {
            $result = $this->runner->run($command, $timeoutSeconds, $this->processOutputLimitBytes);
        } catch (ProcessExecutionException $exception) {
            throw ClipRenderException::withCode($this->mapProcessCode($exception->publicCode()));
        } catch (Throwable) {
            throw ClipRenderException::withCode('render_failed');
        }
        if (hrtime(true) >= $deadline) {
            throw ClipRenderException::withCode('render_timeout');
        }
        $this->validateProcessResult($result);
        return $result;
    }

    private function remainingTimeoutSeconds(int $deadline): int
    {
        $remainingNanoseconds = $deadline - hrtime(true);
        if ($remainingNanoseconds <= 0) {
            throw ClipRenderException::withCode('render_timeout');
        }

        return max(1, (int) ceil($remainingNanoseconds / 1000000000));
    }

    private function validateProcessResult(ProcessResult $result): void
    {
        if (strlen($result->stdout) + strlen($result->stderr) > $this->processOutputLimitBytes) {
            throw ClipRenderException::withCode('render_failed');
        }
        if ($result->exitCode !== 0) {
            throw ClipRenderException::withCode('render_failed');
        }
    }

    /** @return array{int, string} */
    private function validateArtifact(string $path, int $maximumBytes, string $expectedMime): array
    {
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : false;
        if (!is_int($size) || $size < 1 || $size > $maximumBytes || !class_exists(\finfo::class)) {
            throw ClipRenderException::withCode('render_output_invalid');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if ($mime !== $expectedMime) {
            throw ClipRenderException::withCode('render_output_invalid');
        }

        return [$size, $mime];
    }

    private function newTemporaryPath(string $kind, string $extension): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $path = $this->temporaryDirectory . DIRECTORY_SEPARATOR
                . 'cliplab-' . $kind . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
            if (!file_exists($path)) {
                return $path;
            }
        }

        throw ClipRenderException::withCode('render_failed');
    }

    private function formatSeconds(float $seconds): string
    {
        return number_format($seconds, 3, '.', '');
    }

    /** @return array{int,int} */
    private function outputDimensions(RenderClipRequest $request, bool $isOriginal): array
    {
        $width = $isOriginal ? $request->source()->width() : $request->reframePlan()->aspectRatio()->outputWidth();
        $height = $isOriginal ? $request->source()->height() : $request->reframePlan()->aspectRatio()->outputHeight();
        if (!is_int($width) || !is_int($height)) { throw ClipRenderException::withCode('render_output_invalid'); }
        return [$width, $height];
    }

    private function escapeFilterPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return str_replace([':', "'", '[', ']', ',', ';'], ['\\:', "\\'", '\\[', '\\]', '\\,', '\\;'], $path);
    }

    private function mapProcessCode(string $processCode): string
    {
        if ($processCode === 'process_unavailable') {
            return 'render_unavailable';
        }
        if ($processCode === 'process_timeout') {
            return 'render_timeout';
        }

        return 'render_failed';
    }

    /** @param list<string|null> $paths */
    private function cleanupPaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function isInsideProjectPublicRoot(string $directory): bool
    {
        $projectRoot = realpath(dirname(__DIR__, 2));
        if ($projectRoot === false) {
            return false;
        }
        foreach (['public', 'public_html'] as $name) {
            $publicRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . $name);
            if ($publicRoot === false) {
                continue;
            }
            $candidate = DIRECTORY_SEPARATOR === '\\' ? strtolower($directory) : $directory;
            $expected = DIRECTORY_SEPARATOR === '\\' ? strtolower($publicRoot) : $publicRoot;
            if ($candidate === $expected || str_starts_with($candidate, rtrim($expected, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
