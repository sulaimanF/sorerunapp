#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ -f bootstrap/cache/config.php ]]; then
  echo "Jalankan php artisan config:clear sebelum pengujian." >&2
  exit 1
fi
files=(
  app/Actions/Auth/AuthenticateMember.php
  app/Actions/Auth/ResendVerificationEmail.php
  app/Actions/Memberships/ActivatePendingMembership.php
  app/Actions/Memberships/IssueMemberCredential.php
  app/Actions/Memberships/VerifyEmailAndActivateMembership.php
  app/Http/Controllers/Api/V1/Auth/VerifyMemberEmailController.php
  app/Http/Controllers/Api/V1/Auth/ResendVerificationEmailController.php
  app/Http/Middleware/EnsureCurrentBrowserSession.php
  app/Http/Middleware/ValidatePublicVerificationJson.php
  app/Http/Requests/Auth/ResendVerificationRequest.php
  app/Http/Resources/Auth/EmailVerificationResultResource.php
  app/Notifications/VerifyMembershipEmail.php
  app/Providers/AppServiceProvider.php
  routes/api.php
  routes/email-verification.php
  tests/Feature/Auth/BrowserSessionTest.php
  tests/Feature/Auth/EmailVerificationTest.php
  tests/Support/VerificationBrowser.php
  tests/Support/verification-race-worker.php
)
while IFS= read -r -d '' file; do files+=("$file"); done < <(find app/Actions app/Policies tests/Feature -type f \( -name '*MemberProfile*.php' -o -name '*Profile*Test.php' \) -print0)
for file in "${files[@]}"; do php -l "$file"; done
if [[ "${1:-}" == "--fix" ]]; then php vendor/bin/pint "${files[@]}"; fi
php vendor/bin/pint --test "${files[@]}"
php vendor/bin/phpunit -c phpunit.verification.xml
if [[ -f phpunit.profiles.xml ]]; then
  php vendor/bin/phpunit -c phpunit.profiles.xml --filter '(MemberProfile|ProfileConcurrency)'
fi
