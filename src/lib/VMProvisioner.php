<?php

declare(strict_types=1);

final class VMProvisionException extends RuntimeException
{
}

final class VMCommandRunner
{
    private string $logPath;

    public function __construct(string $logPath)
    {
        $this->logPath = $logPath;
    }

    /** @param list<string> $command */
    public function run(array $command, ?array $environment = null): void
    {
        $this->append('$ ' . implode(' ', array_map('escapeshellarg', $command)) . PHP_EOL);
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->logPath, 'a'],
            2 => ['file', $this->logPath, 'a'],
        ];
        $process = proc_open($command, $descriptors, $pipes, null, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new VMProvisionException('Could not start command: ' . $command[0]);
        }
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new VMProvisionException(sprintf('%s failed with exit code %d', basename($command[0]), $exitCode));
        }
    }

    /** @param list<string> $command */
    public function capture(array $command, bool $allowFailure = false): string
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new VMProvisionException('Could not start command: ' . $command[0]);
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 && !$allowFailure) {
            throw new VMProvisionException(trim((string)$stderr) ?: sprintf('%s failed with exit code %d', basename($command[0]), $exitCode));
        }
        return (string)$stdout;
    }

    public function append(string $message): void
    {
        file_put_contents($this->logPath, $message, FILE_APPEND | LOCK_EX);
    }
}

final class VMProvisioner
{
    public const PLUGIN_NAME = 'unraid-vm-assistant-php';
    public const JOB_ROOT = '/tmp/unraid-vm-assistant/jobs';
    public const VM_SCRIPT_PATH = '/usr/local/emhttp/plugins/unraid-vm-assistant-php/scripts/vm.sh';

