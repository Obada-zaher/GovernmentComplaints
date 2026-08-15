#!/usr/bin/env sh
set -eu

exec php artisan schedule:run
