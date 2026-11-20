#!/usr/bin/env bash

set -Eeuo pipefail

readonly SSH_TARGET="${GEWEB_SSH_TARGET:?Set GEWEB_SSH_TARGET to user@host}"
readonly SOURCE_DOMAIN="${GEWEB_SOURCE_DOMAIN:?Set GEWEB_SOURCE_DOMAIN to the source site domain}"
readonly TARGET_DOMAIN="${GEWEB_TARGET_DOMAIN:?Set GEWEB_TARGET_DOMAIN to the target site domain}"
readonly SOURCE_CONTAINER="${GEWEB_SOURCE_CONTAINER:-scripts-wordpress-rsp-1}"
readonly TARGET_CONTAINER="${GEWEB_TARGET_CONTAINER:-scripts-wordpress-kb-1}"
readonly SOURCE_FILE_USER="${GEWEB_SOURCE_FILE_USER:-82}"
readonly TARGET_FILE_USER="${GEWEB_TARGET_FILE_USER:-2000}"
readonly PLUGIN_DIR='/var/www/html/wp-content/plugins/geweb-ai-search'
readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly ENDPOINT_TEMPLATE="${SCRIPT_DIR}/gemini-credential-transfer-endpoint.php"

transfer_dir=''
endpoint_name=''
remote_endpoint=''

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Required command not found: $1" >&2
        exit 1
    fi
}

validate_setting() {
    local label="$1"
    local value="$2"
    local pattern="$3"
    if [[ ! "$value" =~ $pattern ]]; then
        echo "Invalid ${label}: ${value}" >&2
        exit 1
    fi
}

cleanup() {
    local status="${1:-1}"
    trap - EXIT INT TERM
    set +e
    if [[ -n "$remote_endpoint" ]]; then
        if ! ssh "$SSH_TARGET" \
            "set -e; docker exec -u '$SOURCE_FILE_USER' '$SOURCE_CONTAINER' rm -f '$PLUGIN_DIR/$endpoint_name'; docker exec -u '$TARGET_FILE_USER' '$TARGET_CONTAINER' rm -f '$PLUGIN_DIR/$endpoint_name'; rm -f '$remote_endpoint'" \
            >/dev/null 2>&1; then
            echo 'Warning: automatic removal of the temporary transfer endpoint failed.' >&2
            if [[ "$status" -eq 0 ]]; then
                status=1
            fi
        fi
    fi
    if [[ -n "$transfer_dir" && -d "$transfer_dir" ]]; then
        rm -rf -- "$transfer_dir"
    fi
    exit "$status"
}

prepare_endpoint() {
    local token="$1"
    local target_url="https://${TARGET_DOMAIN}/wp-content/plugins/geweb-ai-search/${endpoint_name}"
    sed \
        -e "s|__GEWEB_TRANSFER_TOKEN__|${token}|g" \
        -e "s|__GEWEB_TRANSFER_TARGET_URL__|${target_url}|g" \
        "$ENDPOINT_TEMPLATE" >"${transfer_dir}/${endpoint_name}"
    chmod 644 "${transfer_dir}/${endpoint_name}"
}

install_endpoint() {
    scp "${transfer_dir}/${endpoint_name}" "${SSH_TARGET}:${remote_endpoint}" >/dev/null
    ssh "$SSH_TARGET" \
        "docker cp '$remote_endpoint' '$SOURCE_CONTAINER:$PLUGIN_DIR/$endpoint_name'; docker cp '$remote_endpoint' '$TARGET_CONTAINER:$PLUGIN_DIR/$endpoint_name'" \
        >/dev/null
}

run_transfer() {
    local token="$1"
    ssh "$SSH_TARGET" \
        "curl -fsS --resolve '$SOURCE_DOMAIN:443:127.0.0.1' -X POST --data-urlencode 'token=$token' --data-urlencode 'action=send' 'https://$SOURCE_DOMAIN/wp-content/plugins/geweb-ai-search/$endpoint_name'"
}

main() {
    require_command openssl
    require_command scp
    require_command sed
    require_command ssh

    validate_setting 'source domain' "$SOURCE_DOMAIN" '^[A-Za-z0-9.-]+$'
    validate_setting 'target domain' "$TARGET_DOMAIN" '^[A-Za-z0-9.-]+$'
    validate_setting 'source container' "$SOURCE_CONTAINER" '^[A-Za-z0-9_.-]+$'
    validate_setting 'target container' "$TARGET_CONTAINER" '^[A-Za-z0-9_.-]+$'
    validate_setting 'source file user' "$SOURCE_FILE_USER" '^[0-9]+$'
    validate_setting 'target file user' "$TARGET_FILE_USER" '^[0-9]+$'

    transfer_dir="$(mktemp -d)"
    local token
    token="$(openssl rand -hex 32)"
    endpoint_name="geweb-gemini-transfer-${token:0:12}.php"
    remote_endpoint="/tmp/${endpoint_name}"
    trap 'cleanup "$?"' EXIT
    trap 'cleanup 130' INT TERM

    prepare_endpoint "$token"
    install_endpoint

    local response
    response="$(run_transfer "$token")"
    if [[ "$response" != *'"ok":true'* || "$response" != *'"gemini_connection":"ok"'* ]]; then
        echo "Credential transfer failed: ${response}" >&2
        exit 1
    fi

    echo "Gemini credential copied and validated: ${response}"
}

main "$@"
