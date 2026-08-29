#!/bin/bash
set -euo pipefail

#
# Simple Unraid/libvirt VM creator for Linux cloud images
# (Ubuntu / Debian / Fedora, or bring your own qcow2 cloud image)
#
# Usage:
#   ./vm.sh vm-name
#
# Optional:
#   RAM_MB=4096 VCPUS=4 DISK_SIZE=40G ./vm.sh vm-name
#   DISTRO=ubuntu-26.04|ubuntu-24.04|debian-13|fedora-43 ./vm.sh vm-name
#
#   ...or your own qcow2 cloud image (URL, or path to a local file):
#   IMAGE_URL=https://example.com/my-cloud-image.qcow2 ./vm.sh vm-name
#
# SSH access — SSH_KEY may be literal key text, a http(s) URL, or a file:
#   SSH_KEY="ssh-ed25519 AAAA... user@host" ./vm.sh vm-name
#   SSH_KEY="$(curl -sL https://github.com/dictcp.keys)" ./vm.sh vm-name
#   SSH_KEY="$(cat ~/.ssh/id_rsa.pub)" ./vm.sh vm-name
#   SSH_KEY=@/root/.ssh/id_rsa.pub ./vm.sh vm-name
#   Default when unset: ~/.ssh/id_ed25519.pub
#
# Guest username (default: ubuntu):
#   USER=alice ./vm.sh vm-name          (VM_USER=alice also works)
#

random_name() {
    printf 'vm-%s\n' "$(od -An -N3 -tx1 /dev/urandom | tr -d ' \n')"
}

NAME="${1:-$(random_name)}"

# NAME="${1:-}"
# 
# if [[ -z "${NAME}" ]]; then
#     echo "Usage: $0 <vm-name>"
#     exit 1
# fi

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

RAM_MB="${RAM_MB:-2048}"
VCPUS="${VCPUS:-2}"
DISK_SIZE="${DISK_SIZE:-20G}"

BRIDGE="${BRIDGE:-br0}"

DOMAINS_DIR="${DOMAINS_DIR:-/mnt/user/domains}"
VM_DIR="${DOMAINS_DIR}/${NAME}"

DISTRO="${DISTRO:-ubuntu-26.04}"

# Keep downloaded base images shared by all VM creations.
# One cache entry per distro/image (see "Distro / image selection" below).
CACHE_DIR="${CACHE_DIR:-${DOMAINS_DIR}/.images}"

DISK_IMG="${VM_DIR}/vdisk1.qcow2"
SEED_IMG="${VM_DIR}/cidata.img"

USER_DATA="${VM_DIR}/user-data"
META_DATA="${VM_DIR}/meta-data"
XML_FILE="${VM_DIR}/${NAME}.xml"

OVMF_CODE="${OVMF_CODE:-/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi.fd}"
OVMF_VARS_TEMPLATE="${OVMF_VARS_TEMPLATE:-/usr/share/qemu/ovmf-x64/OVMF_VARS-pure-efi.fd}"

QEMU_BIN="${QEMU_BIN:-/usr/local/sbin/qemu}"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

die() {
    echo "ERROR: $*" >&2
    exit 1
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "Missing command: $1"
}

generate_uuid() {
    if command -v uuidgen >/dev/null 2>&1; then
        uuidgen
    else
        cat /proc/sys/kernel/random/uuid
    fi
}

generate_mac() {
    # Libvirt/QEMU conventional locally administered range
    printf '52:54:00:%02x:%02x:%02x' \
        "$((16#$(od -An -N1 -tx1 /dev/urandom | tr -d ' ')))" \
        "$((16#$(od -An -N1 -tx1 /dev/urandom | tr -d ' ')))" \
        "$((16#$(od -An -N1 -tx1 /dev/urandom | tr -d ' ')))"
}

image_format() {
    qemu-img info --output=json "$1" 2>/dev/null |
        jq -r '."format-specific".type'
}

valid_pubkey() {
    printf '%s' "$1" | grep -Eqx \
'(ssh-(rsa|dss|ed25519)|ecdsa-sha2-[a-z0-9-]+|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com)[[:space:]]+[A-Za-z0-9+/=]+([[:space:]][A-Za-z0-9 ._@=/-]*)?'
}

# ---------------------------------------------------------------------------
# Preconditions
# ---------------------------------------------------------------------------

