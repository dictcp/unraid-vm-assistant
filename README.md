# VM Creation Assistant for Unraid

VM Creation Assistant is a native Unraid plugin for provisioning ready-to-SSH Linux cloud VMs from the WebGUI. PHP handles the interface and background job orchestration, while the standalone [`vm.sh`](src/scripts/vm.sh) performs cloud-image, cloud-init, and libvirt provisioning. It does not require Docker or `virt-install`.

`vm.sh` is a normal source file beside the PHP worker in `src/scripts/`. The plugin manifest downloads it from the matching Git tag, installs it at `/usr/local/emhttp/plugins/unraid-vm-assistant-php/scripts/vm.sh`, and invokes that installed file with validated environment variables.

## What it creates

Open the assistant from either entrypoint:

- **VMs → Create Cloud VM**, beside Unraid's standard **Add VM** button
- **Settings → User Utilities → VM Creation Assistant**

Then select:

- Ubuntu Server 26.04 LTS
- Ubuntu Server 24.04 LTS
- Debian 13 GenericCloud
- Fedora Cloud 43
- or a custom remote/local qcow2 image

Then provide a VM name, cloud-init username, one or more SSH public keys (or one HTTP(S) URL), vCPU, RAM, and disk size. The form generates a random `cloud-vm-xxxxxx` name by default and uses `/mnt/user/domains` with the `br0` network bridge automatically.

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
→ start through vm.sh
→ optionally enable libvirt autostart
```

The guest is configured for SSH-key-only access, passwordless sudo, and installs/enables `qemu-guest-agent` on first boot.

The VM Manager button is installed through a no-title `VMs` child page. It does not modify Unraid's built-in VM Manager files, and removing the plugin removes the integration automatically.

## Runtime dependencies

The plugin contains no compiled or embedded payload. It verifies the Unraid tools required by PHP and the installed `vm.sh` during installation:

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
install
```

It also expects Unraid's QEMU 10.2 and OVMF paths used by `vm.sh`, so the plugin currently requires Unraid 7.3.0 or newer.

## Delivery, build, and validation

The installable [`unraid-vm-assistant-php.plg`](unraid-vm-assistant-php.plg) is a small manifest. Its six `<FILE>` entries download normal repository files from the immutable release tag into a versioned `/tmp` staging directory. A lifecycle hook then copies them into Unraid's live WebGUI plugin directory with explicit file modes.

There are no checksums, encoded source payloads, package archives, generated `dist/` copies, or GitHub Release assets. Published tags must not be moved, and the repository must remain public so Unraid can fetch the raw files without GitHub credentials.

Use the installed `mise` runtime locally:

```bash
mise run validate
```

The generated install manifest is:

```text
unraid-vm-assistant-php.plg
```

## Publishing a version

Create and push a version tag matching `YYYY.MM.DD.N`. The `Update plugin manifest` GitHub workflow checks out `main`, confirms that every runtime source exists in the tag, runs the manifest generator with that tag, validates the result, and commits the updated `.plg` back to `main` as `github-actions[bot]`.

The tag provides the immutable runtime sources; the install URL remains the generated `.plg` on `main`. The workflow does not create packages, checksums, or a GitHub Release. It can also be run manually for an existing tag from the Actions page.

## Storage and safety

- VM directories and custom local images are restricted to `/mnt/...`; the Unraid boot device is not accepted.
- PHP invokes the installed `vm.sh` with a `proc_open()` argument array and validated environment values.
- Long downloads and VM creation run in a detached PHP CLI worker; the WebGUI request does not remain open.
- Image downloads are locked to prevent two jobs corrupting the same cache entry.
- Every VM gets a full disk copy, so existing VMs never depend on the shared cache.
- Pre-definition failures remove only the files created for that new VM. Once a domain is defined, it is left visible for diagnosis.
- Removing the plugin preserves existing VMs and `/mnt/.../.images` caches.
