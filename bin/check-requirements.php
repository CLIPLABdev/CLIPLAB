#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Config;
use App\Media\MediaPipeAssetManifestVerifier;
use App\Media\UploadLimits;
use App\Process\ProcessRunner;
use App\Queue\WorkerLeaseBudget;

require dirname(__DIR__) . '/bootstrap/app.php';

$checkerProjectRoot = defined('CHECK_REQUIREMENTS_PROJECT_ROOT')
    ? (string) constant('CHECK_REQUIREMENTS_PROJECT_ROOT')
    : dirname(__DIR__);

function requirementLine(string $level, string $name, ?string $detail = null): void
{
    echo '[' . $level . '] ' . $name . ($detail === null ? '' : ': ' . $detail) . PHP_EOL;
}

$mandatory = [
    'PHP 8.0 ou superior' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'Extensão PDO' => extension_loaded('pdo'),
    'Driver PDO MySQL' => extension_loaded('pdo_mysql'),
    'Extensão mbstring' => extension_loaded('mbstring'),
    'Extensão fileinfo' => extension_loaded('fileinfo'),
    'Extensão curl' => extension_loaded('curl'),
    'PHP CLI' => PHP_SAPI === 'cli',
];
$failed = false;
foreach ($mandatory as $name => $available) {
    requirementLine($available ? 'OK' : 'FALHA', $name);
    $failed = $failed || !$available;
}

$uploadLimit = (string) ini_get('upload_max_filesize');
$postLimit = (string) ini_get('post_max_size');
requirementLine('INFO', 'upload_max_filesize', $uploadLimit === '' ? 'não informado' : $uploadLimit);
requirementLine('INFO', 'post_max_size', $postLimit === '' ? 'não informado' : $postLimit);
$uploadBytes = UploadLimits::parseIniBytes($uploadLimit);
$postBytes = UploadLimits::parseIniBytes($postLimit);
if ($uploadBytes !== null && $postBytes !== null && $postBytes <= $uploadBytes) {
    requirementLine('WARN', 'Limites de envio', 'configure post_max_size acima de upload_max_filesize');
}

$media = (array) Config::get('media', []);
$assetRoot = $checkerProjectRoot . '/public/assets/vendor/mediapipe-tasks-vision-1.0.1';
$mediaPipeAssetsValid = (new MediaPipeAssetManifestVerifier())->verify($assetRoot);
requirementLine(
    $mediaPipeAssetsValid ? 'OK' : 'FALHA',
    'Assets MediaPipe',
    $mediaPipeAssetsValid ? 'manifesto versionado íntegro' : 'artefato versionado ausente ou inválido'
);
$failed = $failed || !$mediaPipeAssetsValid;

$reframeConfigurationValid = (string) ($media['mediapipe_asset_version'] ?? '') === MediaPipeAssetManifestVerifier::VERSION
    && requirementInteger('REFRAME_MAX_KEYFRAMES', 32, 32, 32) === 32
    && requirementInteger('REFRAME_MAX_DURATION_SECONDS', 90, 1, 180) !== null
    && requirementInteger('REFRAME_PREVIEW_MAX_FRAMES', 180, 2, 180) !== null
    && requirementInteger('REFRAME_PREVIEW_MAX_EDGE', 320, 64, 320) !== null;
requirementLine(
    $reframeConfigurationValid ? 'OK' : 'FALHA',
    'Configuração Smart Reframe',
    $reframeConfigurationValid ? 'versão e limites suportados' : 'versão ou limites não suportados'
);
$failed = $failed || !$reframeConfigurationValid;

$workerDocumentCspValid = requirementFileContains(
    $checkerProjectRoot . '/app/Middleware/SecurityHeadersMiddleware.php',
    "worker-src 'self'"
);
$workerResponseCspDirective = 'Header always set Content-Security-Policy "default-src \'none\'; script-src \'self\' \'wasm-unsafe-eval\'; connect-src \'self\'"';
$workerResponseCspValid = true;
foreach ([$checkerProjectRoot . '/.htaccess', $checkerProjectRoot . '/public/.htaccess'] as $apacheConfig) {
    $workerResponseCspValid = requirementFileContains($apacheConfig, $workerResponseCspDirective)
        && $workerResponseCspValid;
}
$workerCspValid = $workerDocumentCspValid && $workerResponseCspValid;
requirementLine($workerCspValid ? 'OK' : 'FALHA', 'CSP do worker');
$failed = $failed || !$workerCspValid;

