#!/usr/bin/php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$version = $argv[1] ?? '2026.08.29.4';
if (!preg_match('/^[0-9]{4}\.[0-9]{2}\.[0-9]{2}\.[0-9]+$/', $version)) {
    fwrite(STDERR, "Version must look like YYYY.MM.DD.N\n");
    exit(2);
}

$files = [
    '/usr/local/emhttp/plugins/unraid-vm-assistant-php/scripts/vm.sh' => [$root . '/vm.sh', '0755'],
    '/usr/local/emhttp/plugins/unraid-vm-assistant-php/VMCreationAssistant.page' => [$root . '/src/VMCreationAssistant.page', '0644'],
    '/usr/local/emhttp/plugins/unraid-vm-assistant-php/lib/VMProvisioner.php' => [$root . '/src/lib/VMProvisioner.php', '0644'],
    '/usr/local/emhttp/plugins/unraid-vm-assistant-php/scripts/create-vm.php' => [$root . '/src/scripts/create-vm.php', '0755'],
    '/usr/local/emhttp/plugins/unraid-vm-assistant-php/README.md' => [$root . '/README.md', '0644'],
];

$payload = '';
foreach ($files as $destination => [$source, $mode]) {
    $contents = file_get_contents($source);
    if ($contents === false) {
        throw new RuntimeException("Could not read {$source}");
    }
    $encoded = chunk_split(base64_encode($contents), 76, "\n");
    $marker = 'VMA_' . strtoupper(substr(hash('sha256', $destination), 0, 16));
    $destinationArg = escapeshellarg($destination);
    $payload .= "mkdir -p " . escapeshellarg(dirname($destination)) . "\n";
    $payload .= "base64 -d > {$destinationArg} <<'{$marker}'\n{$encoded}{$marker}\n";
    $payload .= "chmod {$mode} {$destinationArg}\n\n";
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
- Initial PHP-only VM Creation Assistant based on vm.sh.
- Packages vm.sh as a standalone executable and delegates provisioning to it.
- Supports Ubuntu 26.04/24.04, Debian 13, Fedora 43, and custom qcow2 images.
- Creates cloud-init users, installs SSH keys and qemu-guest-agent, then registers the VM in Unraid.
- Runs on Unraid's PHP and native virtualization/filesystem commands without a Docker container.
]]>
</CHANGES>

<FILE Run="/bin/bash">
<INLINE>
<![CDATA[
#!/bin/bash
set -e

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
PLUGIN_NAME="unraid-vm-assistant-php"
PLUGIN_DIR="/boot/config/plugins/\${PLUGIN_NAME}"
WEB_DIR="/usr/local/emhttp/plugins/\${PLUGIN_NAME}"

if [ ! -d /boot/config/plugins ]; then
  echo "ERROR: This plugin must be installed on Unraid." >&2
  exit 1
fi

for command in php virsh qemu-img jq curl truncate mkfs.vfat mcopy blkid mount umount setsid base64; do
  if ! command -v "\${command}" >/dev/null 2>&1; then
    echo "ERROR: Required Unraid command is missing: \${command}" >&2
    exit 1
  fi
done

mkdir -p "\${PLUGIN_DIR}" "\${WEB_DIR}"
chmod 700 "\${PLUGIN_DIR}"

{$payload}
echo "VM Creation Assistant installed under Settings -> User Utilities."
]]>
</INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE>
<![CDATA[
#!/bin/bash
set -e

rm -rf /usr/local/emhttp/plugins/unraid-vm-assistant-php
rm -rf /tmp/unraid-vm-assistant
rm -rf /boot/config/plugins/unraid-vm-assistant-php
echo "VM Creation Assistant removed. Existing VMs and /mnt image caches were preserved."
]]>
</INLINE>
</FILE>

</PLUGIN>
PLG;

$output = $root . '/unraid-vm-assistant-php.plg';
$dist = $root . '/dist/unraid-vm-assistant-php-' . $version . '.plg';
if (!is_dir(dirname($dist)) && !mkdir(dirname($dist), 0755, true)) {
    throw new RuntimeException('Could not create dist directory.');
}
foreach ([$output, $dist] as $path) {
    if (file_put_contents($path, $plugin) === false) {
        throw new RuntimeException("Could not write {$path}");
    }
}
echo "Built {$output}\nBuilt {$dist}\n";
