#!/usr/bin/php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/lib/VMProvisioner.php';

$tests = 0;
function check(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        throw new RuntimeException("Test failed: {$message}");
    }
}

$valid = VMProvisioner::normalizeSpec([
    'profile' => 'ubuntu-26.04',
    'name' => 'ubuntu-dev-01',
    'username' => 'alice',
    'ssh_keys' => "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITest alice@laptop\nssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQDemo second",
    'ram_mb' => 4096,
    'vcpus' => 4,
    'disk_gib' => 40,
    'bridge' => 'br0',
    'domains_dir' => '/mnt/user/domains',
    'start' => true,
]);
check(VMProvisioner::validateSpec($valid) === [], 'valid specification is accepted');

$userData = VMProvisioner::renderUserData($valid);
check(str_contains($userData, 'name: "alice"'), 'cloud-init contains username');
check(substr_count($userData, 'ssh-') === 2, 'cloud-init contains all SSH keys');
check(str_contains($userData, 'qemu-guest-agent'), 'cloud-init installs guest agent');

$paths = [
    'disk' => '/mnt/user/domains/ubuntu-dev-01/vdisk1.qcow2',
    'seed' => '/mnt/user/domains/ubuntu-dev-01/cidata.img',
    'nvram' => '/etc/libvirt/qemu/nvram/test_VARS-pure-efi.fd',
];
$xml = VMProvisioner::renderDomainXml($valid, $paths, '12345678-1234-4234-9234-123456789abc', '52:54:00:12:34:56');
$document = new DOMDocument();
check($document->loadXML($xml), 'generated libvirt XML parses');
check(str_contains($xml, 'pc-q35-10.2'), 'XML follows vm.sh machine type');
check(str_contains($xml, 'org.qemu.guest_agent.0'), 'XML includes guest-agent channel');

$badName = $valid;
$badName['name'] = 'bad; virsh destroy all';
check(VMProvisioner::validateSpec($badName) !== [], 'shell-like VM name is rejected');

$badPath = $valid;
$badPath['domains_dir'] = '/mnt/user/../boot';
check(VMProvisioner::validateSpec($badPath) !== [], 'parent traversal is rejected');

$badImage = $valid;
$badImage['profile'] = 'custom';
$badImage['image_source'] = '/etc/passwd';
check(VMProvisioner::validateSpec($badImage) !== [], 'custom local image outside /mnt is rejected');

$custom = $valid;
$custom['profile'] = 'custom';
$custom['image_source'] = 'https://example.com/cloud.qcow2';
check(VMProvisioner::validateSpec($custom) === [], 'custom HTTPS image is accepted');

check(is_file(dirname(__DIR__) . '/src/scripts/vm.sh'), 'standalone vm.sh source is present with the worker scripts');
check(
    VMProvisioner::VM_SCRIPT_PATH === '/usr/local/emhttp/plugins/unraid-vm-assistant-php/scripts/vm.sh',
    'PHP provisioner refers to the installed standalone vm.sh path'
);

$integrationPage = (string)file_get_contents(dirname(__DIR__) . '/src/VMManagerIntegration.page');
check(str_contains($integrationPage, 'Menu="VMs:99"'), 'VM Manager integration is registered as a VMs child page');
check(str_contains($integrationPage, "id = 'btnAddCloudVM'"), 'VM Manager integration creates an idempotent cloud VM button');
check(str_contains($integrationPage, "'/Settings/VMCreationAssistant'"), 'cloud VM button targets the existing assistant');

echo "{$tests} tests passed.\n";
