<?php
declare(strict_types=1);

final class Perms
{
    public const ALL = [
        'res.read' => 'Admin kalendar s punim podacima',
        'res.write' => 'Pravljenje, izmjena i otkaz rezervacija',
        'res.attendance' => 'Dolazak / nedolazak / oslobađanje staze',
        'cash.collect' => 'Naplata rekreacije, patika i duga',
        'cash.correct' => 'Ispravka (storno) evidentirane naplate',
        'monthly.manage' => 'Aktivacija i produženje mjesečne',
        'monthly.vip' => 'VIP mjesečna i sedmični sati',
        'bonus.manage' => 'Ručna dodjela bonus sati',
        'users.manage' => 'Ručno pravljenje i uređivanje profila',
        'users.block' => 'Blokiranje naloga',
        'debt.forgive' => 'Oprost duga',
        'clubs.manage' => 'Klubovi, članstvo, stalni termini',
        'clubs.billing' => 'Obračuni klubova',
        'events.manage' => 'Utakmice, turniri, kvalifikacije',
        'events.birthday' => 'Rođendani',
        'events.entries' => 'Unos igrača u događaje',
        'day.view' => 'Pregled dnevnog prometa',
        'day.close' => 'Zaključivanje dana',
        'stats.view' => 'Statistika',
        'prices.manage' => 'Cijene',
        'settings.manage' => 'Postavke i obavijesti',
        'audit.view' => 'Historija promjena',
    ];
    public const DEFAULTS = [
        'manager' => ['res.read', 'res.write', 'res.attendance', 'cash.collect', 'cash.correct', 'monthly.manage', 'monthly.vip', 'bonus.manage', 'users.manage', 'users.block', 'debt.forgive', 'clubs.manage', 'clubs.billing', 'events.manage', 'events.birthday', 'events.entries', 'day.view', 'day.close', 'audit.view'],
        'staff' => ['res.read', 'res.write', 'res.attendance', 'cash.collect', 'monthly.manage', 'events.birthday', 'events.entries', 'day.view', 'day.close'],
        'guest' => [],
    ];

    public static function forUser(?array $u): array
    {
        if (!$u) return [];
        if ($u['role'] === 'owner') return array_keys(self::ALL);
        if ($u['role'] === 'guest') return [];
        return array_column(Db::all('SELECT perm FROM user_permissions WHERE user_id = ?', [$u['id']]), 'perm');
    }
    public static function has(?array $u, string $perm): bool
    {
        if (!$u) return false;
        if ($u['role'] === 'owner') return true;
        return in_array($perm, self::forUser($u), true);
    }
    public static function require(?array $u, string $perm): void
    {
        if (!$u) throw new AppError('Potrebna je prijava.', 401);
        if (!self::has($u, $perm)) throw new AppError('Nemate ovlaštenje za ovu radnju.', 403);
    }
    public static function isStaff(?array $u): bool { return $u !== null && in_array($u['role'], ['owner', 'manager', 'staff'], true); }
    public static function setFor(int $userId, array $perms): void
    {
        $perms = array_values(array_intersect($perms, array_keys(self::ALL)));
        Db::q('DELETE FROM user_permissions WHERE user_id = ?', [$userId]);
        foreach ($perms as $p) Db::insert('user_permissions', ['user_id' => $userId, 'perm' => $p]);
    }
}

final class RateLimit
{
    /** Vraća false kad je limit prekoračen. */
    public static function hit(string $bucket, int $max, int $windowSec): bool
    {
        return Db::tx(function () use ($bucket, $max, $windowSec) {
            $r = Db::one('SELECT hits, window_start_utc FROM rate_limits WHERE bucket = ? FOR UPDATE', [$bucket]);
            $now = Clock::nowUtc();
            if (!$r || strtotime($r['window_start_utc'] . ' UTC') + $windowSec < strtotime($now . ' UTC')) {
                Db::q('REPLACE INTO rate_limits (bucket, hits, window_start_utc) VALUES (?, 1, ?)', [$bucket, $now]);
                return true;
            }
            if ((int) $r['hits'] >= $max) return false;
            Db::q('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
            return true;
        });
    }
    public static function clear(string $bucket): void { Db::q('DELETE FROM rate_limits WHERE bucket = ?', [$bucket]); }
}

final class Auth
{
    public const COOKIE = 'rs_sess';
    public static ?array $user = null;
    /** U testovima: posljednji poslani kod (samo dev/test okruženje). */
    public static ?string $lastCode = null;
    /** Stvarni status slanja posljednjeg koda (email): sent | not_configured | failed | pending. */
    public static ?string $lastCodeStatus = null;