require_cmd virsh
require_cmd qemu-img
require_cmd jq
require_cmd curl
require_cmd truncate
require_cmd mkfs.vfat
require_cmd mount
require_cmd umount
require_cmd blkid

[[ -f "${OVMF_CODE}" ]] ||
    die "OVMF code file not found: ${OVMF_CODE}"

[[ -f "${OVMF_VARS_TEMPLATE}" ]] ||
    die "OVMF VARS template not found: ${OVMF_VARS_TEMPLATE}"

# ---------------------------------------------------------------------------
# SSH key resolution
# ---------------------------------------------------------------------------
# Priority: SSH_KEY -> ~/.ssh/id_ed25519.pub
# SSH_KEY accepts:
#   - literal key text ("ssh-ed25519 AAAA... comment")
#   - a local file path, or "@/path/to/key.pub"
#   - an http(s) URL (e.g. https://github.com/<user>.keys)

SSH_KEY_SOURCE="default (~/.ssh/id_ed25519.pub)"
SSH_KEYS=""

if [[ -n "${SSH_KEY:-}" ]]; then
    case "${SSH_KEY}" in
        https://*|http://*)
            echo "Fetching SSH keys from URL: ${SSH_KEY}"
            SSH_KEY_SOURCE="URL: ${SSH_KEY}"
            SSH_KEYS="$(curl --fail --silent --show-error --location "${SSH_KEY}")"
            ;;
        @*)
            f="${SSH_KEY#@}"
            [[ -f "${f}" ]] || die "SSH key file not found: ${f}"
            SSH_KEY_SOURCE="file: ${f}"
            SSH_KEYS="$(cat "${f}")"
            ;;
        *)
            if [[ -f "${SSH_KEY}" ]]; then
                SSH_KEY_SOURCE="file: ${SSH_KEY}"
                SSH_KEYS="$(cat "${SSH_KEY}")"
            else
                SSH_KEY_SOURCE="inline SSH_KEY"
                SSH_KEYS="${SSH_KEY}"
            fi
            ;;
    esac
else
    DEFAULT_KEY="${HOME}/.ssh/id_ed25519.pub"
    [[ -f "${DEFAULT_KEY}" ]] ||
        die "No SSH key found. Set SSH_KEY, or create ${DEFAULT_KEY}"
    SSH_KEYS="$(cat "${DEFAULT_KEY}")"
fi

SSH_KEYS="${SSH_KEYS//$'\r'/}"
SSH_KEYS="$(printf '%s\n' "${SSH_KEYS}" | sed '/^[[:space:]]*$/d' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"

[[ -n "${SSH_KEYS}" ]] ||
    die "SSH key resolved from ${SSH_KEY_SOURCE} is empty"

FIRST_KEY="$(printf '%s\n' "${SSH_KEYS}" | head -n1)"

if ! valid_pubkey "${FIRST_KEY}"; then
    die "Resolved SSH key does not look like a valid OpenSSH public key
  (from ${SSH_KEY_SOURCE})
  got: ${FIRST_KEY:0:60}..."
fi

echo "SSH keys: ${SSH_KEY_SOURCE}"
printf '%s\n' "${SSH_KEYS}" | while IFS= read -r k; do
    echo "  ${k:0:50}..."
done

# Guest username
USER_NAME="${VM_USER:-${USER_NAME:-${USER:-ubuntu}}}"

if [[ ! "${USER_NAME}" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]]; then
    die "Invalid username '${USER_NAME}' (must match ^[a-z_][a-z0-9_-]{0,31}\$)"
fi

# ---------------------------------------------------------------------------
# Distro / image selection
# ---------------------------------------------------------------------------
# DISTRO picks a built-in image; IMAGE_URL overrides with your own qcow2
# cloud image (http(s) URL) or points at a local file.

UBUNTU_2604_URL="https://cloud-images.ubuntu.com/resolute/current/resolute-server-cloudimg-amd64.img"
UBUNTU_2404_URL="https://cloud-images.ubuntu.com/noble/current/noble-server-cloudimg-amd64.img"
DEBIAN_13_URL="https://cloud.debian.org/images/cloud/trixie/latest/debian-13-genericcloud-amd64.qcow2"
FEDORA_43_URL="https://download.fedoraproject.org/pub/fedora/linux/releases/43/Cloud/x86_64/images/Fedora-Cloud-Base-Generic-43-1.6.x86_64.qcow2"

IMAGE_LABEL=""

