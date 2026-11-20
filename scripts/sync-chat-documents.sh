#!/usr/bin/env bash

set -Eeuo pipefail

readonly SSH_TARGET="${GEWEB_SSH_TARGET:?Set GEWEB_SSH_TARGET to user@host}"
readonly SITE_DOMAIN="${GEWEB_SITE_DOMAIN:?Set GEWEB_SITE_DOMAIN to the site domain}"
readonly WORDPRESS_CONTAINER="${GEWEB_WORDPRESS_CONTAINER:-scripts-wordpress-rsp-1}"
readonly WORDPRESS_FILE_USER="${GEWEB_WORDPRESS_FILE_USER:-82}"
readonly PLUGIN_DIR='/var/www/html/wp-content/plugins/geweb-ai-search'
readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly ENDPOINT_TEMPLATE="${SCRIPT_DIR}/sync-chat-documents-endpoint.php"

transfer_dir=''
endpoint_name=''
remote_endpoint=''

cleanup() {
    local status="${1:-1}"
    trap - EXIT INT TERM
    set +e
    if [[ -n "$remote_endpoint" ]]; then
        ssh "$SSH_TARGET" \
            "set -e; docker exec -u '$WORDPRESS_FILE_USER' '$WORDPRESS_CONTAINER' rm -f '$PLUGIN_DIR/$endpoint_name'; rm -f '$remote_endpoint'" \
            >/dev/null 2>&1 || status=1
    fi
    if [[ -n "$transfer_dir" && -d "$transfer_dir" ]]; then
        rm -rf -- "$transfer_dir"
    fi
    exit "$status"
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

prepare_endpoint() {
    local token="$1"
    sed "s|__GEWEB_CHAT_SYNC_TOKEN__|${token}|g" "$ENDPOINT_TEMPLATE" >"${transfer_dir}/${endpoint_name}"
    chmod 644 "${transfer_dir}/${endpoint_name}"
}

install_endpoint() {
    scp "${transfer_dir}/${endpoint_name}" "${SSH_TARGET}:${remote_endpoint}" >/dev/null
    ssh "$SSH_TARGET" "docker cp '$remote_endpoint' '$WORDPRESS_CONTAINER:$PLUGIN_DIR/$endpoint_name'" >/dev/null
}

run_batch() {
    local token="$1"
    ssh "$SSH_TARGET" \
        "curl -fsS --max-time 900 --resolve '$SITE_DOMAIN:443:127.0.0.1' -X POST --data-urlencode 'token=$token' --data-urlencode 'limit=3' 'https://$SITE_DOMAIN/wp-content/plugins/geweb-ai-search/$endpoint_name'"
}

main() {
    validate_setting 'site domain' "$SITE_DOMAIN" '^[A-Za-z0-9.-]+$'
    validate_setting 'WordPress container' "$WORDPRESS_CONTAINER" '^[A-Za-z0-9_.-]+$'
    validate_setting 'WordPress file user' "$WORDPRESS_FILE_USER" '^[0-9]+$'
    transfer_dir="$(mktemp -d)"
    local token
    token="$(openssl rand -hex 32)"
    endpoint_name="geweb-chat-sync-${token:0:12}.php"
    remote_endpoint="/tmp/${endpoint_name}"
    trap 'cleanup "$?"' EXIT
    trap 'cleanup 130' INT TERM
    prepare_endpoint "$token"
    install_endpoint

    local response
    while true; do
        response="$(run_batch "$token")"
        echo "$response"
        if [[ "$response" != *'"ok":true'* ]]; then
            echo 'Chat document synchronization failed.' >&2
            exit 1
        fi
        if [[ "$response" == *'"remaining":0'* ]]; then
            break
        fi
    done
}

main "$@"
