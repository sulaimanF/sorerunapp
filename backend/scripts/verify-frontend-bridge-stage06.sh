#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ -f bootstrap/cache/config.php ]]; then
  echo "Jalankan php artisan config:clear sebelum pengujian." >&2
  exit 1
fi
files=(
  "app/Services/Auth/EmailVerificationUrl.php"
  "app/Services/Notifications/DeliverNotification.php"
  "config/sorerunapp.php"
  "config/cors.php"
  "tests/Feature/Auth/EmailVerificationBrowserUrlTest.php"
  "tests/Feature/Notifications/NotificationOutboxTest.php"
)
for file in "${files[@]}"; do php -l "$file"; done
if [[ "${1:-}" == "--fix" ]]; then php vendor/bin/pint "${files[@]}"; fi
php vendor/bin/pint --test "${files[@]}"
php vendor/bin/phpunit -c phpunit.verification.xml
