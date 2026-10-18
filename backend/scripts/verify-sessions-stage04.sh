#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

if [[ "${1:-}" != "" && "${1:-}" != "--fix" ]]; then
    echo "Usage: bash scripts/verify-sessions-stage04.sh [--fix]"
    exit 1
fi
if [[ ! -f vendor/autoload.php || ! -f .env.testing ]]; then
    echo "Complete stages 01-03 and prepare .env.testing first."
    exit 1
fi
if [[ -f bootstrap/cache/config.php ]]; then
    echo "Run php artisan config:clear before this check."
    exit 1
fi
for prerequisite in tests/Feature/Auth/RegisterMemberTest.php tests/Feature/Auth/RegistrationConcurrencyTest.php tests/Support/EnforceCsrfInRegistrationTests.php; do
    if [[ ! -f "$prerequisite" ]]; then
        echo "Missing stage 03 prerequisite: $prerequisite"
        exit 1
    fi
done
stage_files=(
    app/Actions/Auth/AuthenticateMember.php
    app/Data/Auth/LoginMemberData.php
    app/Http/Controllers/Api/V1/Auth/CurrentMemberController.php
    app/Http/Controllers/Api/V1/Auth/LoginMemberController.php
    app/Http/Controllers/Api/V1/Auth/LogoutMemberController.php
    app/Http/Middleware/EnsureCurrentBrowserSession.php
    app/Http/Middleware/RequireBrowserSession.php
    app/Http/Middleware/ValidateLoginJson.php
    app/Http/Requests/Auth/LoginMemberRequest.php
    app/Http/Resources/Auth/BrowserSessionResource.php
    app/Providers/AppServiceProvider.php
    app/Support/Auth/BrowserSession.php
    bootstrap/app.php
    routes/api.php
    tests/Feature/Auth/BrowserSessionTest.php
)
for source_file in "${stage_files[@]}"; do
    php -l "$source_file"
done
if [[ "${1:-}" == "--fix" ]]; then
    php vendor/bin/pint "${stage_files[@]}"
fi
php vendor/bin/pint --test "${stage_files[@]}"
php vendor/bin/phpunit -c phpunit.sessions.xml
