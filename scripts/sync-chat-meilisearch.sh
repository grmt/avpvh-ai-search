#!/usr/bin/env bash

set -Eeuo pipefail

readonly SSH_TARGET="${GEWEB_SSH_TARGET:?Set GEWEB_SSH_TARGET to user@host}"
readonly SITE_DOMAIN="${GEWEB_SITE_DOMAIN:?Set GEWEB_SITE_DOMAIN to the site domain}"
readonly WORDPRESS_CONTAINER="${GEWEB_WORDPRESS_CONTAINER:-scripts-wordpress-rsp-1}"
readonly PLUGIN_DIR='/var/www/html/wp-content/plugins/geweb-ai-search'
readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly ENDPOINT_TEMPLATE="${SCRIPT_DIR}/sync-chat-meilisearch-endpoint.php"

transfer_dir=''
endpoint_name=''
remote_endpoint=''

cleanup() {
    local status="${1:-1}"
    trap - EXIT INT TERM
    set +e
    if [[ -n "$remote_endpoint" ]]; then
        ssh "$SSH_TARGET" "docker exec '$WORDPRESS_CONTAINER' rm -f '$PLUGIN_DIR/$endpoint_name'; rm -f '$remote_endpoint'" >/dev/null 2>&1 || status=1
    fi
    if [[ -n "$transfer_dir" && -d "$transfer_dir" ]]; then
        rm -rf -- "$transfer_dir"
    fi
    exit "$status"
}

main() {
    transfer_dir="$(mktemp -d)"
    local token
    token="$(openssl rand -hex 32)"
    endpoint_name="geweb-chat-meili-${token:0:12}.php"
    remote_endpoint="/tmp/${endpoint_name}"
    trap 'cleanup "$?"' EXIT
    trap 'cleanup 130' INT TERM
    sed "s|__GEWEB_CHAT_MEILI_TOKEN__|${token}|g" "$ENDPOINT_TEMPLATE" >"${transfer_dir}/${endpoint_name}"
    scp "${transfer_dir}/${endpoint_name}" "${SSH_TARGET}:${remote_endpoint}" >/dev/null
    ssh "$SSH_TARGET" "docker cp '$remote_endpoint' '$WORDPRESS_CONTAINER:$PLUGIN_DIR/$endpoint_name'" >/dev/null
    local response
    response="$(ssh "$SSH_TARGET" "curl -fsS --max-time 900 --resolve '$SITE_DOMAIN:443:127.0.0.1' -X POST --data-urlencode 'token=$token' 'https://$SITE_DOMAIN/wp-content/plugins/geweb-ai-search/$endpoint_name'")"
    echo "$response"
    [[ "$response" == *'"ok":true'* ]]
}

main "$@"