    public static function userById(int $id): ?array { return Db::one('SELECT * FROM users WHERE id = ?', [$id]); }
    public static function userByPhone(string $phone): ?array { return Db::one('SELECT * FROM users WHERE phone = ?', [$phone]); }

    public static function actorFor(?array $u): array
    {
        if (!$u) return ['id' => null, 'label' => 'Sistem'];
        $label = $u['role'] === 'staff' ? trim($u['first_name'] . ' ' . $u['last_name']) : trim($u['first_name'] . ' ' . $u['last_name']);
        $roles = ['owner' => 'vlasnik', 'manager' => 'menadžer', 'staff' => 'osoblje', 'guest' => 'gost'];
        return ['id' => (int) $u['id'], 'label' => $label . ' (' . $roles[$u['role']] . ')'];
    }

    public static function validatePassword(string $p): void
    {
        if (mb_strlen($p) < 8) throw new AppError('Lozinka mora imati najmanje 8 znakova.');
        if (mb_strlen($p) > 200) throw new AppError('Lozinka je predugačka.');
    }

    /** Kod se šalje na $email (potvrđeni kanal); $phone se čuva uz kod samo radi evidencije/debaga. */
    private static function newCode(string $email, string $purpose, ?array $payload, ?string $phone = null): string
    {
        if (!RateLimit::hit("code:$purpose:$email", 3, 3600)) throw new AppError('Previše zahtjeva za kod. Pokušajte ponovo za sat vremena.', 429);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Db::q('UPDATE verification_codes SET used_utc = ? WHERE email = ? AND purpose = ? AND used_utc IS NULL', [Clock::nowUtc(), $email, $purpose]);
        $id = Db::insert('verification_codes', [
            'phone' => $phone ?? '', 'email' => $email, 'purpose' => $purpose, 'code_hash' => hash('sha256', $code . '|' . $email),
            'payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'created_utc' => Clock::nowUtc(), 'expires_utc' => Clock::utcPlus('+15 minutes'),
        ]);
        $oid = Notify::direct('verify_code', 'email', $email, "Arena Bakarni: vaš kod je $code (važi 15 minuta).", "code:$id", 'Arena Bakarni — potvrda');
        self::$lastCodeStatus = Notify::sendNow($oid);
        if (Config::get('env') !== 'prod') self::$lastCode = $code;
        return $code;
    }

