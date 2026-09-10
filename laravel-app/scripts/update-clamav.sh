#!/bin/sh

set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
APP_DIR=$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)

VERSION_FILE="${APP_DIR}/docker/security-worker/clamav.version"
RELEASES_API="https://api.github.com/repos/Cisco-Talos/clamav/releases?per_page=100"

cd "${APP_DIR}"

for command_name in curl docker grep sed awk sort tail cut mktemp cp rm; do
    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "Missing required command: ${command_name}" >&2
        exit 1
    fi
done

if [ ! -f "${VERSION_FILE}" ]; then
    echo "Missing version file: ${VERSION_FILE}" >&2
    exit 1
fi

# shellcheck disable=SC1090
. "${VERSION_FILE}"

if [ -z "${CLAMAV_RELEASE_LINE:-}" ] || [ -z "${CLAMAV_VERSION:-}" ]; then
    echo "Invalid ClamAV version configuration." >&2
    exit 1
fi

echo "Current ClamAV version: ${CLAMAV_VERSION}"
echo "Allowed release line:    ${CLAMAV_RELEASE_LINE}.x"
echo "Checking official Cisco Talos / ClamAV releases..."

RELEASES_FILE=$(mktemp)
VERSION_BACKUP=$(mktemp)

cleanup()
{
    rm -f "${RELEASES_FILE}" "${VERSION_BACKUP}"
}

trap cleanup EXIT HUP INT TERM

curl --fail --location --silent --show-error \
    "${RELEASES_API}" \
    --output "${RELEASES_FILE}"

LATEST_VERSION=$(
    grep -Eo "\"tag_name\":[[:space:]]*\"clamav-${CLAMAV_RELEASE_LINE}\.[0-9]+\"" \
        "${RELEASES_FILE}" \
    | sed -E 's/.*clamav-([0-9]+\.[0-9]+\.[0-9]+)".*/\1/' \
    | sort -t. -k1,1n -k2,2n -k3,3n \
    | tail -n 1
)

if [ -z "${LATEST_VERSION}" ]; then
    echo "Could not determine the latest trusted ${CLAMAV_RELEASE_LINE}.x release." >&2
    exit 1
fi

echo "Latest trusted release: ${LATEST_VERSION}"

HIGHEST_VERSION=$(
    printf '%s\n%s\n' "${CLAMAV_VERSION}" "${LATEST_VERSION}" \
    | sort -t. -k1,1n -k2,2n -k3,3n \
    | tail -n 1
)

if [ "${HIGHEST_VERSION}" = "${CLAMAV_VERSION}" ] \
    && [ "${LATEST_VERSION}" != "${CLAMAV_VERSION}" ]; then
    echo "Refusing downgrade from ${CLAMAV_VERSION} to ${LATEST_VERSION}." >&2
    exit 1
fi

VERSION_CHANGED=false

if [ "${LATEST_VERSION}" != "${CLAMAV_VERSION}" ]; then
    VERSION_CHANGED=true

    echo "Preparing ${CLAMAV_VERSION} -> ${LATEST_VERSION}"

    cp "${VERSION_FILE}" "${VERSION_BACKUP}"

    cat > "${VERSION_FILE}" <<VERSION_EOF
CLAMAV_RELEASE_LINE=${CLAMAV_RELEASE_LINE}
CLAMAV_VERSION=${LATEST_VERSION}
VERSION_EOF
else
    echo "ClamAV engine is already current."
fi

echo "Building verified security-worker image..."

if ! docker compose build --no-cache security-worker; then
    if [ "${VERSION_CHANGED}" = true ]; then
        cp "${VERSION_BACKUP}" "${VERSION_FILE}"
    fi

    echo "ClamAV image build failed; version file was not updated." >&2
    exit 1
fi

# Keep the repository version unchanged until the built image is verified.
if [ "${VERSION_CHANGED}" = true ]; then
    cp "${VERSION_BACKUP}" "${VERSION_FILE}"
fi

echo "Verifying built runtime..."

BUILT_CLAMSCAN_VERSION=$(
    docker compose run --rm --no-deps security-worker clamscan --version \
        | awk 'NR == 1 {print $2}'
)

BUILT_FRESHCLAM_VERSION=$(
    docker compose run --rm --no-deps security-worker freshclam --version \
        | awk 'NR == 1 {print $2}' \
        | cut -d/ -f1
)

if [ "${BUILT_CLAMSCAN_VERSION}" != "${LATEST_VERSION}" ]; then
    echo "Unexpected clamscan version: ${BUILT_CLAMSCAN_VERSION}" >&2
    exit 1
fi

if [ "${BUILT_FRESHCLAM_VERSION}" != "${LATEST_VERSION}" ]; then
    echo "Unexpected freshclam version: ${BUILT_FRESHCLAM_VERSION}" >&2
    exit 1
fi

if [ "${VERSION_CHANGED}" = true ]; then
    cat > "${VERSION_FILE}" <<VERSION_EOF
CLAMAV_RELEASE_LINE=${CLAMAV_RELEASE_LINE}
CLAMAV_VERSION=${LATEST_VERSION}
VERSION_EOF

    echo "Version file committed to ${LATEST_VERSION}."
fi

echo "Deploying updated security-worker..."
docker compose up -d --force-recreate security-worker

echo "Updating virus signatures..."
docker compose exec -T security-worker freshclam

echo "Running clean-file smoke scan..."
docker compose exec -T security-worker sh -lc '
    test_file=/tmp/clamav-update-smoke.txt
    trap "rm -f ${test_file}" EXIT
    printf "ClamAV update smoke test\n" > "${test_file}"
    clamscan "${test_file}"
'

echo
echo "ClamAV maintenance completed successfully."
echo "Engine:     ${LATEST_VERSION}"
echo "Signatures: updated"
