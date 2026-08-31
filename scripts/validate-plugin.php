#!/usr/bin/php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pluginPath = $argv[1] ?? $root . '/unraid-vm-assistant-php.plg';
$document = new DOMDocument();
libxml_use_internal_errors(true);
if (!$document->load($pluginPath, LIBXML_NONET)) {
    foreach (libxml_get_errors() as $error) {
        fwrite(STDERR, trim($error->message) . PHP_EOL);
    }
    fwrite(STDERR, "Invalid plugin XML: {$pluginPath}\n");
    exit(1);
}

$plugin = $document->documentElement;
if (!$plugin instanceof DOMElement) {
    fwrite(STDERR, "Plugin root element is missing.\n");
    exit(1);
}
$version = $plugin->getAttribute('version');
if (!preg_match('/^[0-9]{4}\.[0-9]{2}\.[0-9]{2}\.[0-9]+$/', $version)) {
    fwrite(STDERR, "Plugin version is invalid: {$version}\n");
    exit(1);
}

$stageRoot = '/tmp/unraid-vm-assistant-php/' . $version;
$rawRoot = 'https://raw.githubusercontent.com/dictcp/unraid-vm-assistant/' . $version;
$files = [
    'src/scripts/vm.sh' => ['/usr/local/emhttp/plugins/unraid-vm-assistant-php/scripts/vm.sh', '0755'],
    'src/VMCreationAssistant.page' => ['/usr/local/emhttp/plugins/unraid-vm-assistant-php/VMCreationAssistant.page', '0644'],
    'src/VMManagerIntegration.page' => ['/usr/local/emhttp/plugins/unraid-vm-assistant-php/VMManagerIntegration.page', '0644'],
    'src/lib/VMProvisioner.php' => ['/usr/local/emhttp/plugins/unraid-vm-assistant-php/lib/VMProvisioner.php', '0644'],
    'src/scripts/create-vm.php' => ['/usr/local/emhttp/plugins/unraid-vm-assistant-php/scripts/create-vm.php', '0755'],
    'src/README.md' => ['/usr/local/emhttp/plugins/unraid-vm-assistant-php/README.md', '0644'],
];

$descriptionPath = $root . '/src/README.md';
$description = trim((string)file_get_contents($descriptionPath));
$descriptionWords = preg_split('/\s+/u', $description, -1, PREG_SPLIT_NO_EMPTY);
if (
    $description === ''
    || !is_array($descriptionWords)
    || count($descriptionWords) >= 50
    || preg_match('/[\r\n]/', $description) === 1
    || preg_match('/(?:^|\s)#{1,6}\s|(?:^|\s)[-*+]\s|\[[^]]+]\([^)]+\)|<[^>]+>/', $description) === 1
) {
    fwrite(STDERR, "Plugin Manager description must be one plain-text line under 50 words.\n");
    exit(1);
}

$xpath = new DOMXPath($document);
$downloads = $xpath->query('/PLUGIN/FILE[URL]');
if ($downloads === false || $downloads->length !== count($files)) {
    fwrite(STDERR, "Expected exactly six raw-file downloads.\n");
    exit(1);
}

$downloadsByName = [];
foreach ($downloads as $download) {
    if (!$download instanceof DOMElement) {
        continue;
    }
    $downloadsByName[$download->getAttribute('Name')] = trim((string)$download->getElementsByTagName('URL')->item(0)?->textContent);
}

$installNode = $xpath->query('/PLUGIN/FILE[@Run="/bin/bash" and not(@Method)]/INLINE')?->item(0);
if (!$installNode instanceof DOMNode) {
    fwrite(STDERR, "Install lifecycle hook is missing.\n");
    exit(1);
}
$installScript = $installNode->textContent;

foreach ($files as $source => [$destination, $mode]) {
    $sourcePath = $root . '/' . $source;
    if (!is_file($sourcePath)) {
        fwrite(STDERR, "Repository source file is missing: {$source}\n");
        exit(1);
    }
    $staged = $stageRoot . '/' . $source;
    $expectedUrl = $rawRoot . '/' . $source;
    if (($downloadsByName[$staged] ?? null) !== $expectedUrl) {
        fwrite(STDERR, "Raw-file mapping is missing or incorrect: {$source}\n");
        exit(1);
    }
    $installCommand = sprintf(
        'install -m %s %s %s',
        $mode,
        escapeshellarg($staged),
        escapeshellarg($destination),
    );
    if (!str_contains($installScript, $installCommand)) {
        fwrite(STDERR, "Install destination or mode is incorrect: {$source}\n");
        exit(1);
    }
}

$checksums = $xpath->query('/PLUGIN/FILE/SHA256 | /PLUGIN/FILE/MD5');
if ($checksums === false || $checksums->length !== 0) {
    fwrite(STDERR, "The raw-file manifest must not contain checksums.\n");
    exit(1);
}

$pluginContents = (string)file_get_contents($pluginPath);
foreach (['base64', '.txz', '/dist/'] as $forbidden) {
    if (stripos($pluginContents, $forbidden) !== false) {
        fwrite(STDERR, "Generated plugin contains forbidden package content: {$forbidden}\n");
        exit(1);
    }
}
if (is_dir($root . '/dist')) {
    fwrite(STDERR, "Legacy dist directory must not exist.\n");
    exit(1);
}

$scripts = $xpath->query('/PLUGIN/FILE[@Run="/bin/bash"]/INLINE');
if ($scripts === false || $scripts->length !== 2) {
    fwrite(STDERR, "Expected exactly two lifecycle hooks.\n");
    exit(1);
}

/** @return array{int,string,string} */
function lintBash(string $script): array
{
    $process = proc_open(
        ['/bin/bash', '-n'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not launch bash validation.');
    }
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string)$stdout, (string)$stderr];
}

[$vmExit, $vmOutput, $vmError] = lintBash((string)file_get_contents($root . '/src/scripts/vm.sh'));
if ($vmExit !== 0) {
    fwrite(STDERR, "Installed vm.sh source is invalid.\n{$vmOutput}{$vmError}");
    exit(1);
}

foreach ($scripts as $index => $node) {
    [$exitCode, $stdout, $stderr] = lintBash($node->textContent);
    if ($exitCode !== 0) {
        fwrite(STDERR, "Lifecycle hook " . ($index + 1) . " is invalid.\n{$stdout}{$stderr}");
        exit(1);
    }
}

echo "Plugin XML, short description, raw-file mappings, lifecycle hooks, and installed vm.sh source are valid.\n";