if [[ -n "${IMAGE_URL:-}" ]]; then
    echo "Using custom image from IMAGE_URL: ${IMAGE_URL}"
elif [[ -n "${DISTRO}" ]]; then
    case "${DISTRO}" in
        ubuntu-26.04)
            IMAGE_URL="${UBUNTU_2604_URL}"
            IMAGE_LABEL="ubuntu-26.04"
            ;;
        ubuntu-24.04)
            IMAGE_URL="${UBUNTU_2404_URL}"
            IMAGE_LABEL="ubuntu-24.04"
            ;;
        debian-13)
            IMAGE_URL="${DEBIAN_13_URL}"
            IMAGE_LABEL="debian-13"
            ;;
        fedora-43)
            IMAGE_URL="${FEDORA_43_URL}"
            IMAGE_LABEL="fedora-43"
            ;;
        *)
            die "Unknown DISTRO '${DISTRO}'. Supported: ubuntu-26.04, ubuntu-24.04, debian-13, fedora-43 (or set IMAGE_URL to your own qcow2 cloud image)"
            ;;
    esac
fi

[[ -n "${IMAGE_URL}" ]] ||
    die "No image selected. Set DISTRO or IMAGE_URL"

if [[ -f "${IMAGE_URL}" ]]; then
    IMAGE_MODE="local"
    IMAGE_LABEL="${IMAGE_LABEL:-$(basename "${IMAGE_URL}")}"
    echo "Using local image: ${IMAGE_URL}"
