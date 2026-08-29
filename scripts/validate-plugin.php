#!/usr/bin/php
<?php

declare(strict_types=1);

$pluginPath = $argv[1] ?? dirname(__DIR__) . '/unraid-vm-assistant-php.plg';
$document = new DOMDocument();
libxml_use_internal_errors(true);
if (!$document->load($pluginPath, LIBXML_NONET)) {
    foreach (libxml_get_errors() as $error) {
        fwrite(STDERR, trim($error->message) . PHP_EOL);
    }
    fwrite(STDERR, "Invalid plugin XML: {$pluginPath}\n");
    exit(1);
}

$xpath = new DOMXPath($document);
$scripts = $xpath->query('/PLUGIN/FILE[@Run="/bin/bash"]/INLINE');
if ($scripts === false || $scripts->length !== 2) {
    fwrite(STDERR, "Expected exactly two embedded Bash scripts.\n");
    exit(1);
}

$vmScriptPath = dirname(__DIR__) . '/VM.sh';
$vmScript = file_get_contents($vmScriptPath);
if ($vmScript === false) {
    fwrite(STDERR, "Could not read standalone VM.sh.\n");
    exit(1);
}
$installerWithoutWhitespace = preg_replace('/\s+/', '', $scripts->item(0)->textContent);
if (!is_string($installerWithoutWhitespace) || !str_contains($installerWithoutWhitespace, base64_encode($vmScript))) {
    fwrite(STDERR, "Generated plugin does not contain the standalone VM.sh payload.\n");
    exit(1);
}

$vmLint = proc_open(['/bin/bash', '-n'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $vmPipes);
if (!is_resource($vmLint)) {
    throw new RuntimeException('Could not launch bash for VM.sh validation.');
}
fwrite($vmPipes[0], $vmScript);
fclose($vmPipes[0]);
fclose($vmPipes[1]);
$vmError = stream_get_contents($vmPipes[2]);
fclose($vmPipes[2]);
if (proc_close($vmLint) !== 0) {
    fwrite(STDERR, "Standalone VM.sh is invalid.\n{$vmError}");
    exit(1);
}

foreach ($scripts as $index => $node) {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(['/bin/bash', '-n'], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not launch bash for package validation.');
    }
    fwrite($pipes[0], $node->textContent);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fwrite(STDERR, "Embedded Bash script " . ($index + 1) . " is invalid.\n{$stdout}{$stderr}");
        exit(1);
    }
}

echo "Plugin XML, installer scripts, and packaged standalone VM.sh are valid.\n";