$mimeDirectives = [
    'AddType application/javascript .mjs',
    'AddType application/wasm .wasm',
    'AddType application/octet-stream .tflite',
];
$mimeValid = true;
foreach ([$checkerProjectRoot . '/.htaccess', $checkerProjectRoot . '/public/.htaccess'] as $apacheConfig) {
    foreach ($mimeDirectives as $directive) {
        $mimeValid = requirementFileContains($apacheConfig, $directive) && $mimeValid;
    }
}
requirementLine($mimeValid ? 'OK' : 'FALHA', 'MIME MediaPipe');
$failed = $failed || !$mimeValid;

try {
    $databaseSupported = requirementDatabaseSupported(requirementDatabaseCapability());
} catch (Throwable) {
    $databaseSupported = false;
}
requirementLine(
    $databaseSupported ? 'OK' : 'FALHA',
    'Banco SQL com CHECK',
    $databaseSupported ? 'engine e constraints suportados' : 'engine, versão ou CHECK não suportado'
);
$failed = $failed || !$databaseSupported;

$configuredMaxBytes = (int) ($media['configured_max_upload_bytes'] ?? $media['max_upload_bytes'] ?? 524288000);
$effectiveMaxBytes = (int) ($media['effective_upload_bytes'] ?? $configuredMaxBytes);
$phpCapacityBytes = $media['php_upload_capacity_bytes'] ?? null;
if (is_int($phpCapacityBytes) && $configuredMaxBytes > $phpCapacityBytes) {
    requirementLine(
        'WARN',
        'Capacidade efetiva de upload',
        'MEDIA_MAX_UPLOAD_BYTES excede a capacidade do PHP; limite aplicado: ' . $effectiveMaxBytes . ' bytes'
    );
}

$privateRoot = (string) ($media['private_root'] ?? '');
$storageDirectory = $privateRoot !== '' && is_dir($privateRoot);
$storageReadable = $storageDirectory && is_readable($privateRoot);
$storageWritable = $storageDirectory && is_writable($privateRoot);

function requirementInteger(string $name, int $default, int $minimum, int $maximum): ?int
{
    $raw = getenv($name);
    if ($raw === false) {
        return $default;
    }
    if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $raw) !== 1 || strlen($raw) > strlen((string) PHP_INT_MAX)) {
        return null;
    }
    $value = (int) $raw;
    if ((string) $value !== $raw || $value < $minimum || $value > $maximum) {
        return null;
    }

    return $value;
}

