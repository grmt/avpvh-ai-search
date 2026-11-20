#!/usr/bin/env bash

set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly PROJECT_DIR="$(dirname -- "$SCRIPT_DIR")"
readonly REVIEW_ROOT="${PROJECT_DIR}/.chat-review/chats"
readonly REVIEW_HOST="${GEWEB_CHAT_REVIEW_HOST:-127.0.0.1}"
readonly REVIEW_PORT="${GEWEB_CHAT_REVIEW_PORT:-8765}"

if [[ "$REVIEW_HOST" != '127.0.0.1' && "$REVIEW_HOST" != '::1' ]]; then
    echo 'The chat review server may only bind to a loopback address.' >&2
    exit 2
fi
if [[ ! "$REVIEW_PORT" =~ ^[0-9]+$ ]] || (( REVIEW_PORT < 1024 || REVIEW_PORT > 65535 )); then
    echo "Invalid review port: ${REVIEW_PORT}" >&2
    exit 2
fi
if [[ ! -f "${REVIEW_ROOT}/manifest.json" ]]; then
    echo 'Chat review files are missing. Run split-skype-chats.php first.' >&2
    exit 1
fi

cd "$PROJECT_DIR"
echo "Chat review: http://${REVIEW_HOST}:${REVIEW_PORT}/"
exec php -S "${REVIEW_HOST}:${REVIEW_PORT}" "$SCRIPT_DIR/chat-review-server.php"
