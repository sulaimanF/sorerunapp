#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [[ "${1:-}" != "" && "${1:-}" != "--fix" ]]; then
    echo "Usage: bash scripts/verify-models-stage02.sh [--fix]"
    exit 1
fi

if [[ ! -f vendor/autoload.php || ! -f .env.testing ]]; then
    echo "Missing vendor/autoload.php or .env.testing. Complete stage 01 setup first."
    exit 1
fi

if [[ -f bootstrap/cache/config.php ]]; then
    echo "Clear cached configuration with php artisan config:clear, then run this check again."
    exit 1
fi

stage_files=(
    app/Models/User.php
    app/Models/Role.php
    app/Models/UserRole.php
    app/Models/MemberProfile.php
    app/Models/Membership.php
    app/Models/MemberNumberSequence.php
    app/Models/MemberCredential.php
    app/Models/PolicyAcknowledgement.php
    app/Models/AuditLog.php
    app/Models/NotificationDelivery.php
    tests/Feature/Models/MembershipModelsTest.php
)

for source_file in "${stage_files[@]}"; do
    php -l "$source_file"
done

if [[ "${1:-}" == "--fix" ]]; then
    php vendor/bin/pint "${stage_files[@]}"
fi

php vendor/bin/pint --test "${stage_files[@]}"
php vendor/bin/phpunit -c phpunit.models.xml
