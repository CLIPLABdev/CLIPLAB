<?php

declare(strict_types=1);

$nonce = getenv('CLIPLAB_TEST_SERVER_NONCE');
$configuredRoot = getenv('CLIPLAB_TEST_SERVER_ROOT');
$documentRoot = is_string($configuredRoot) ? realpath($configuredRoot) : false;
if (!is_string($nonce) || preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1 || $documentRoot === false) {
    http_response_code(500);

    return true;
}

$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($requestPath === '/__cliplab_test_server_ready') {
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=UTF-8');
    http_response_code(200);
    echo $nonce;

    return true;
}

$relativePath = ltrim(str_replace('/', DIRECTORY_SEPARATOR, rawurldecode((string) $requestPath)), DIRECTORY_SEPARATOR);
$candidate = realpath($documentRoot . DIRECTORY_SEPARATOR . $relativePath);
$comparableRoot = DIRECTORY_SEPARATOR === '\\' ? strtolower($documentRoot) : $documentRoot;
$comparableCandidate = is_string($candidate) && DIRECTORY_SEPARATOR === '\\' ? strtolower($candidate) : $candidate;
if (is_string($comparableCandidate)
    && str_starts_with($comparableCandidate, rtrim($comparableRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    && is_file($candidate)) {
    return false;
}

require $documentRoot . DIRECTORY_SEPARATOR . 'index.php';

return true;
