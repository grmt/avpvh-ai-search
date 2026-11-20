#!/usr/bin/env bash

set -Eeuo pipefail

readonly SSH_TARGET="${GEWEB_SSH_TARGET:?Set GEWEB_SSH_TARGET to user@host}"
readonly SITE_DOMAIN="${GEWEB_SITE_DOMAIN:?Set GEWEB_SITE_DOMAIN to the site domain}"
readonly WORDPRESS_CONTAINER="${GEWEB_WORDPRESS_CONTAINER:-scripts-wordpress-rsp-1}"
readonly WORDPRESS_FILE_USER="${GEWEB_WORDPRESS_FILE_USER:-82}"
readonly PLUGIN_DIR='/var/www/html/wp-content/plugins/geweb-ai-search'
readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly ENDPOINT_TEMPLATE="${SCRIPT_DIR}/retry-failed-jpgs-endpoint.php"

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
            "set -e; docker exec -u '$WORDPRESS_FILE_USER' '$WORDPRESS_CONTAINER' rm -f '$PLUGIN_DIR/$endpoint_name'; rm -f '$remote_endpoint'" \
            >/dev/null 2>&1; then
            echo 'Warning: automatic removal of the temporary image retry endpoint failed.' >&2
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
    sed -e "s|__GEWEB_JPG_RETRY_TOKEN__|${token}|g" \
        "$ENDPOINT_TEMPLATE" >"${transfer_dir}/${endpoint_name}"
    chmod 644 "${transfer_dir}/${endpoint_name}"
}

install_endpoint() {
    scp "${transfer_dir}/${endpoint_name}" "${SSH_TARGET}:${remote_endpoint}" >/dev/null
    ssh "$SSH_TARGET" \
        "docker cp '$remote_endpoint' '$WORDPRESS_CONTAINER:$PLUGIN_DIR/$endpoint_name'" \
        >/dev/null
}

run_retry() {
    local token="$1"
    local dry_run="$2"
    ssh "$SSH_TARGET" \
        "curl -fsS --max-time 900 --resolve '$SITE_DOMAIN:443:127.0.0.1' -X POST --data-urlencode 'token=$token' --data-urlencode 'dry_run=$dry_run' 'https://$SITE_DOMAIN/wp-content/plugins/geweb-ai-search/$endpoint_name'"
}

main() {
    local dry_run='0'
    if [[ "${1:-}" == '--dry-run' ]]; then
        dry_run='1'
        shift
    fi
    if [[ "$#" -ne 0 ]]; then
        echo "Usage: $0 [--dry-run]" >&2
        exit 2
    fi

    require_command openssl
    require_command scp
    require_command sed
    require_command ssh
    validate_setting 'site domain' "$SITE_DOMAIN" '^[A-Za-z0-9.-]+$'
    validate_setting 'WordPress container' "$WORDPRESS_CONTAINER" '^[A-Za-z0-9_.-]+$'
    validate_setting 'WordPress file user' "$WORDPRESS_FILE_USER" '^[0-9]+$'

    transfer_dir="$(mktemp -d)"
    local token
    token="$(openssl rand -hex 32)"
    endpoint_name="geweb-jpg-retry-${token:0:12}.php"
    remote_endpoint="/tmp/${endpoint_name}"
    trap 'cleanup "$?"' EXIT
    trap 'cleanup 130' INT TERM

    prepare_endpoint "$token"
    install_endpoint

    local response
    while true; do
        response="$(run_retry "$token" "$dry_run")"
        if [[ "$response" != *'"ok":true'* ]]; then
        echo "Image retry failed: ${response}" >&2
            exit 1
        fi
        echo "$response"
        if [[ "$dry_run" == '1' || "$response" == *'"matched":0'* ]]; then
            break
        fi
    done
}

main "$@"
