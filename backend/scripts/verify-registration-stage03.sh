#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

if [[ "${1:-}" != "" && "${1:-}" != "--fix" ]]; then
    echo "Usage: bash scripts/verify-registration-stage03.sh [--fix]"
    exit 1
fi
if [[ ! -f vendor/autoload.php || ! -f .env.testing ]]; then
    echo "Missing vendor/autoload.php or .env.testing. Complete stages 01 and 02 first."
    exit 1
fi
if [[ -f bootstrap/cache/config.php ]]; then
    echo "Run php artisan config:clear before this check."
    exit 1
fi
stage_files=(
    app/Actions/Auth/RegisterMember.php
    app/Actions/Notifications/RecordEmailVerificationDelivery.php
    app/Data/Auth/RegisterMemberData.php
    app/Exceptions/ApiProblem.php
    app/Http/Controllers/Api/V1/Auth/RegisterMemberController.php
    app/Http/Middleware/AssignRequestId.php
    app/Http/Middleware/RequireStatefulRegistration.php
    app/Http/Middleware/ValidateRegistrationJson.php
    app/Http/Requests/Auth/RegisterMemberRequest.php
    app/Http/Resources/Auth/RegistrationReceiptResource.php
    app/Providers/AppServiceProvider.php
    app/Services/Auth/RegistrationPolicies.php
    app/Services/Members/NormalizePhoneNumber.php
    app/Support/Http/ApiErrorResponse.php
    app/Support/Http/ApiExceptionRenderer.php
    app/Support/Http/RequestId.php
    bootstrap/app.php
    config/sorerunapp.php
    routes/api.php
    tests/Feature/Auth/RegisterMemberTest.php
    tests/Feature/Auth/RegistrationConcurrencyTest.php
    tests/Support/EnforceCsrfInRegistrationTests.php
    tests/Support/registration-race-worker.php
)
for source_file in "${stage_files[@]}"; do
    php -l "$source_file"
done
if [[ "${1:-}" == "--fix" ]]; then
    php vendor/bin/pint "${stage_files[@]}"
fi
php vendor/bin/pint --test "${stage_files[@]}"
php vendor/bin/phpunit -c phpunit.registration.xml