/** @return array{driver:string,version:string,check_constraints:?bool} */
function requirementDatabaseCapability(): array
{
    if (defined('CHECK_REQUIREMENTS_DATABASE_CAPABILITY')) {
        $capability = constant('CHECK_REQUIREMENTS_DATABASE_CAPABILITY');
        if (is_array($capability)) {
            return [
                'driver' => is_string($capability['driver'] ?? null) ? $capability['driver'] : '',
                'version' => is_string($capability['version'] ?? null) ? $capability['version'] : '',
                'check_constraints' => is_bool($capability['check_constraints'] ?? null)
                    ? $capability['check_constraints']
                    : null,
            ];
        }
    }

    $database = (array) Config::get('database', []);
    $pdo = new PDO(
        (string) ($database['dsn'] ?? ''),
        is_string($database['username'] ?? null) ? $database['username'] : null,
        is_string($database['password'] ?? null) ? $database['password'] : null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $checks = null;
    if (stripos($version, 'mariadb') !== false) {
        $checks = (string) $pdo->query('SELECT @@check_constraint_checks')->fetchColumn() === '1';
    }

    return ['driver' => $driver, 'version' => $version, 'check_constraints' => $checks];
}

function requirementDatabaseSupported(array $capability): bool
{
    if (($capability['driver'] ?? null) !== 'mysql' || !is_string($capability['version'] ?? null)) {
        return false;
    }
    if (preg_match('/([0-9]+\.[0-9]+\.[0-9]+)/', $capability['version'], $matches) !== 1) {
        return false;
    }
    if (stripos($capability['version'], 'mariadb') !== false) {
        return version_compare($matches[1], '10.4.0', '>=')
            && ($capability['check_constraints'] ?? null) === true;
    }

    return version_compare($matches[1], '8.0.16', '>=');
}

function requirementFileContains(string $path, string $needle): bool
{
    $contents = @file_get_contents($path);

    return is_string($contents) && str_contains($contents, $needle);
}

function configuredBinaryAvailable(string $binary, string $expectedWindowsBasename): bool
{
    if ($binary === '' || trim($binary) !== $binary || strpos($binary, "\0") !== false) {
        return false;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        $binaryName = strtolower(basename(str_replace('\\', '/', $binary)));
        if (!in_array($binaryName, [$expectedWindowsBasename, $expectedWindowsBasename . '.exe'], true)) {
            return false;
        }
    }
    if (configuredBinaryIsAbsolute($binary)) {
        return configuredBinaryFileAvailable($binary);
    }
    if (str_contains($binary, '/') || str_contains($binary, '\\')
        || (DIRECTORY_SEPARATOR === '\\' && str_contains($binary, ':'))
    ) {
        return false;
    }
    $names = configuredBinaryNames($binary);
    if ($names === []) {
        return false;
    }
    $path = getenv('PATH');
    if (!is_string($path) || $path === '') {
        return false;
    }
    foreach (explode(PATH_SEPARATOR, $path) as $entry) {
        $directory = configuredBinaryPathDirectory($entry);
        if ($directory === null) {
            continue;
        }
        foreach ($names as $name) {
            if (configuredBinaryFileAvailable($directory . DIRECTORY_SEPARATOR . $name)) {
                return true;
            }
        }
    }

    return false;
}

function configuredBinaryFileAvailable(string $candidate): bool
{
    $resolved = realpath($candidate);
    if ($resolved === false || !is_file($resolved)) {
        return false;
    }
    if (DIRECTORY_SEPARATOR === '\\'
        && !in_array('.' . strtolower(pathinfo($resolved, PATHINFO_EXTENSION)), ['.com', '.exe'], true)
    ) {
        return false;
    }

    return is_executable($resolved);
}

/** @return list<string> */
function configuredBinaryNames(string $binary): array
{
    if (DIRECTORY_SEPARATOR !== '\\') {
        return [$binary];
    }
    $extensions = configuredWindowsExecutableExtensions();
    $extension = pathinfo($binary, PATHINFO_EXTENSION);
    if ($extension !== '') {
        return in_array('.' . strtolower($extension), $extensions, true) ? [$binary] : [];
    }

    return array_map(static fn (string $suffix): string => $binary . $suffix, $extensions);
}

/** @return list<string> */
function configuredWindowsExecutableExtensions(): array
{
    $configured = getenv('PATHEXT');
    $parts = is_string($configured) ? explode(';', $configured) : [];
    $extensions = [];
    foreach ($parts as $part) {
        $extension = strtolower(trim($part));
        if (!in_array($extension, ['.com', '.exe'], true) || in_array($extension, $extensions, true)) {
            continue;
        }
        $extensions[] = $extension;
    }

    return $extensions === [] ? ['.com', '.exe'] : $extensions;
}

function configuredBinaryPathDirectory(string $entry): ?string
{
    if (DIRECTORY_SEPARATOR === '\\') {
        $entry = trim($entry);
        if (strlen($entry) >= 2 && $entry[0] === '"' && $entry[strlen($entry) - 1] === '"') {
            $entry = substr($entry, 1, -1);
        }
        if (str_contains($entry, '"')) {
            return null;
        }
    }
    if ($entry === '' || !configuredBinaryIsAbsolute($entry)) {
        return null;
    }
    $resolved = realpath($entry);

    return $resolved !== false && is_dir($resolved) ? $resolved : null;
}

function configuredBinaryIsAbsolute(string $path): bool
{
    if ($path === '') {
        return false;
    }
    if (DIRECTORY_SEPARATOR !== '\\') {
        return $path[0] === '/';
    }
    $drive = ord($path[0]);
    $driveIsAsciiLetter = ($drive >= 65 && $drive <= 90) || ($drive >= 97 && $drive <= 122);
    if (strlen($path) >= 3 && $driveIsAsciiLetter && $path[1] === ':'
        && ($path[2] === '\\' || $path[2] === '/')
    ) {
        return true;
    }

    return strlen($path) >= 2
        && (($path[0] === '\\' && $path[1] === '\\') || ($path[0] === '/' && $path[1] === '/'));
}
requirementLine($storageReadable ? 'OK' : 'FALHA', 'Armazenamento privado legível');
requirementLine($storageWritable ? 'OK' : 'FALHA', 'Armazenamento privado gravável');
$processTemporaryDirectoryReady = false;
if ($storageWritable) {
    try {
        new ProcessRunner([
            (string) ($media['ffprobe_binary'] ?? 'ffprobe'),
            (string) ($media['ffmpeg_binary'] ?? 'ffmpeg'),
        ], $privateRoot);
        $processTemporaryDirectoryReady = true;
    } catch (Throwable) {
        $processTemporaryDirectoryReady = false;
    }
}
requirementLine($processTemporaryDirectoryReady ? 'OK' : 'FALHA', 'Diretório temporário privado');
$failed = $failed || !$storageReadable || !$storageWritable || !$processTemporaryDirectoryReady;

$gemini = (array) Config::get('gemini', []);
$geminiKeyConfigured = trim((string) ($gemini['api_key'] ?? '')) !== '';
$geminiModelConfigured = trim((string) ($gemini['model'] ?? '')) !== '';
requirementLine($geminiKeyConfigured ? 'OK' : 'WARN', 'Chave Gemini', $geminiKeyConfigured ? 'configurada' : 'não configurada');
requirementLine($geminiModelConfigured ? 'OK' : 'WARN', 'Modelo Gemini', $geminiModelConfigured ? 'configurado' : 'não configurado');

$queue = is_array($media['queue'] ?? null) ? $media['queue'] : [];
$leaseSeconds = (int) ($queue['lease_seconds'] ?? 300);
$httpTimeoutSeconds = (int) ($gemini['http_timeout_seconds'] ?? 180);
$renderTimeoutSeconds = (int) ($media['render_timeout_seconds'] ?? 240);
$downloadTimeoutSeconds = (int) ($media['download_timeout_seconds'] ?? 120);
$processTimeoutSeconds = (int) ($media['process_timeout_seconds'] ?? 60);
$leaseConfigurationValid = true;
$leaseSafe = false;
try {
    $requiredLease = WorkerLeaseBudget::requiredSeconds(
        $httpTimeoutSeconds,
        $renderTimeoutSeconds,
        $downloadTimeoutSeconds,
        $processTimeoutSeconds,
        30,
        true,
        ($media['youtube_import_enabled'] ?? false) ? (int) ($media['yt_dlp_timeout_seconds'] ?? 60) : 0,
        ($media['youtube_import_enabled'] ?? false) ? (int) ($media['process_timeout_seconds'] ?? 60) : 0
    );
    $leaseSafe = $leaseSeconds >= $requiredLease;
} catch (InvalidArgumentException|OverflowException) {
    $leaseConfigurationValid = false;
} catch (Throwable) {
    $leaseConfigurationValid = false;
}
requirementLine(
    $leaseConfigurationValid && $leaseSafe ? 'OK' : 'FALHA',
    'Lease do worker para Gemini/renderização',
    !$leaseConfigurationValid
        ? 'configuração de timeout inválida'
        : ($leaseSafe
            ? 'cobre o timeout com margem de segurança'
            : 'configure QUEUE_LEASE_SECONDS com pelo menos 30 segundos de margem')
);
$failed = $failed || !$leaseConfigurationValid || !$leaseSafe;

$cronBudgetSeconds = 50;
$longestOperation = max(
    $downloadTimeoutSeconds,
    $processTimeoutSeconds,
    $httpTimeoutSeconds,
    $renderTimeoutSeconds
);
$cronFits = $longestOperation <= $cronBudgetSeconds;
requirementLine(
    $cronFits ? 'OK' : 'WARN',
    'Orçamento operacional do cron',
    $cronFits
        ? 'compatível com a execução curta configurada'
        : 'uploads ou chamadas externas podem exigir execuções repetidas ou um worker VPS'
);

$procOpen = function_exists('proc_open');
requirementLine($procOpen ? 'OK' : 'WARN', 'proc_open', $procOpen ? 'disponível' : 'indisponível para processamento local');
$ffprobeAvailable = configuredBinaryAvailable((string) ($media['ffprobe_binary'] ?? 'ffprobe'), 'ffprobe');
$ffmpegAvailable = configuredBinaryAvailable((string) ($media['ffmpeg_binary'] ?? 'ffmpeg'), 'ffmpeg');
requirementLine($ffprobeAvailable ? 'OK' : 'WARN', 'FFprobe', $ffprobeAvailable ? 'disponível' : 'indisponível para processamento local');
requirementLine($ffmpegAvailable ? 'OK' : 'WARN', 'FFmpeg', $ffmpegAvailable ? 'disponível' : 'indisponível para renderização local');
if (!$procOpen || !$ffprobeAvailable || !$ffmpegAvailable) {
    requirementLine('WARN', 'Ação necessária', 'configure o mesmo artefato PHP em um worker VPS com o mesmo banco e armazenamento privado compartilhados');
}

exit($failed ? 1 : 0);
