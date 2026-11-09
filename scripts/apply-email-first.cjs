const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const planned = new Map();
function edit(file, transform) {
  const target = path.join(root, file);
  if (!fs.existsSync(target)) throw new Error(`File wajib tidak ditemukan: ${file}`);
  const source = fs.readFileSync(target, 'utf8').replace(/\r\n/g, '\n');
  const next = transform(source);
  if (next !== source) planned.set(file, next);
}
function optional(file, transform) { if (fs.existsSync(path.join(root, file))) edit(file, transform); }
function once(source, anchor, value, file) {
  if (!source.includes(anchor) || source.indexOf(anchor) !== source.lastIndexOf(anchor)) throw new Error(`Anchor tidak unik pada ${file}; tidak ada perubahan ditulis.`);
  return source.replace(anchor, value);
}
edit('backend/routes/api.php', source => {
  if (source.includes('// email-first: public resend')) return source;
  const old = /\s*Route::post\('\/auth\/email-verification\/resend', ResendVerificationEmailController::class\)[\s\S]*?->name\('api\.v1\.auth\.email-verification\.resend'\);/g;
  if ([...source.matchAll(old)].length !== 1) throw new Error('Route resend lama tidak unik; perubahan dibatalkan.');
  source = source.replace(old, '');
  return once(source, "    Route::post('/auth/login', LoginMemberController::class)", `    // email-first: public resend
    Route::post('/auth/email-verification/resend', ResendVerificationEmailController::class)
        ->middleware([\\App\\Http\\Middleware\\ValidatePublicVerificationJson::class, RequireStatefulRegistration::class, 'throttle:verification-resend'])
        ->name('api.v1.auth.email-verification.resend');

    Route::post('/auth/login', LoginMemberController::class)`, 'routes/api.php');
});
edit('backend/app/Providers/AppServiceProvider.php', source => {
  if (source.includes('// email-first: uniform public limits')) return source;
  const callback = /        RateLimiter::for\('verification-resend', function \(Request \$request\): array \{[\s\S]*?\n        \}\);/g;
  if ([...source.matchAll(callback)].length !== 1) throw new Error('Callback limiter tidak unik; perubahan dibatalkan.');
  return source.replace(callback, `        // email-first: uniform public limits, no account lookup in the limiter.
        RateLimiter::for('verification-resend', function (Request $request): array {
            $ip = hash('sha256', (string) $request->ip());
            $email = $request->input('email');
            $key = is_string($email) ? hash('sha256', mb_strtolower(trim($email), 'UTF-8')) : 'invalid-'.$ip;

            return [
                Limit::perMinute(5)->by('verification:minute:'.$ip),
                Limit::perHour(20)->by('verification:ip:'.$ip),
                Limit::perHour(5)->by('verification:email:'.$key),
            ];
        });`);
});
function walk(directory) {
  if (!fs.existsSync(directory)) return [];
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
    const next = path.join(directory, entry.name);
    return entry.isDirectory() ? walk(next) : [next];
  });
}
for (const file of walk(path.join(root, 'backend/app/Actions'))) {
  if (!['GetMemberProfile.php', 'UpdateMemberProfile.php'].includes(path.basename(file))) continue;
  edit(path.relative(root, file), source => {
    if (source.includes("'EMAIL_NOT_VERIFIED'")) return source;
    const anchor = '            $profile = $user->memberProfile()';
    return once(source, anchor, `            if ($user->email_verified_at === null) {
                throw new \\App\\Exceptions\\ApiProblem('EMAIL_NOT_VERIFIED', 'Verifikasi email Anda sebelum masuk.', 403);
            }

${anchor}`, path.basename(file));
  });
}
optional('backend/app/Policies/MemberProfilePolicy.php', source => {
  if (source.includes('$user->email_verified_at !== null')) return source;
  const needle = '$user->status === AccountStatus::Enabled';
  return once(source, needle, needle+' && $user->email_verified_at !== null', 'MemberProfilePolicy');
});
for (const file of walk(path.join(root, 'backend/tests/Feature'))) {
  if (!/Profile.*Test\.php$/.test(path.basename(file))) continue;
  edit(path.relative(root, file), source => {
    if (!source.includes('function signedIn(') || source.includes('// email-first: verify fixture before login')) return source;
    const helper = /(private|protected) function signedIn\(User \$user\)[^{]*\{/g;
    if ([...source.matchAll(helper)].length !== 1) throw new Error(`Helper profil tidak dikenali: ${file}`);
    source = source.replace(helper, match => match + `
        // email-first: verify fixture before login, using the same domain transaction.
        if ($user->fresh()->email_verified_at === null) {
            app(\\App\\Actions\\Memberships\\VerifyEmailAndActivateMembership::class)->handle(
                (int) $user->id, (string) $user->id, sha1($user->email), (string) $user->auth_version,
                (string) \\Illuminate\\Support\\Str::uuid(), $user->auth_version, publicProof: true,
            );
        }
`);
    const start = source.indexOf('// email-first: verify fixture before login');
    const end = source.indexOf('\n    }', start);
    source = source.slice(0, start).replaceAll('$this->login($user)->assertOk();', '$this->signedIn($user);')
      + source.slice(start, end) + source.slice(end).replaceAll('$this->login($user)->assertOk();', '$this->signedIn($user);');
    source = source.replace(/    public function test_[\s\S]*?(?=\n    (?:public|private|protected) |\n    #\[|\n\})/g, method => {
      if (!method.includes('$this->signedIn($user)') || !method.includes("'PATCH'")
          || !method.includes('assertNull($user->fresh()->email_verified_at)') || method.includes("'email_verified_at' => null")) return method;
      return method.replaceAll('assertNull($user->fresh()->email_verified_at)', 'assertNotNull($user->fresh()->email_verified_at)')
        .replaceAll('MembershipStatus::PendingVerification', 'MembershipStatus::Active')
        .replaceAll("'pending_verification'", "'active'")
        .replaceAll('assertSame(0, $user->membership->credentials()->count())', 'assertSame(1, $user->membership->credentials()->count())');
    });
    return source;
  });
}
for (const file of walk(path.join(root, 'backend/tests/Feature'))) {
  if (!/ProfileConcurrencyTest\.php$/.test(path.basename(file))) continue;
  edit(path.relative(root, file), source => source.replace(/\$this->assertOneActivation\(\['profile',\s*'activation'\](?:, false)?\);/g, "$this->assertOneActivation(['profile', 'activation'], true);"));
}
optional('.gitignore', source => source.includes('.email-first-backup/') ? source : source.trimEnd()+'\n\n# Source backup for the email-first revision\n.email-first-backup/\n');
for (const file of ['docs/PANDUAN_TAHAP_06.md', 'docs/API_CONTRACT.md', 'docs/PANDUAN_TAHAP_07.md', 'docs/PROFILE_STAGE_07.md', 'docs/FRONTEND_STAGE_06.md']) {
  optional(file, source => source.includes('ALUR EMAIL-FIRST') ? source : '> **ALUR EMAIL-FIRST (24 Oktober 2026):** aturan login/verifikasi lama pada dokumen tahap ini telah digantikan oleh `docs/PANDUAN_EMAIL_FIRST.md` dan `docs/API_EMAIL_FIRST.md`. Verifikasi email wajib sebelum login; pengujian profil memakai akun terverifikasi.\n\n'+source);
}
optional('backend/docs/API_CONTRACT.md', source => {
  if (source.includes('## Revisi email-first — 24 Oktober 2026')) return source;
  source = source.replace('Member unverified boleh login.', 'Member unverified tidak dapat login: password benar menghasilkan 403 EMAIL_NOT_VERIFIED tanpa sesi authenticated.')
    .replace('"email_verified": false', '"email_verified": true')
    .replace('Cookie pemilik, CSRF, sesi current | 202 verification_requested; 429 cooldown/rate limit', 'Publik stateful + CSRF, JSON email | 202 generik + Retry-After 60; 429 request limit')
    .replace('Cookie pemilik, sesi current, signed URL 60 menit | 200 hasil verifikasi/aktivasi', 'Publik signed URL 60 menit | 200 verification_link_ready; tanpa mutasi')
    .replace('meminta login pemilik bila perlu, lalu GET ke signed API URL setelah konfirmasi pengguna', 'melakukan POST ke signed API URL dengan CSRF setelah konfirmasi pengguna, tanpa login')
    .replace('forUser(), TTL 60 menit, authenticated owner, hash email/version, dan transaksi aktivasi tetap berlaku.', 'forUser(), TTL 60 menit, hash email/version, dan transaksi aktivasi tetap berlaku; proof awal publik menggantikan authenticated owner.');
  return '> **Kontrak autentikasi terbaru:** lihat ../../docs/API_EMAIL_FIRST.md. Ketentuan berbeda pada catatan tahap sebelumnya di bawah telah digantikan.\n\n'+source+`

## Revisi email-first — 24 Oktober 2026

Login wajib enabled + verified. GET signed URL read-only. POST signed URL + CSRF + JSON {} memverifikasi tanpa login/auto-login. Response publik hanya result dan meta; tanpa identitas, nomor, atau QR. Resend publik menerima email dengan respons generik dan cooldown. Rincian dan pengujian: ../../docs/API_EMAIL_FIRST.md dan ../../docs/PANDUAN_EMAIL_FIRST.md.
`;
});
// Every expected anchor is validated before the first write. Source backups only.
if (planned.size) {
  const backup = path.join(root, '.email-first-backup', new Date().toISOString().replace(/[:.]/g, '-'));
  for (const [file, content] of planned) {
    const saved = path.join(backup, file); fs.mkdirSync(path.dirname(saved), { recursive: true });
    fs.copyFileSync(path.join(root, file), saved); fs.writeFileSync(path.join(root, file), content);
    console.log(`Diperbarui: ${file}`);
  }
  console.log('Backup source tersedia di .email-first-backup/. Jangan commit folder ini.');
} else console.log('Patch shared files sudah diterapkan.');