    private static function checkCode(string $email, string $purpose, string $code): array
    {
        $row = Db::one('SELECT * FROM verification_codes WHERE email = ? AND purpose = ? AND used_utc IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE', [$email, $purpose]);
        if (!$row || $row['expires_utc'] < Clock::nowUtc()) throw new AppError('Kod je istekao ili ne postoji. Zatražite novi.');
        if ((int) $row['attempts'] >= 5) throw new AppError('Previše pogrešnih pokušaja. Zatražite novi kod.', 429);
        if (!hash_equals($row['code_hash'], hash('sha256', trim($code) . '|' . $email))) {
            Db::q('UPDATE verification_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            throw new AppError('Pogrešan kod.');
        }
        Db::q('UPDATE verification_codes SET used_utc = ? WHERE id = ?', [Clock::nowUtc(), $row['id']]);
        return $row;
    }

    /** Registracija/oporavak putem emaila: u produkciji tek kad je email kanal povezan I izričito uključeno ('registration_enabled' => true). */
    public static function registrationOpen(): bool
    {
        if (Config::get('env') !== 'prod') return true;
        return Config::get('registration_enabled') === true && Notify::channel('email') !== null;
    }
    private static function requireRegistration(): void
    {
        if (!self::registrationOpen()) throw new AppError('Registracija i oporavak lozinke još nisu uključeni. Za rezervaciju se obratite Areni.', 503);
    }

    public static function registerStart(array $in): array
    {
        self::requireRegistration();
        $first = str_req($in, 'first_name', 80); $last = str_req($in, 'last_name', 80);
        $phone = Phone::require($in['phone'] ?? null);
        $email = str_req($in, 'email', 190);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppError('Neispravan email.');
        $pass = (string) ($in['password'] ?? ''); self::validatePassword($pass);
        $ex = self::userByPhone($phone);
        if ($ex && $ex['password_hash'] && $ex['email_verified_utc']) throw new AppError('Ovaj broj je već registrovan. Prijavite se ili oporavite lozinku.', 409);
        self::newCode($email, 'register', ['phone' => $phone, 'first_name' => $first, 'last_name' => $last, 'email' => $email, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT)], $phone);
        return ['email' => $email];
    }

    /** Potvrda emaila. Ako profil s tim telefonom postoji (napravilo ga osoblje), povezuje se bez dupliranja. */
    public static function registerConfirm(array $in): array
    {
        $email = str_req($in, 'email', 190);
        return Db::tx(function () use ($email, $in) {
            $row = self::checkCode($email, 'register', (string) ($in['code'] ?? ''));
            $p = json_decode((string) $row['payload'], true);
            $phone = $p['phone'];
            $ex = Db::one('SELECT * FROM users WHERE phone = ? FOR UPDATE', [$phone]);
            if ($ex && $ex['password_hash'] && $ex['email_verified_utc']) throw new AppError('Ovaj broj je već registrovan.', 409);
            if ($ex) {
                Db::update('users', [
                    'first_name' => $ex['first_name'] ?: $p['first_name'], 'last_name' => $ex['last_name'] ?: $p['last_name'],
                    'email' => $p['email'], 'password_hash' => $p['password_hash'], 'email_verified_utc' => Clock::nowUtc(), 'updated_utc' => Clock::nowUtc(),
                ], 'id = ?', [$ex['id']]);
                $id = (int) $ex['id'];
                Audit::log('user.linked', 'user', $id, ['phone' => $phone, 'email' => $email]);
            } else {
                $id = Db::insert('users', ['first_name' => $p['first_name'], 'last_name' => $p['last_name'], 'phone' => $phone, 'email' => $p['email'],
                    'password_hash' => $p['password_hash'], 'email_verified_utc' => Clock::nowUtc(), 'role' => 'guest', 'created_utc' => Clock::nowUtc()]);
                Audit::log('user.registered', 'user', $id, ['phone' => $phone, 'email' => $email]);
            }
            return ['user_id' => $id, 'token' => self::createSession($id)];
        });
    }

    public static function login(string $identifier, string $password, string $ip = ''): array
    {
        $identifier = trim($identifier);
        $phone = Phone::normalize($identifier);
        $key = $phone ?? mb_strtolower($identifier);
        // Ograničenje pokušaja prijave (tražena sigurnosna mjera) važi u produkciji i u automatskim testovima;
        // na probi/razvojnom okruženju (env 'dev') je isključeno, da ponovljeni ručni pokušaji pri testiranju ne zaključaju nalog.
        if (Config::get('env') !== 'dev' && (!RateLimit::hit("login:$key", 5, 900) || ($ip !== '' && !RateLimit::hit("loginip:$ip", 30, 900)))) {
            throw new AppError('Previše pokušaja prijave. Pokušajte ponovo za 15 minuta.', 429);
        }
        $u = null;
        if (preg_match('/^[a-z0-9._-]{3,40}$/i', $identifier) && !preg_match('/^\+?\d+$/', $identifier)) $u = Db::one('SELECT * FROM users WHERE username = ?', [mb_strtolower($identifier)]);
        if (!$u && $phone) $u = self::userByPhone($phone);
        $ok = $u && $u['password_hash'] && password_verify($password, $u['password_hash']);
        if (!$ok) {
            if (!$u || !$u['password_hash']) password_verify($password, password_hash('dummy-timing', PASSWORD_DEFAULT)); // ujednačeno vrijeme odgovora
            throw new AppError('Pogrešan telefon ili lozinka.', 401);
        }
        if ($u['role'] === 'guest' && !$u['email_verified_utc']) throw new AppError('Nalog nije aktiviran — potvrdite email.', 401);
        RateLimit::clear("login:$key");
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
        return ['user_id' => (int) $u['id'], 'token' => self::createSession((int) $u['id'])];
    }

