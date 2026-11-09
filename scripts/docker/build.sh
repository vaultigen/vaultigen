#!/bin/sh
# Local builds never log in to a registry or push images.
set -eu
cd "$(dirname "$0")/../.."

revision=$(git rev-parse HEAD 2>/dev/null || printf 'local')
exec "${CONTAINER_ENGINE:-docker}" build --target production --tag vaultigen:local \
    --build-arg "SOURCE_REVISION=$revision" "$@" .
