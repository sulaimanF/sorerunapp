#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ -f bootstrap/cache/config.php ]]; then
  echo "Hentikan pengujian: jalankan php artisan config:clear agar konfigurasi test tidak memakai cache environment lain." >&2
  exit 1
fi
files=(
  "app/Actions/Auth/ResendVerificationEmail.php"
  "app/Actions/Memberships/GetMembershipCard.php"
  "app/Actions/Memberships/IssueMemberCredential.php"
  "app/Actions/Memberships/VerifyEmailAndActivateMembership.php"
  "app/Actions/Notifications/EnqueueNotificationDelivery.php"
  "app/Actions/Notifications/RecordEmailVerificationDelivery.php"
  "app/Actions/Notifications/RecordNotificationDelivery.php"
  "app/Console/Commands/DispatchNotificationOutbox.php"
  "app/Data/Notifications/DeliveryClaim.php"
  "app/Http/Controllers/Api/V1/Auth/ResendVerificationEmailController.php"
  "app/Http/Controllers/Api/V1/Auth/VerifyMemberEmailController.php"
  "app/Http/Controllers/Api/V1/Memberships/MembershipCardController.php"
  "app/Http/Middleware/RequireVerificationSession.php"
  "app/Http/Middleware/ValidateEmailVerificationSignature.php"
  "app/Http/Requests/Auth/ResendVerificationRequest.php"
  "app/Http/Resources/Auth/EmailVerificationResultResource.php"
  "app/Http/Resources/Memberships/MembershipCardResource.php"
  "app/Jobs/DeliverOutboxNotification.php"
  "app/Notifications/MembershipActivated.php"
  "app/Notifications/VerifyMembershipEmail.php"
  "app/Providers/AppServiceProvider.php"
  "app/Services/Auth/EmailVerificationUrl.php"
  "app/Services/Notifications/DeliverNotification.php"
  "bootstrap/app.php"
  "config/sorerunapp.php"
  "routes/api.php"
  "routes/email-verification.php"
  "tests/Feature/Auth/EmailVerificationTest.php"
  "tests/Feature/Auth/VerificationConcurrencyTest.php"
  "tests/Feature/Notifications/NotificationOutboxTest.php"
  "tests/Support/VerificationBrowser.php"
  "tests/Support/VerificationFixtures.php"
  "tests/Support/verification-race-worker.php"
)
for file in "${files[@]}"; do
  php -l "$file"
done
if [[ "${1:-}" == "--fix" ]]; then
  php vendor/bin/pint "${files[@]}"
elif [[ $# -gt 0 ]]; then
  echo "Usage: bash scripts/verify-verification-stage05.sh [--fix]" >&2
  exit 2
fi
php vendor/bin/pint --test "${files[@]}"
php vendor/bin/phpunit -c phpunit.verification.xml
