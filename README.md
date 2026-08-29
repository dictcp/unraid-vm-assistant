# VM Creation Assistant for Unraid — PHP edition

This is a separate, PHP-only native Unraid plugin built around the bundled [`VM.sh`](VM.sh). It does not use the earlier Go provisioning backend and does not require Docker or `virt-install`.

`VM.sh` remains a normal standalone shell file in the repository. The package installs it at `/usr/local/emhttp/plugins/unraid-vm-assistant-php/scripts/VM.sh`, and the detached PHP worker invokes that installed file with validated environment variables.

## What it creates

From **Settings → User Utilities → VM Creation Assistant**, select:

- Ubuntu Server 26.04 LTS
- Ubuntu Server 24.04 LTS
- Debian 13 GenericCloud
- Fedora Cloud 43
- or a custom remote/local qcow2 image

Then provide a VM name, cloud-init username, one or more SSH public keys, vCPU, RAM, disk size, domains directory, and network bridge.

The detached PHP worker performs the same workflow as `vm.sh`:

```text
resolve/download cached qcow2 image
→ validate image format
→ make an independent full VM disk copy
→ resize the disk
→ create user-data + meta-data
→ build a VFAT CIDATA disk with mkfs.vfat + mcopy
→ copy per-VM OVMF VARS
→ generate Unraid/libvirt XML
→ virsh define
→ start through VM.sh
→ optionally enable libvirt autostart
```

The guest is configured for SSH-key-only access, passwordless sudo, and installs/enables `qemu-guest-agent` on first boot.

## Runtime dependencies

The plugin contains no compiled payload. It verifies the Unraid tools required by PHP and the bundled `VM.sh` during installation:

```text
/usr/bin/php
virsh
qemu-img
jq
curl
truncate
mkfs.vfat
mcopy
blkid
mount
umount
setsid
base64
```

It also expects Unraid's QEMU 10.2 and OVMF paths used by `vm.sh`, so the plugin currently requires Unraid 7.3.0 or newer.

## Build and validate

Use the installed `mise` runtime locally:

```bash
mise run validate
```

The generated installable plugin is:

```text
unraid-vm-assistant-php.plg
```

## Storage and safety

- VM directories and custom local images are restricted to `/mnt/...`; the Unraid boot device is not accepted.
- PHP invokes the packaged `VM.sh` with a `proc_open()` argument array and validated environment values.
- Long downloads and VM creation run in a detached PHP CLI worker; the WebGUI request does not remain open.
- Image downloads are locked to prevent two jobs corrupting the same cache entry.
- Every VM gets a full disk copy, so existing VMs never depend on the shared cache.
- Pre-definition failures remove only the files created for that new VM. Once a domain is defined, it is left visible for diagnosis.
- Removing the plugin preserves existing VMs and `/mnt/.../.images` caches.
