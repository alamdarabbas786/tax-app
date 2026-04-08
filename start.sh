#!/bin/sh
set -e

PORT_TO_USE="${PORT:-3000}"
exec php -S "0.0.0.0:${PORT_TO_USE}" -t public public/router.php