elif [[ "${IMAGE_URL}" =~ ^https?:// ]]; then
    IMAGE_MODE="url"
    IMAGE_LABEL="${IMAGE_LABEL:-$(basename "${IMAGE_URL%%\?*}")}"
else
    die "IMAGE_URL must be an http(s) URL or an existing local file: ${IMAGE_URL}"
fi

BASE_IMAGE="${CACHE_DIR}/${IMAGE_LABEL}"

if virsh dominfo "${NAME}" >/dev/null 2>&1; then
    die "VM '${NAME}' already exists in libvirt"
fi

if [[ -e "${VM_DIR}" ]]; then
    die "VM directory already exists: ${VM_DIR}"
fi

# ---------------------------------------------------------------------------
# Generate identity
# ---------------------------------------------------------------------------

UUID="$(generate_uuid)"
MAC="$(generate_mac)"

NVRAM_DIR="/etc/libvirt/qemu/nvram"
NVRAM="${NVRAM_DIR}/${UUID}_VARS-pure-efi.fd"

echo
echo "Creating VM"
echo "------------------------------------------------"
echo "Name:       ${NAME}"
echo "UUID:       ${UUID}"
echo "MAC:        ${MAC}"
echo "RAM:        ${RAM_MB} MiB"
echo "vCPU:       ${VCPUS}"
echo "Disk:       ${DISK_SIZE}"
echo "Bridge:     ${BRIDGE}"
echo "Directory:  ${VM_DIR}"
echo "User:       ${USER_NAME}"
echo "Distro:     ${IMAGE_LABEL}"
echo

# ---------------------------------------------------------------------------
# Directories
# ---------------------------------------------------------------------------

mkdir -p "${CACHE_DIR}"
mkdir -p "${VM_DIR}"
mkdir -p "${NVRAM_DIR}"

# ---------------------------------------------------------------------------
# Download / copy base image
# ---------------------------------------------------------------------------

if [[ "${IMAGE_MODE}" == "local" ]]; then
    echo "Using local image:"
    echo "  ${IMAGE_URL}"
    BASE_IMAGE="${IMAGE_URL}"
elif [[ -f "${BASE_IMAGE}" ]]; then
    echo "Using cached image:"
    echo "  ${BASE_IMAGE}"
else
    echo "Downloading ${IMAGE_LABEL} cloud image..."
    echo "  ${IMAGE_URL}"

    mkdir -p "${CACHE_DIR}"

    TMP_IMAGE="${BASE_IMAGE}.tmp"

    rm -f "${TMP_IMAGE}"

    curl \
        --fail \
        --location \
        --progress-bar \
        --output "${TMP_IMAGE}" \
        "${IMAGE_URL}"

    # Fedora's redirector can serve transient 403/404 pages from a bad
    # mirror; a corrupt download here would poison the shared cache.
    FORMAT="$(image_format "${TMP_IMAGE}")"
    if [[ "${FORMAT}" != "qcow2" ]]; then
        rm -f "${TMP_IMAGE}"
        die "Downloaded image is not qcow2 (got: ${FORMAT:-unreadable}).
Retry later, or download the image manually and pass its path via IMAGE_URL."
    fi

    mv "${TMP_IMAGE}" "${BASE_IMAGE}"
fi

if [[ "${IMAGE_MODE}" == "local" ]]; then
    if [[ "$(image_format "${BASE_IMAGE}")" != "qcow2" ]]; then
        die "Local image is not a valid qcow2: ${BASE_IMAGE}"
    fi
fi

echo

qemu-img info "${BASE_IMAGE}"

# ---------------------------------------------------------------------------
# Create VM disk
# ---------------------------------------------------------------------------

echo
echo "Creating VM disk..."

cp "${BASE_IMAGE}" "${DISK_IMG}"

# Ubuntu cloud image is qcow2 despite the .img suffix.
qemu-img resize "${DISK_IMG}" "${DISK_SIZE}"

echo
qemu-img info "${DISK_IMG}"

# ---------------------------------------------------------------------------
# cloud-init NoCloud data
# ---------------------------------------------------------------------------

echo
echo "Generating cloud-init data..."

cat > "${META_DATA}" <<EOF
instance-id: ${UUID}
local-hostname: ${NAME}
EOF

cat > "${USER_DATA}" <<EOF
#cloud-config

hostname: ${NAME}
manage_etc_hosts: true

users:
  - name: ${USER_NAME}
    gecos: ${USER_NAME}
    groups:
      - sudo
    shell: /bin/bash
    sudo: ALL=(ALL) NOPASSWD:ALL
    lock_passwd: true
    ssh_authorized_keys:
$(printf '%s\n' "${SSH_KEYS}" | sed 's/^/      - /')

ssh_pwauth: false
disable_root: true

package_update: true

packages:
  - qemu-guest-agent

runcmd:
  - systemctl enable --now qemu-guest-agent
EOF

# ---------------------------------------------------------------------------
# Create VFAT NoCloud seed disk
# ---------------------------------------------------------------------------

echo "Creating NoCloud CIDATA filesystem..."

truncate -s 4M "${SEED_IMG}"

mkfs.vfat \
    -n CIDATA \
    "${SEED_IMG}" >/dev/null

# SEED_MNT="$(mktemp -d /tmp/cidata.XXXXXX)"
# 
# cleanup() {
#     if mountpoint -q "${SEED_MNT}" 2>/dev/null; then
#         umount "${SEED_MNT}" || true
#     fi
# 
#     rmdir "${SEED_MNT}" 2>/dev/null || true
# }
# 
# trap cleanup EXIT
# 
# mount -o loop "${SEED_IMG}" "${SEED_MNT}"
# 
# cp "${USER_DATA}" "${SEED_MNT}/user-data"
# cp "${META_DATA}" "${SEED_MNT}/meta-data"
# 
# sync
# 
# umount "${SEED_MNT}"
# rmdir "${SEED_MNT}"
# 
# trap - EXIT

mcopy -i "${SEED_IMG}" \
    "${USER_DATA}" \
    "${META_DATA}" \
    ::

echo
echo "CIDATA:"
blkid "${SEED_IMG}"

# ---------------------------------------------------------------------------
# UEFI NVRAM
# ---------------------------------------------------------------------------

echo
echo "Creating UEFI NVRAM..."

cp "${OVMF_VARS_TEMPLATE}" "${NVRAM}"

# ---------------------------------------------------------------------------
# Generate libvirt XML
# ---------------------------------------------------------------------------

echo
echo "Generating libvirt XML..."

cat > "${XML_FILE}" <<EOF
<?xml version='1.0' encoding='UTF-8'?>
<domain xmlns:qemu="http://libvirt.org/schemas/domain/qemu/1.0" type="kvm">

  <uuid>${UUID}</uuid>
  <name>${NAME}</name>

  <metadata>
    <vmtemplate xmlns="http://unraid"
                name="Ubuntu"
                icon="ubuntu.png"
                os="ubuntu"
                storage="default"/>
  </metadata>

  <memory unit="MiB">${RAM_MB}</memory>
  <currentMemory unit="MiB">${RAM_MB}</currentMemory>

  <vcpu placement="static">${VCPUS}</vcpu>

  <cpu mode="host-passthrough" migratable="on">
    <cache mode="passthrough"/>
  </cpu>

  <memoryBacking>
    <nosharepages/>
  </memoryBacking>

  <os>
    <loader readonly="yes"
            type="pflash">${OVMF_CODE}</loader>
    <nvram format="raw">${NVRAM}</nvram>
    <type arch="x86_64"
          machine="pc-q35-10.2">hvm</type>
  </os>

  <features>
    <acpi/>
    <apic/>
  </features>

  <clock offset="utc"/>

  <on_poweroff>destroy</on_poweroff>
  <on_reboot>restart</on_reboot>
  <on_crash>restart</on_crash>

  <devices>

    <emulator>${QEMU_BIN}</emulator>

    <controller type="pci" index="0" model="pcie-root"/>

    <!-- Ubuntu cloud-image system disk -->
    <disk type="file" device="disk">
      <driver name="qemu"
              type="qcow2"
              cache="writeback"
              discard="unmap"/>
      <source file="${DISK_IMG}"/>
      <target dev="vda" bus="virtio"/>
    </disk>

    <!-- cloud-init NoCloud datasource -->
    <disk type="file" device="disk">
      <driver name="qemu"
              type="raw"/>
      <source file="${SEED_IMG}"/>
      <target dev="vdb" bus="virtio"/>
      <readonly/>
    </disk>

    <interface type="bridge">
      <mac address="${MAC}"/>
      <source bridge="${BRIDGE}"/>
      <model type="virtio-net"/>
    </interface>

    <input type="tablet" bus="usb"/>
    <input type="mouse" bus="ps2"/>
    <input type="keyboard" bus="ps2"/>

    <graphics type="vnc"
              sharePolicy="ignore"
              port="-1"
              autoport="yes"
              websocket="-1"
              listen="0.0.0.0">
      <listen type="address"
              address="0.0.0.0"/>
    </graphics>

    <video>
      <model type="qxl"
             ram="65536"
             vram="16384"
             vgamem="16384"
             heads="1"
             primary="yes"/>
    </video>

    <audio id="1" type="none"/>

    <serial type="pty">
      <target type="isa-serial" port="0">
        <model name="isa-serial"/>
      </target>
    </serial>

    <console type="pty">
      <target type="serial" port="0"/>
    </console>

    <channel type="unix">
      <target type="virtio"
              name="org.qemu.guest_agent.0"/>
    </channel>

    <memballoon model="virtio"/>

  </devices>

</domain>
EOF

# ---------------------------------------------------------------------------
# Validate XML
# ---------------------------------------------------------------------------

if command -v virt-xml-validate >/dev/null 2>&1; then
    echo "Validating XML..."
    virt-xml-validate "${XML_FILE}" domain
fi

# ---------------------------------------------------------------------------
# Define + start
# ---------------------------------------------------------------------------

echo
echo "Defining VM..."

virsh define "${XML_FILE}"

echo
echo "Starting VM..."

if ! virsh start "${NAME}"; then
    echo
    echo "VM failed to start."
    echo
    echo "Domain was defined, so inspect with:"
    echo "  virsh dumpxml '${NAME}'"
    echo
    echo "To remove:"
    echo "  virsh undefine '${NAME}'"
    exit 1
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

echo
echo "================================================"
echo "VM created successfully"
echo "================================================"
echo
echo "Name:      ${NAME}"
echo "UUID:      ${UUID}"
echo "MAC:       ${MAC}"
echo "User:      ${USER_NAME}"
echo "Distro:    ${IMAGE_LABEL}"
echo "Disk:      ${DISK_IMG}"
echo "CIDATA:    ${SEED_IMG}"
echo "XML:       ${XML_FILE}"
echo
echo "libvirt:"
virsh dominfo "${NAME}"

echo
echo "Network:"
virsh domiflist "${NAME}"

echo
echo "VNC:"
virsh vncdisplay "${NAME}" || true

echo
echo "Useful commands:"
echo
echo "  virsh console '${NAME}'"
echo "  virsh domifaddr '${NAME}'"
echo "  virsh domifaddr '${NAME}' --source agent"
echo "  virsh shutdown '${NAME}'"
echo "  virsh destroy '${NAME}'"
echo
echo "When cloud-init/qemu-guest-agent is ready:"
echo
echo "  virsh domifaddr '${NAME}' --source agent"
echo "  ssh ${USER_NAME}@<IP>"
echo