    /** Identifikacija kao prijava (telefon); kod ide na potvrđeni email s naloga, bez otkrivanja da li broj postoji. */
    public static function resetStart(array $in): array
    {
        self::requireRegistration();
        $phone = Phone::require($in['phone'] ?? null);
        $u = self::userByPhone($phone);
        if ($u && $u['email_verified_utc'] && $u['email']) self::newCode($u['email'], 'reset', null, $phone);
        return [];
    }

    public static function resetConfirm(array $in): array
    {
        $phone = Phone::require($in['phone'] ?? null);
        $pass = (string) ($in['password'] ?? ''); self::validatePassword($pass);
        return Db::tx(function () use ($phone, $in, $pass) {
            $u = self::userByPhone($phone);
            if (!$u || !$u['email']) throw new AppError('Nalog ne postoji.');
            self::checkCode($u['email'], 'reset', (string) ($in['code'] ?? ''));
            Db::update('users', ['password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'updated_utc' => Clock::nowUtc()], 'id = ?', [$u['id']]);
            Db::q('DELETE FROM sessions WHERE user_id = ?', [$u['id']]);
            Audit::log('user.password_reset', 'user', $u['id']);
            return ['user_id' => (int) $u['id'], 'token' => self::createSession((int) $u['id'])];
        });
    }

    public static function createSession(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        Db::insert('sessions', ['token_hash' => hash('sha256', $token), 'user_id' => $userId, 'created_utc' => Clock::nowUtc(), 'expires_utc' => Clock::utcPlus('+30 days'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
        return $token;
    }
    public static function fromToken(?string $token): ?array
    {
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        return Db::one('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires_utc > ?', [hash('sha256', $token), Clock::nowUtc()]);
    }
    public static function logout(?string $token): void { if ($token) Db::q('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $token)]); }

    /** Profil koji pravi osoblje (bez lozinke) ili pronalazi postojeći po telefonu. */
    public static function findOrCreateProfile(array $in, array $by): array
    {
        $phone = Phone::require($in['phone'] ?? null);
        $ex = self::userByPhone($phone);
        if ($ex) return $ex;
        $first = str_req($in, 'first_name', 80);
        $id = Db::insert('users', ['first_name' => $first, 'last_name' => (string) ($in['last_name'] ?? ''), 'phone' => $phone,
            'email' => str_opt($in, 'email', 190), 'role' => 'guest', 'created_by' => $by['id'], 'created_utc' => Clock::nowUtc()]);
        Audit::log('user.profile_created', 'user', $id, ['phone' => $phone]);
        return self::userById($id);
    }

    public static function publicUser(array $u): array
    {
        return ['id' => (int) $u['id'], 'first_name' => $u['first_name'], 'last_name' => $u['last_name'], 'phone' => $u['phone'], 'phone_pretty' => Phone::pretty($u['phone']),
            'email' => $u['email'], 'username' => $u['username'], 'role' => $u['role'], 'club_id' => $u['club_id'] ? (int) $u['club_id'] : null,
            'blocked' => $u['blocked_utc'] !== null, 'has_account' => (bool) $u['password_hash'], 'verified' => (bool) $u['email_verified_utc']];
    }
}