    /** @return array<string,array<string,string>> */
    public static function profiles(): array
    {
        return [
            'ubuntu-26.04' => [
                'label' => 'Ubuntu Server 26.04 LTS',
                'url' => 'https://cloud-images.ubuntu.com/releases/resolute/release/ubuntu-26.04-server-cloudimg-amd64.img',
                'username' => 'ubuntu',
                'icon' => 'ubuntu.png',
                'os' => 'ubuntu',
            ],
            'ubuntu-24.04' => [
                'label' => 'Ubuntu Server 24.04 LTS',
                'url' => 'https://cloud-images.ubuntu.com/releases/24.04/release/ubuntu-24.04-server-cloudimg-amd64.img',
                'username' => 'ubuntu',
                'icon' => 'ubuntu.png',
                'os' => 'ubuntu',
            ],
            'debian-13' => [
                'label' => 'Debian 13 GenericCloud',
                'url' => 'https://cloud.debian.org/images/cloud/trixie/latest/debian-13-genericcloud-amd64.qcow2',
                'username' => 'debian',
                'icon' => 'debian.png',
                'os' => 'debian',
            ],
            'fedora-43' => [
                'label' => 'Fedora Cloud 43',
                'url' => 'https://download.fedoraproject.org/pub/fedora/linux/releases/43/Cloud/x86_64/images/Fedora-Cloud-Base-Generic-43-1.6.x86_64.qcow2',
                'username' => 'fedora',
                'icon' => 'fedora.png',
                'os' => 'fedora',
            ],
            'custom' => [
                'label' => 'Custom qcow2 image',
                'url' => '',
                'username' => 'ubuntu',
                'icon' => 'linux.png',
                'os' => 'linux',
            ],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function normalizeSpec(array $input): array
    {
        $profile = trim((string)($input['profile'] ?? 'ubuntu-26.04'));
        $profiles = self::profiles();
        $defaultUser = $profiles[$profile]['username'] ?? 'ubuntu';
        return [
            'profile' => $profile,
            'image_source' => trim((string)($input['image_source'] ?? '')),
            'name' => trim((string)($input['name'] ?? '')),
            'username' => trim((string)($input['username'] ?? $defaultUser)),
            'ssh_keys' => self::normalizeKeys((string)($input['ssh_keys'] ?? '')),
            'ram_mb' => (int)($input['ram_mb'] ?? 2048),
            'vcpus' => (int)($input['vcpus'] ?? 2),
            'disk_gib' => (int)($input['disk_gib'] ?? 20),
            'bridge' => trim((string)($input['bridge'] ?? 'br0')),
            'domains_dir' => rtrim(trim((string)($input['domains_dir'] ?? '/mnt/user/domains')), '/'),
            'start' => self::toBool($input['start'] ?? true),
            'autostart' => self::toBool($input['autostart'] ?? false),
        ];
    }

    /** @param array<string,mixed> $spec @return list<string> */
    public static function validateSpec(array $spec): array
    {
        $errors = [];
        $profiles = self::profiles();
        if (!isset($profiles[$spec['profile'] ?? ''])) {
            $errors[] = 'Unknown image profile.';
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$/', (string)($spec['name'] ?? ''))) {
            $errors[] = 'VM name must be 1-63 letters, numbers, dots, underscores, or dashes.';
        }
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', (string)($spec['username'] ?? ''))) {
            $errors[] = 'Username must be a valid lowercase Linux username.';
        }
        if (($spec['vcpus'] ?? 0) < 1 || ($spec['vcpus'] ?? 0) > 64) {
            $errors[] = 'vCPU count must be between 1 and 64.';
        }
        if (($spec['ram_mb'] ?? 0) < 512 || ($spec['ram_mb'] ?? 0) > 262144) {
            $errors[] = 'RAM must be between 512 and 262144 MiB.';
        }
        if (($spec['disk_gib'] ?? 0) < 8 || ($spec['disk_gib'] ?? 0) > 4096) {
            $errors[] = 'Disk size must be between 8 and 4096 GiB.';
        }
        $domainsDir = (string)($spec['domains_dir'] ?? '');
        if (!self::isSafeMountPath($domainsDir)) {
            $errors[] = 'Domains directory must be an absolute path below /mnt without parent traversal.';
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,31}$/', (string)($spec['bridge'] ?? ''))) {
            $errors[] = 'Network bridge contains invalid characters.';
        }
        $keys = preg_split('/\n/', (string)($spec['ssh_keys'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($keys === []) {
            $errors[] = 'At least one OpenSSH public key is required.';
        }
        foreach ($keys as $key) {
            if (!self::validPublicKey($key)) {
                $errors[] = 'One or more SSH public keys are invalid.';
                break;
            }
        }
        if (($spec['profile'] ?? '') === 'custom') {
            $source = (string)($spec['image_source'] ?? '');
            if (!self::isRemoteImage($source) && !self::isSafeMountPath($source)) {
                $errors[] = 'Custom image must be an HTTP(S) URL or an absolute local path below /mnt.';
            }
        }
        return $errors;
    }

    /** @param array<string,mixed> $spec */
    public static function renderUserData(array $spec): string
    {
        $keys = preg_split('/\n/', (string)$spec['ssh_keys'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $keyLines = implode("\n", array_map(static fn(string $key): string => '      - ' . self::yamlQuote($key), $keys));
        $name = self::yamlQuote((string)$spec['name']);
        $username = self::yamlQuote((string)$spec['username']);
        return <<<YAML
#cloud-config

hostname: {$name}
manage_etc_hosts: true

users:
  - name: {$username}
    gecos: {$username}
    groups:
      - sudo
    shell: /bin/bash
    sudo: "ALL=(ALL) NOPASSWD:ALL"
    lock_passwd: true
    ssh_authorized_keys:
{$keyLines}

ssh_pwauth: false
disable_root: true

package_update: true
packages:
  - qemu-guest-agent

runcmd:
  - [systemctl, enable, --now, qemu-guest-agent]

YAML;
    }

    /** @param array<string,mixed> $spec */
    public static function renderMetaData(array $spec, string $uuid): string
    {
        return 'instance-id: ' . self::yamlQuote($uuid) . "\nlocal-hostname: " . self::yamlQuote((string)$spec['name']) . "\n";
    }

    /** @param array<string,mixed> $spec */
    public static function renderDomainXml(array $spec, array $paths, string $uuid, string $mac): string
    {
        $profiles = self::profiles();
        $profile = $profiles[(string)$spec['profile']];
        $x = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $name = $x((string)$spec['name']);
        $bridge = $x((string)$spec['bridge']);
        $icon = $x($profile['icon']);
        $os = $x($profile['os']);
        $disk = $x($paths['disk']);
        $seed = $x($paths['seed']);
        $nvram = $x($paths['nvram']);
        $ram = (int)$spec['ram_mb'];
        $vcpus = (int)$spec['vcpus'];
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<domain xmlns:qemu="http://libvirt.org/schemas/domain/qemu/1.0" type="kvm">
  <uuid>{$uuid}</uuid>
  <name>{$name}</name>
  <metadata>
    <vmtemplate xmlns="http://unraid" name="Linux Cloud" icon="{$icon}" os="{$os}" storage="default"/>
  </metadata>
  <memory unit="MiB">{$ram}</memory>
  <currentMemory unit="MiB">{$ram}</currentMemory>
  <vcpu placement="static">{$vcpus}</vcpu>
  <cpu mode="host-passthrough" migratable="on"><cache mode="passthrough"/></cpu>
  <memoryBacking><nosharepages/></memoryBacking>
  <os>
    <loader readonly="yes" type="pflash">/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi.fd</loader>
    <nvram format="raw">{$nvram}</nvram>
    <type arch="x86_64" machine="pc-q35-10.2">hvm</type>
  </os>
  <features><acpi/><apic/></features>
  <clock offset="utc"/>
  <on_poweroff>destroy</on_poweroff>
  <on_reboot>restart</on_reboot>
  <on_crash>restart</on_crash>
  <devices>
    <emulator>/usr/local/sbin/qemu</emulator>
    <controller type="pci" index="0" model="pcie-root"/>
    <disk type="file" device="disk">
      <driver name="qemu" type="qcow2" cache="writeback" discard="unmap"/>
      <source file="{$disk}"/>
      <target dev="vda" bus="virtio"/>
    </disk>
    <disk type="file" device="disk">
      <driver name="qemu" type="raw"/>
      <source file="{$seed}"/>
      <target dev="vdb" bus="virtio"/>
      <readonly/>
    </disk>
    <interface type="bridge">
      <mac address="{$mac}"/>
      <source bridge="{$bridge}"/>
      <model type="virtio-net"/>
    </interface>
    <input type="tablet" bus="usb"/>
    <input type="mouse" bus="ps2"/>
    <input type="keyboard" bus="ps2"/>
    <graphics type="vnc" sharePolicy="ignore" port="-1" autoport="yes" websocket="-1" listen="0.0.0.0">
      <listen type="address" address="0.0.0.0"/>
    </graphics>
    <video><model type="qxl" ram="65536" vram="16384" vgamem="16384" heads="1" primary="yes"/></video>
    <audio id="1" type="none"/>
    <serial type="pty"><target type="isa-serial" port="0"><model name="isa-serial"/></target></serial>
    <console type="pty"><target type="serial" port="0"/></console>
    <channel type="unix"><target type="virtio" name="org.qemu.guest_agent.0"/></channel>
    <memballoon model="virtio"/>
  </devices>
</domain>
XML;
    }

    /** @param array<string,mixed> $rawSpec */
    public function provision(array $rawSpec, string $logPath): array
    {
        $spec = self::normalizeSpec($rawSpec);
        $errors = self::validateSpec($spec);
        if ($errors !== []) {
            throw new VMProvisionException(implode(' ', $errors));
        }
        $runner = new VMCommandRunner($logPath);
        $name = (string)$spec['name'];
        if (!is_file(self::VM_SCRIPT_PATH) || !is_executable(self::VM_SCRIPT_PATH)) {
            throw new VMProvisionException('Installed vm.sh is missing or not executable: ' . self::VM_SCRIPT_PATH);
        }

        $environment = [
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => '/root',
            'DISTRO' => (string)$spec['profile'],
            'VM_USER' => (string)$spec['username'],
            'SSH_KEY' => (string)$spec['ssh_keys'],
            'RAM_MB' => (string)$spec['ram_mb'],
            'VCPUS' => (string)$spec['vcpus'],
            'DISK_SIZE' => (string)$spec['disk_gib'] . 'G',
            'BRIDGE' => (string)$spec['bridge'],
            'DOMAINS_DIR' => (string)$spec['domains_dir'],
        ];
        if ($spec['profile'] === 'custom') {
            $environment['IMAGE_URL'] = (string)$spec['image_source'];
        }

        $runner->append("Delegating VM creation to installed vm.sh\n");
        $runner->run(['/bin/bash', self::VM_SCRIPT_PATH, $name], $environment);
        if ($spec['autostart']) {
            $virsh = self::findExecutable('virsh');
            if ($virsh === null) {
                throw new VMProvisionException('Missing required Unraid command: virsh');
            }
            $runner->run([$virsh, 'autostart', $name]);
        }
        return ['name' => $name, 'script' => self::VM_SCRIPT_PATH];
    }

    /** @return array<string,string> */
    private function preflight(): array
    {
        $required = ['virsh', 'qemu-img', 'curl', 'truncate', 'mkfs.vfat', 'mcopy', 'blkid', 'cp'];
        $commands = [];
        foreach ($required as $name) {
            $path = self::findExecutable($name);
            if ($path === null) {
                throw new VMProvisionException("Missing required Unraid command: {$name}");
            }
            $commands[$name] = $path;
        }
        $validator = self::findExecutable('virt-xml-validate');
        if ($validator !== null) {
            $commands['virt-xml-validate'] = $validator;
        }
        foreach (['/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi.fd', '/usr/share/qemu/ovmf-x64/OVMF_VARS-pure-efi.fd', '/usr/local/sbin/qemu'] as $path) {
            if (!is_file($path)) {
                throw new VMProvisionException("Required Unraid virtualization file is missing: {$path}");
            }
        }
        return $commands;
    }

    private function resolveImage(string $source, string $profile, string $cacheDir, array $commands, VMCommandRunner $runner): string
    {
        if (!self::isRemoteImage($source)) {
            if (!is_file($source)) {
                throw new VMProvisionException("Local image does not exist: {$source}");
            }
            $this->assertQcow2($source, $commands['qemu-img'], $runner);
            return $source;
        }
        $label = preg_replace('/[^A-Za-z0-9._-]/', '-', $profile === 'custom' ? basename((string)parse_url($source, PHP_URL_PATH)) : $profile);
        $label = trim((string)$label, '.-') ?: 'custom-image';
        if (str_ends_with(strtolower($label), '.qcow2')) {
            $label = substr($label, 0, -6) ?: 'custom-image';
        }
        $baseImage = $cacheDir . '/' . $label . '.qcow2';
        $lockPath = $baseImage . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new VMProvisionException("Could not lock image cache: {$lockPath}");
        }
        try {
            if (is_file($baseImage)) {
                $this->assertQcow2($baseImage, $commands['qemu-img'], $runner);
                $runner->append("Using cached image: {$baseImage}\n");
                return $baseImage;
            }
            $tmp = $baseImage . '.tmp.' . getmypid();
            @unlink($tmp);
            $runner->append("Downloading: {$source}\n");
            $runner->run([$commands['curl'], '--fail', '--location', '--progress-bar', '--output', $tmp, $source]);
            $this->assertQcow2($tmp, $commands['qemu-img'], $runner);
            if (!rename($tmp, $baseImage)) {
                @unlink($tmp);
                throw new VMProvisionException("Could not install cached image: {$baseImage}");
            }
            return $baseImage;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function assertQcow2(string $path, string $qemuImg, VMCommandRunner $runner): void
    {
        $json = $runner->capture([$qemuImg, 'info', '--output=json', $path]);
        $info = json_decode($json, true);
        if (!is_array($info) || ($info['format'] ?? null) !== 'qcow2') {
            throw new VMProvisionException("Image is not a readable qcow2 file: {$path}");
        }
    }

    private static function normalizeKeys(string $value): string
    {
        $value = str_replace("\r", '', $value);
        $lines = preg_split('/\n/', $value) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
        return implode("\n", $lines);
    }

    private static function validPublicKey(string $key): bool
    {
        return preg_match('/^(ssh-(rsa|dss|ed25519)|ecdsa-sha2-[a-z0-9-]+|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com)\s+[A-Za-z0-9+\/=]+(?:\s+[A-Za-z0-9 ._@=\/\-]+)?$/', $key) === 1;
    }

    private static function isSafeMountPath(string $path): bool
    {
        return str_starts_with($path, '/mnt/') && !str_contains($path, "\0") && !preg_match('#(?:^|/)\.\.(?:/|$)#', $path);
    }

    private static function isRemoteImage(string $source): bool
    {
        $scheme = strtolower((string)parse_url($source, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) && filter_var($source, FILTER_VALIDATE_URL) !== false;
    }

    private static function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private static function yamlQuote(string $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function generateUuid(): string
    {
        $uuid = trim((string)@file_get_contents('/proc/sys/kernel/random/uuid'));
        if (preg_match('/^[a-f0-9-]{36}$/', $uuid)) {
            return $uuid;
        }
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function generateMac(): string
    {
        $bytes = unpack('C3', random_bytes(3));
        return sprintf('52:54:00:%02x:%02x:%02x', $bytes[1], $bytes[2], $bytes[3]);
    }

    private static function findExecutable(string $name): ?string
    {
        foreach (['/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin'] as $directory) {
            $path = $directory . '/' . $name;
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    private static function writeAtomic(string $path, string $contents, int $mode): void
    {
        $tmp = $path . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $contents, LOCK_EX) === false || !chmod($tmp, $mode) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new VMProvisionException("Could not write file: {$path}");
        }
    }
}
