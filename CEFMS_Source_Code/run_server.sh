#!/bin/bash
# Start CEFMS on http://127.0.0.1:8080 (PHP built-in server, 8 workers)
cd "$(dirname "$0")"
export CEFMS_DB_USER=${CEFMS_DB_USER:-cefms} CEFMS_DB_PASS=${CEFMS_DB_PASS:-cefms_pw} PHP_CLI_SERVER_WORKERS=8
exec php -S 127.0.0.1:8080 -t public
