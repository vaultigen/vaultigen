#!/bin/sh
set -eu

# Private Oracle client libraries must not affect PHP or the MariaDB tools.
export LD_LIBRARY_PATH=/opt/mysql/lib
exec "/opt/mysql/bin/$(basename "$0")" --plugin-dir=/opt/mysql/lib/plugin "$@"
