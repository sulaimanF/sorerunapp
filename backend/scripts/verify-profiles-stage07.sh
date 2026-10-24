#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ -f bootstrap/cache/config.php ]]; then
  echo "Jalankan php artisan config:clear sebelum pengujian." >&2
  exit 1
fi
files=(
  "app/Data/Members/UpdateMemberProfileData.php"
  "app/Policies/MemberProfilePolicy.php"
  "app/Http/Requests/Members/UpdateMemberProfileRequest.php"
  "app/Http/Middleware/ValidateProfileJson.php"
  "app/Actions/Memberships/ActivatePendingMembership.php"
  "app/Actions/Members/GetMemberProfile.php"
  "app/Actions/Members/UpdateMemberProfile.php"
  "app/Http/Resources/Members/MemberProfileResource.php"
  "app/Http/Controllers/Api/V1/Members/ShowMemberProfileController.php"
  "app/Http/Controllers/Api/V1/Members/UpdateMemberProfileController.php"
  "app/Actions/Memberships/VerifyEmailAndActivateMembership.php"
  "app/Providers/AppServiceProvider.php"
  "bootstrap/app.php"
  "routes/api.php"
  "tests/Feature/Members/MemberProfileTest.php"
  "tests/Feature/Members/ProfileConcurrencyTest.php"
  "tests/Support/profile-race-worker.php"
)
for file in "${files[@]}"; do php -l "$file"; done
if [[ "${1:-}" == "--fix" ]]; then php vendor/bin/pint "${files[@]}"; fi
php vendor/bin/pint --test "${files[@]}"
php vendor/bin/phpunit -c phpunit.profiles.xml
