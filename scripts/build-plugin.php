#!/usr/bin/php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pluginPath = $root . '/unraid-vm-assistant-php.plg';
$version = $argv[1] ?? null;
if ($version === null && is_file($pluginPath)) {
    $currentPlugin = (string)file_get_contents($pluginPath);
    if (preg_match('/<PLUGIN\b[^>]*\bversion="([^"]+)"/s', $currentPlugin, $matches)) {
        $version = $matches[1];
    }
}
if (!is_string($version) || !preg_match('/^[0-9]{4}\.[0-9]{2}\.[0-9]{2}\.[0-9]+$/', $version)) {
    fwrite(STDERR, "Pass a version that looks like YYYY.MM.DD.N, or keep a valid generated plugin manifest.\n");
    exit(2);
}

$stageRoot = '/tmp/unraid-vm-assistant-php/' . $version;
$rawRoot = 'https://raw.githubusercontent.com/dictcp/unraid-vm-assistant/' . $version;
$files = [
    'src/scripts/vm.sh' => ['/usr/local/emhttp/plugins/unraid-vm-assistant-php/scripts/vm.sh', '0755'],
    'src/VMCreationAssistant.page' => ['/usr/local/emhttp/plugins/unraid-vm-assistant-php/VMCreationAssistant.page', '0644'],
    'src/VMManagerIntegration.page' => ['/usr/local/emhttp/plugins/unraid-vm-assistant-php/VMManagerIntegration.page', '0644'],
    'src/lib/VMProvisioner.php' => ['/usr/local/emhttp/plugins/unraid-vm-assistant-php/lib/VMProvisioner.php', '0644'],
    'src/scripts/create-vm.php' => ['/usr/local/emhttp/plugins/unraid-vm-assistant-php/scripts/create-vm.php', '0755'],
    'README.md' => ['/usr/local/emhttp/plugins/unraid-vm-assistant-php/README.md', '0644'],
];

$downloads = '';
$installCommands = '';
$destinationDirectories = [];
foreach ($files as $source => [$destination, $mode]) {
    if (!is_file($root . '/' . $source)) {
        throw new RuntimeException("Source file does not exist: {$source}");
    }
    $staged = $stageRoot . '/' . $source;
    $url = $rawRoot . '/' . $source;
    $downloads .= <<<XML
<FILE Name="{$staged}">
<URL>{$url}</URL>
</FILE>

XML;
    $destinationDirectories[dirname($destination)] = true;
    $installCommands .= sprintf(
        "install -m %s %s %s\n",
        $mode,
        escapeshellarg($staged),
        escapeshellarg($destination),
    );
}

$directoryCommands = '';
foreach (array_keys($destinationDirectories) as $directory) {
    $directoryCommands .= 'mkdir -p ' . escapeshellarg($directory) . "\n";
}

$plugin = <<<PLG
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN>

<PLUGIN
  name="unraid-vm-assistant-php"
  author="dictcp"
  version="{$version}"
  launch="Settings/VMCreationAssistant"
  pluginURL="https://raw.githubusercontent.com/dictcp/unraid-vm-assistant/main/unraid-vm-assistant-php.plg"
  support="https://github.com/dictcp/unraid-vm-assistant"
  min="7.3.0"
>

<CHANGES>
<![CDATA[
### {$version}
- Installs normal repository files directly from the matching immutable Git tag.
- Keeps vm.sh as a standalone source file and delegates provisioning to its installed copy.
- Supports Ubuntu 26.04/24.04, Debian 13, Fedora 43, and custom qcow2 images.
- Creates cloud-init users, installs SSH keys and qemu-guest-agent, then registers the VM in Unraid.
- Runs on Unraid's PHP and native virtualization/filesystem commands without Docker or virt-install.
- Adds a Create Cloud VM button beside Add VM in Unraid VM Manager.
]]>
</CHANGES>

{$downloads}<FILE Run="/bin/bash">
<INLINE>
<![CDATA[
#!/bin/bash
set -euo pipefail

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
PLUGIN_NAME="unraid-vm-assistant-php"
PLUGIN_DIR="/boot/config/plugins/\${PLUGIN_NAME}"
STAGE_DIR="{$stageRoot}"

if [ ! -d /boot/config/plugins ]; then
  echo "ERROR: This plugin must be installed on Unraid." >&2
  exit 1
fi

for command in php virsh qemu-img jq curl truncate mkfs.vfat mcopy blkid mount umount setsid install; do
  if ! command -v "\${command}" >/dev/null 2>&1; then
    echo "ERROR: Required Unraid command is missing: \${command}" >&2
    exit 1
  fi
done

mkdir -p "\${PLUGIN_DIR}"
chmod 700 "\${PLUGIN_DIR}"

{$directoryCommands}{$installCommands}
rm -rf "\${STAGE_DIR}"
echo "VM Creation Assistant installed under Settings -> User Utilities."
]]>
</INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE>
<![CDATA[
#!/bin/bash
set -euo pipefail

rm -rf /usr/local/emhttp/plugins/unraid-vm-assistant-php
rm -rf /tmp/unraid-vm-assistant-php
rm -rf /tmp/unraid-vm-assistant
rm -rf /boot/config/plugins/unraid-vm-assistant-php
echo "VM Creation Assistant removed. Existing VMs and /mnt image caches were preserved."
]]>
</INLINE>
</FILE>

</PLUGIN>
PLG;

$output = $pluginPath;
if (file_put_contents($output, $plugin) === false) {
    throw new RuntimeException("Could not write {$output}");
}
echo "Built {$output}\n";
