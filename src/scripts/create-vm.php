#!/usr/bin/php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/VMProvisioner.php';

/** @param array<string,mixed> $state */
function writeStatus(string $path, array $state): void
{
    $state['updated_at'] = gmdate(DATE_ATOM);
    $tmp = $path . '.tmp.' . getmypid();
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !chmod($tmp, 0600) || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Could not write status file: {$path}");
    }
}

function validJobPath(string $path, string $suffix): bool
{
    $root = VMProvisioner::JOB_ROOT . '/';
    return str_starts_with($path, $root)
        && str_ends_with($path, $suffix)
        && !str_contains($path, "\0")
        && !preg_match('#(?:^|/)\.\.(?:/|$)#', $path);
}

$options = getopt('', ['spec:', 'log:', 'status:']);
$specPath = (string)($options['spec'] ?? '');
$logPath = (string)($options['log'] ?? '');
$statusPath = (string)($options['status'] ?? '');

if (!validJobPath($specPath, '.json') || !validJobPath($logPath, '.log') || !validJobPath($statusPath, '.status.json')) {
    fwrite(STDERR, "Invalid job path.\n");
    exit(2);
}

try {
    if (!is_file($specPath)) {
        throw new RuntimeException("Job specification not found: {$specPath}");
    }
    $spec = json_decode((string)file_get_contents($specPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($spec)) {
        throw new RuntimeException('Job specification must be a JSON object.');
    }
    file_put_contents($logPath, '', LOCK_EX);
    chmod($logPath, 0600);
    writeStatus($statusPath, [
        'state' => 'running',
        'name' => (string)($spec['name'] ?? ''),
        'started_at' => gmdate(DATE_ATOM),
        'pid' => getmypid(),
    ]);

    $result = (new VMProvisioner())->provision($spec, $logPath);
    writeStatus($statusPath, [
        'state' => 'complete',
        'name' => $result['name'],
        'started_at' => (string)(json_decode((string)file_get_contents($statusPath), true)['started_at'] ?? ''),
        'finished_at' => gmdate(DATE_ATOM),
        'result' => $result,
    ]);
    exit(0);
} catch (Throwable $error) {
    file_put_contents($logPath, "\nERROR: " . $error->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    try {
        writeStatus($statusPath, [
            'state' => 'failed',
            'name' => isset($spec) && is_array($spec) ? (string)($spec['name'] ?? '') : '',
            'finished_at' => gmdate(DATE_ATOM),
            'error' => $error->getMessage(),
        ]);
    } catch (Throwable) {
        // The original error is more useful than a secondary status-write error.
    }
    exit(1);
}
