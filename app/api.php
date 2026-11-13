<?php
declare(strict_types=1);

/** JSON API. handle() je bez HTTP sloja, pa se isti kod koristi u testovima. */
final class Api
{
    private ?array $u = null;
    public ?string $setToken = null;
    public bool $clearToken = false;

    public function __construct(private ?string $token = null, private string $ip = '') {}

    public function handle(string $method, string $path, array $q, array $in): array
    {
        try {
            $this->u = Auth::fromToken($this->token);
            Audit::$actor = $this->u ? Auth::actorFor($this->u) : null;
            Auth::$user = $this->u;
            $data = $this->route($method, trim($path, '/'), $q, $in);
            return [200, ['ok' => true, 'data' => $data]];
        } catch (AppError $e) {
            return [$e->status, ['ok' => false, 'error' => $e->getMessage(), 'data' => $e->data]];
        } catch (Throwable $e) {
            error_log('[arena-rs] ' . $e);
            return [500, ['ok' => false, 'error' => Config::get('env') === 'prod' ? 'Greška na serveru. Pokušajte ponovo.' : $e->getMessage()]];
        }
    }

    private function me(): array { if (!$this->u) throw new AppError('Potrebna je prijava.', 401); return $this->u; }
    private function staff(string $perm): array { $u = $this->me(); Perms::require($u, $perm); return $u; }
    private function owner(): array { $u = $this->me(); if ($u['role'] !== 'owner') throw new AppError('Samo vlasnik.', 403); return $u; }

    private function route(string $m, string $p, array $q, array $in): mixed
    {
        $seg = $p === '' ? [] : explode('/', $p);
        $id = isset($seg[1]) && ctype_digit($seg[1]) ? (int) $seg[1] : null;
        $act = $seg[2] ?? null;
        $key = $m . ' ' . ($seg[0] ?? '') . ($id !== null ? '/:id' : (isset($seg[1]) ? '/' . $seg[1] : '')) . ($act ? '/' . $act : '');

        switch ($key) {
            // ---------- javno ----------
            case 'GET calendar/day': return Calendar::day((string) (($q['date'] ?? '') ?: Clock::today()), $this->mode(), $this->u);
            case 'GET calendar/week': return Calendar::week((string) (($q['date'] ?? '') ?: Clock::today()), $this->mode(), $this->u);
            case 'GET events/:id': return Events::get($id, Perms::has($this->u, 'events.manage'));
            case 'GET clubs/public': return Clubs::all(false);
            case 'GET cron': $r = Jobs::run('web'); return isset($r['skipped']) ? ['skipped' => $r['skipped']] : ['ok' => true];

            // ---------- nalog ----------
            case 'POST auth/register': return Auth::registerStart($in) + ['dev_code' => $this->devCode(), 'code_status' => Auth::$lastCodeStatus];
            case 'POST auth/confirm': $r = Auth::registerConfirm($in); $this->setToken = $r['token']; return ['user' => Auth::publicUser(Auth::userById($r['user_id']))];
            case 'POST auth/login': $r = Auth::login((string) ($in['login'] ?? ''), (string) ($in['password'] ?? ''), $this->ip); $this->setToken = $r['token']; return ['user' => Auth::publicUser(Auth::userById($r['user_id']))];
            case 'POST auth/logout': Auth::logout($this->token); $this->clearToken = true; return null;
            case 'POST auth/reset': return Auth::resetStart($in) + ['dev_code' => $this->devCode(), 'code_status' => Auth::$lastCodeStatus === 'failed' ? 'failed' : null];
            case 'POST auth/reset-confirm': $r = Auth::resetConfirm($in); $this->setToken = $r['token']; return ['user' => Auth::publicUser(Auth::userById($r['user_id']))];
            case 'GET me': return ['registration' => Auth::registrationOpen(), 'env' => Config::get('env') === 'prod' ? 'prod' : 'proba'] + ($this->u ? ['user' => Auth::publicUser($this->u), 'perms' => Perms::forUser($this->u), 'staff' => Perms::isStaff($this->u), 'club' => $this->u['club_id'] ? Db::one('SELECT id, name FROM clubs WHERE id = ?', [$this->u['club_id']]) : null] : ['user' => null]);
            case 'GET me/overview': return $this->overview((int) $this->me()['id'], false);
            case 'POST me/monthly-request': Coverage::request((int) $this->me()['id'], (string) ($in['kind'] ?? 'new')); return null;
            case 'POST me/series-request': return Series::request($this->me(), $in);
            case 'POST me/profile':
                $u = $this->me(); $email = str_req($in, 'email', 190);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppError('Neispravan email.');
                Db::update('users', ['email' => $email, 'updated_utc' => Clock::nowUtc()], 'id = ?', [$u['id']]); return null;

            // ---------- rezervacije ----------
            case 'POST reservations/quote': return $this->quote($in);
            case 'POST reservations': return Reservations::detail($this->me(), (int) Reservations::create($this->me(), $in)['id']);
            case 'GET reservations/:id': return Reservations::detail($this->me(), $id);
            case 'POST reservations/:id/change': Reservations::change($this->me(), $id, $in); return Reservations::detail($this->me(), $id);
            case 'POST reservations/:id/extend': Reservations::change($this->me(), $id, $in, true); return Reservations::detail($this->me(), $id);
            case 'POST reservations/:id/cancel': Reservations::cancel($this->me(), $id, ($in['kind'] ?? '') === 'arena' ? 'arena' : 'guest', str_opt($in, 'reason')); return Reservations::detail($this->me(), $id);
            case 'POST reservations/:id/arrived': Reservations::markArrived($this->me(), $id); return Reservations::detail($this->me(), $id);
            case 'POST reservations/:id/no-show': $r = Reservations::markNoShow($this->me(), $id, !empty($in['release'])); return Reservations::detail($this->me(), $id) + ['result' => $r['no_show_result']];
            case 'GET reservations/:id/settle-quote': $this->staff('cash.collect'); return Reservations::quoteSettle($id, isset($q['used']) && $q['used'] !== '' ? (int) $q['used'] : null);
            case 'POST reservations/:id/settle': $rc = Reservations::settle($this->me(), $id, $in); return ['receipt' => $rc, 'reservation' => Reservations::detail($this->me(), $id)];
            case 'GET reservations/:id/history': $this->staff('res.read'); return Db::all("SELECT at_utc, actor_label, action, data_json, reason FROM audit_log WHERE entity = 'reservation' AND entity_id = ? ORDER BY id", [(string) $id]);

            // ---------- korisnici ----------
            case 'GET users': return $this->searchUsers((string) ($q['q'] ?? ''));
            case 'GET users/:id': $this->staff('res.read'); return $this->userCard($id);
            case 'POST users': $a = $this->staff('res.write'); return Auth::publicUser(Auth::findOrCreateProfile($in, $a));
            case 'POST users/:id/update':
                $this->staff('users.manage');
                $set = ['first_name' => str_req($in, 'first_name', 80), 'last_name' => (string) ($in['last_name'] ?? ''), 'email' => str_opt($in, 'email', 190), 'updated_utc' => Clock::nowUtc()];
                if (!empty($in['phone'])) { $ph = Phone::require($in['phone']); $o = Auth::userByPhone($ph); if ($o && (int) $o['id'] !== $id) throw new AppError('Taj telefon već ima drugi profil.'); $set['phone'] = $ph; }
                Db::update('users', $set, 'id = ?', [$id]); Audit::log('user.updated', 'user', $id, $set); return $this->userCard($id);
            case 'POST users/:id/block':
                $this->staff('users.block'); $why = str_req($in, 'reason', 255);
                Db::update('users', ['blocked_utc' => Clock::nowUtc(), 'blocked_by' => $this->u['id'], 'blocked_reason' => $why], 'id = ? AND role = \'guest\'', [$id]);
                Audit::log('user.blocked', 'user', $id, [], $why); return $this->userCard($id);
            case 'POST users/:id/unblock':
                $this->staff('users.block'); Db::update('users', ['blocked_utc' => null, 'blocked_by' => null, 'blocked_reason' => null], 'id = ?', [$id]);
                Audit::log('user.unblocked', 'user', $id); return $this->userCard($id);
            case 'POST users/:id/club': Clubs::setMember($this->me(), $id, !empty($in['club_id']) ? (int) $in['club_id'] : null); return $this->userCard($id);
            case 'POST users/:id/bonus': $this->staff('bonus.manage'); Coverage::manualBonus($id, int_req($in, 'minutes'), str_req($in, 'reason', 255)); return $this->userCard($id);
            case 'GET users/:id/monthly-preview': $this->staff('monthly.manage'); return Coverage::activationPreview($id);
            case 'POST users/:id/monthly': $a = $this->staff('monthly.manage'); Coverage::activate($id, (string) ($in['kind'] ?? 'standard'), isset($in['weekly_minutes']) ? (int) $in['weekly_minutes'] : null, $a, !empty($in['confirm_early'])); return $this->userCard($id);

            // ---------- mjesečne, zahtjevi ----------
            case 'GET requests': $this->staff('res.read'); return $this->requests();
            case 'POST monthly-requests/:id/reject': $this->staff('monthly.manage'); Db::q("UPDATE monthly_requests SET status = 'rejected', handled_by = ?, handled_utc = ? WHERE id = ? AND status = 'pending'", [$this->u['id'], Clock::nowUtc(), $id]); Audit::log('monthly.request_rejected', 'monthly_request', $id); return null;
            case 'GET monthly': $this->staff('res.read'); return Db::all("SELECT p.*, TRIM(CONCAT(u.first_name,' ',u.last_name)) name, u.phone FROM monthly_passes p JOIN users u ON u.id = p.user_id WHERE p.end_date > ? ORDER BY p.end_date", [T::addDays(Clock::today(), -30)]);

            // ---------- klubovi, serije, obračuni ----------
            case 'GET clubs': $this->staff('res.read'); return Clubs::all(true);
            case 'POST clubs': return Clubs::save($this->me(), $in);
            case 'GET clubs/:id/members': $this->staff('clubs.manage'); return Db::all("SELECT id, first_name, last_name, phone FROM users WHERE club_id = ? ORDER BY last_name, first_name", [$id]);
            case 'GET series': return Series::list($this->me(), $q['status'] ?? null);
            case 'POST series': return Series::createByStaff($this->me(), $in);
            case 'GET series/:id/check': return Series::check($this->me(), $id);
            case 'GET series/:id/detail': return Series::detail($this->me(), $id);
            case 'POST series/:id/approve': return Series::approve($this->me(), $id, array_map('strval', (array) ($in['skip_dates'] ?? [])));
            case 'POST series/:id/reject': Series::reject($this->me(), $id, str_opt($in, 'reason')); return null;
            case 'POST series/:id/end': Series::end($this->me(), $id, (string) ($in['from'] ?? Clock::today())); return Series::get($id);
            case 'POST series/:id/skip': Series::skipDate($this->me(), $id, (string) ($in['date'] ?? ''), str_opt($in, 'reason')); return Series::get($id);
            case 'GET statements': $this->staff('clubs.billing'); return Statements::list(isset($q['club']) ? (int) $q['club'] : null);
            case 'GET statements/unbilled': $this->staff('clubs.billing'); return Statements::unbilled((int) ($q['club'] ?? 0));
            case 'POST statements': return Statements::create($this->me(), (int) ($in['club_id'] ?? 0), !empty($in['reservation_id']) ? (int) $in['reservation_id'] : null);
            case 'GET statements/:id': $this->staff('clubs.billing'); return Statements::get($id);
            case 'GET statements/:id/pdf': $this->staff('clubs.billing'); $s = Statements::get($id); return ['__file' => $s['pdf_path'], 'name' => $s['number'] . '.pdf'];
            case 'POST statements/:id/send-email': return Statements::sendEmail($this->me(), $id);

            // ---------- događaji ----------
            case 'GET events': $this->staff('res.read'); return Events::list((string) ($q['from'] ?? Clock::today()), (string) ($q['to'] ?? T::addDays(Clock::today(), 90)));
            case 'POST events/preview': return Events::preview($this->me(), $in);
            case 'POST events': return Events::create($this->me(), $in);
            case 'POST events/:id/cancel': Events::cancel($this->me(), $id, (string) ($in['reason'] ?? '')); return null;
            case 'POST events/:id/entries': Events::addEntry($this->me(), $id, $in); return Events::get($id, true);
            case 'POST entries/:id/delete': Events::removeEntry($this->me(), $id); return null;

            // ---------- novac, dan ----------
            case 'GET day': $this->staff('day.view'); $d = (string) ($q['date'] ?? Clock::today()); return ['open_later' => DayClose::openLater($d), 'payment_day' => DayClose::paymentDay(), 'date' => $d, 'closed' => Db::one('SELECT d.*, TRIM(CONCAT(u.first_name,\' \',u.last_name)) by_name FROM day_closes d LEFT JOIN users u ON u.id = d.closed_by WHERE day = ?', [$d]), 'totals' => DayClose::totals($d), 'blockers' => DayClose::blockers($d)];
            case 'POST day/close': return DayClose::close($this->me(), (string) ($in['date'] ?? Clock::today()));
            case 'POST payments/:id/correct': return Cash::correct($this->me(), $id, isset($in['new_amount']) && $in['new_amount'] !== '' ? Money::parse((string) $in['new_amount']) : null, (string) ($in['reason'] ?? ''));
            case 'POST debts/:id/forgive': Cash::forgiveDebt($this->me(), $id, (string) ($in['reason'] ?? '')); return null;
            case 'POST debts/:id/pay': return ['payment_id' => Cash::payDebt($this->me(), $id)];
            case 'GET dashboard': return $this->dashboard();

            // ---------- vlasnik ----------
            case 'GET stats': $this->staff('stats.view'); return Stats::compare((string) ($q['from'] ?? T::addDays(Clock::today(), -29)), (string) ($q['to'] ?? Clock::today()));
            case 'GET prices':
                $this->staff('res.read'); $pv = Pricing::current();
                return ['current' => $pv, 'history' => Db::all('SELECT * FROM price_versions ORDER BY id DESC'),
                    'birthday_noshow_debt' => Settings::get('birthday_noshow_debt') === '1',
                    'birthday_noshow_example_cents' => Pricing::laneCash(120, $pv) * 3];
            case 'POST settings/birthday-debt':
                $this->staff('prices.manage'); Settings::set('birthday_noshow_debt', !empty($in['enabled']) ? '1' : '0');
                Audit::log('settings.birthday_noshow_debt', 'settings', null, ['enabled' => !empty($in['enabled'])]);
                return null;
            case 'POST prices':
                $a = $this->staff('prices.manage');
                $cur = Pricing::current();
                $row = ['monthly_remainder_30_cents' => isset($in['monthly_remainder_30']) && $in['monthly_remainder_30'] !== '' ? Money::parse((string) $in['monthly_remainder_30']) : (int) $cur['monthly_remainder_30_cents'],
                    'monthly_remainder_min_cents' => isset($in['monthly_remainder_min']) && $in['monthly_remainder_min'] !== '' ? Money::parse((string) $in['monthly_remainder_min']) : (int) $cur['monthly_remainder_min_cents'],
                    'valid_from_utc' => Clock::nowUtc(), 'recreation_30_cents' => Money::parse((string) $in['recreation_30']), 'recreation_min_cents' => Money::parse((string) $in['recreation_min']), 'shoes_cents' => Money::parse((string) $in['shoes']),
                    'training_lane_hour_cents' => Money::parse((string) $in['training_lane_hour']), 'monthly_cents' => Money::parse((string) $in['monthly']), 'note' => str_opt($in, 'note'), 'created_by' => $a['id']];
                $pid = Db::insert('price_versions', $row); Audit::log('prices.new_version', 'price_version', $pid, $row); return Pricing::current();
            case 'GET staff-accounts': $this->owner(); return array_map(fn($u) => Auth::publicUser($u) + ['perms' => Perms::forUser($u)], Db::all("SELECT * FROM users WHERE role IN ('owner','manager','staff') ORDER BY FIELD(role,'owner','manager','staff'), id"));
            case 'GET perms': $this->owner(); return ['all' => Perms::ALL, 'defaults' => Perms::DEFAULTS];
            case 'POST staff-accounts': return $this->saveStaff($in);
            case 'POST perms/:id': $this->owner(); $t = Auth::userById($id); if (!$t || !in_array($t['role'], ['manager', 'staff'], true)) throw new AppError('Ovlaštenja se dodjeljuju samo menadžeru i osoblju.'); Perms::setFor($id, (array) ($in['perms'] ?? [])); Audit::log('perms.changed', 'user', $id, ['perms' => Perms::forUser($t)]); return Perms::forUser($t);
            case 'GET recipients': $this->staff('settings.manage'); return ['list' => Db::all('SELECT * FROM notification_recipients ORDER BY id'), 'types' => Notify::TYPES, 'channels' => array_keys((array) Config::get('channels', []))];
            case 'POST recipients':
                $this->staff('settings.manage');
                $row = ['name' => str_req($in, 'name', 120), 'phone' => Phone::normalize($in['phone'] ?? null), 'email' => str_opt($in, 'email', 190), 'channel' => in_array($in['channel'] ?? 'sms', ['sms', 'viber', 'whatsapp', 'email'], true) ? $in['channel'] : 'sms',
                    'types' => json_encode(array_values(array_intersect(array_keys(Notify::TYPES), (array) ($in['types'] ?? [])))), 'active' => empty($in['inactive']) ? 1 : 0];
                if (!empty($in['id'])) Db::update('notification_recipients', $row, 'id = ?', [(int) $in['id']]); else Db::insert('notification_recipients', $row);
                Audit::log('recipients.saved', 'recipient', $in['id'] ?? null, $row); return null;
            case 'POST recipients/:id/delete': $this->staff('settings.manage'); Db::update('notification_recipients', ['active' => 0], 'id = ?', [$id]); return null;
            case 'GET outbox': $this->staff('settings.manage'); return Db::all('SELECT id, channel, to_addr, type, subject, body, attachment_path IS NOT NULL has_attachment, status, attempts, last_error, sent_utc, created_utc FROM outbox ORDER BY id DESC LIMIT 200');
            case 'POST outbox/:id/requeue': $this->staff('settings.manage'); Notify::requeue($id); return null;
            case 'POST outbox/run': $this->staff('settings.manage'); return Notify::work();
            case 'GET audit': $this->staff('audit.view'); return Db::all('SELECT * FROM audit_log' . (isset($q['entity']) ? ' WHERE entity = ? AND entity_id = ?' : '') . ' ORDER BY id DESC LIMIT 300', isset($q['entity']) ? [$q['entity'], (string) ($q['id'] ?? '')] : []);
            case 'POST backup/run':
                $this->owner(); require_once dirname(__DIR__) . '/tools/backup-lib.php';
                $f = Backup::dump(Config::dataDir() . '/backup'); Audit::log('backup.manual', 'backup', basename($f));
                return ['file' => basename($f), 'bytes' => filesize($f)];
            case 'GET backup/list':
                $this->owner(); $fs = array_merge(glob(Config::dataDir() . '/backup/arena-rs-*.sql.gz') ?: [], glob(Config::dataDir() . '/backup/arena-rs-*.sqlite.gz') ?: []); rsort($fs);
                return array_map(fn($f) => ['file' => basename($f), 'bytes' => filesize($f)], $fs);
            case 'GET backup/download':
                $this->owner(); $name = basename((string) ($q['file'] ?? ''));
                $f = Config::dataDir() . '/backup/' . $name;
                if (!preg_match('/^arena-rs-[0-9-]+\.(sql|sqlite)\.gz$/', $name) || !is_file($f)) throw new AppError('Backup ne postoji.', 404);
                Audit::log('backup.downloaded', 'backup', $name);
                return ['__file' => $f, 'name' => $name];
            case 'GET dev/codes':
                if (Config::get('env') === 'prod') throw new AppError('Nije dostupno.', 404);
                return Db::all("SELECT id, to_addr, body, status, created_utc FROM outbox WHERE type = 'verify_code' ORDER BY id DESC LIMIT 20");
        }
        throw new AppError('Nepoznata adresa.', 404);
    }

    private function devCode(): ?string { return Config::get('env') === 'prod' ? null : (Config::get('show_dev_codes', true) ? Auth::$lastCode : null); }

    private function mode(): string
    {
        if (Perms::has($this->u, 'res.read')) return 'staff';
        return $this->u ? 'user' : 'public';
    }

    private function quote(array $in): array
    {
        $u = $this->me();
        $start = T::parseLocal((string) ($in['start'] ?? ''));
        $min = int_req($in, 'minutes');
        $isStaff = Perms::isStaff($u) && !empty($in['as_staff']);
        $user = $isStaff ? (!empty($in['user_id']) ? Auth::userById((int) $in['user_id']) : (!empty($in['guest']['phone']) ? Auth::userByPhone((string) Phone::normalize($in['guest']['phone'])) : null)) : $u;
        $cat = $isStaff ? (string) ($in['category'] ?? 'recreation') : ($u['club_id'] && empty($in['personal']) ? 'training' : 'recreation');
        $end = Reservations::validateTime($start, $min, $cat, $isStaff);
        $want = isset($in['lanes']) && is_array($in['lanes']) && $in['lanes'] ? $in['lanes'] : (int) ($in['lane_count'] ?? 1);
        $lanes = Availability::pick($start, $end, $want);
        $pv = Pricing::current();
        $out = ['start' => $start, 'end' => $end, 'lanes' => $lanes, 'category' => $cat, 'free_lanes' => Availability::freeLanes($start, $end)];
        if ($cat === 'training') { $out['training'] = true; if ($isStaff) $out['club_amount_cents'] = Pricing::training(count($lanes), $min, $pv); return $out; }
        if ($cat === 'birthday') { $out['cash_total'] = Pricing::laneCash($min, $pv) * count($lanes); return $out; }
        $c = Coverage::compute($user ? (int) $user['id'] : null, 'recreation', $start, $end, count($lanes), $pv);
        unset($c['pass']);
        return $out + $c + ['debt_cents' => $user ? (int) Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM debts WHERE user_id = ? AND status = 'open'", [$user['id']]) : 0];
    }

    public function overview(int $userId, bool $staffView): array
    {
        $u = Auth::userById($userId);
        $now = Clock::nowLocal();
        $res = Db::all("SELECT r.id, r.category, r.start_at, r.end_at, r.status, r.cancel_kind, r.attendance, r.processed_utc IS NOT NULL processed, GROUP_CONCAT(l.lane_id ORDER BY l.lane_id) lanes
            FROM reservations r LEFT JOIN reservation_lanes l ON l.reservation_id = r.id WHERE r.user_id = ? GROUP BY r.id ORDER BY r.start_at DESC LIMIT 300", [$userId]);
        foreach ($res as &$r) { $r['category_label'] = Cat::label($r['category']); $r['color'] = Cat::ALL[$r['category']][1] ?? '#666'; }
        unset($r);
        $club = null;
        if ($u['club_id']) {
            $club = Db::one('SELECT id, name FROM clubs WHERE id = ?', [$u['club_id']]);
            $club['trainings'] = Db::all("SELECT r.id, r.start_at, r.end_at, r.status, GROUP_CONCAT(l.lane_id ORDER BY l.lane_id) lanes FROM reservations r LEFT JOIN reservation_lanes l ON l.reservation_id = r.id
                WHERE r.club_id = ? AND r.category = 'training' AND r.status = 'active' AND r.end_at > ? GROUP BY r.id ORDER BY r.start_at LIMIT 50", [$u['club_id'], $now]);
            $club['series'] = Db::all("SELECT id, weekday, start_time, minutes, lane_count, status, valid_from, valid_to FROM recurring_series WHERE club_id = ? AND status IN ('pending','approved') ORDER BY weekday, start_time", [$u['club_id']]);
        }
        return [
            'user' => Auth::publicUser($u) + ['blocked_reason' => $u['blocked_reason']],
            'upcoming' => array_values(array_reverse(array_filter($res, fn($r) => $r['end_at'] > $now && $r['status'] === 'active'))),
            'past' => array_values(array_filter($res, fn($r) => $r['end_at'] <= $now || $r['status'] !== 'active')),
            'monthly' => Coverage::summary($userId),
            'debts' => Db::all("SELECT id, amount_cents, reason, status, created_utc FROM debts WHERE user_id = ? ORDER BY id DESC", [$userId]),
            'debt_open_cents' => (int) Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM debts WHERE user_id = ? AND status = 'open'", [$userId]),
            'warnings' => Db::all('SELECT kind, text, created_utc FROM warnings WHERE user_id = ? ORDER BY id DESC', [$userId]),
            'club' => $club,
        ];
    }

    private function userCard(int $id): array
    {
        $o = $this->overview($id, true);
        $o['payments'] = Db::all('SELECT id, business_day, kind, amount_cents, reservation_id, reverses_payment_id, reason FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$id]);
        $o['passes'] = Db::all('SELECT * FROM monthly_passes WHERE user_id = ? ORDER BY id DESC', [$id]);
        return $o;
    }

    private function searchUsers(string $q): array
    {
        $this->staff('res.write');
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $digits = preg_replace('/\D+/', '', $q);
        $rows = [];
        if (strlen($digits) >= 3) {
            $norm = Phone::normalize($q);
            $tail = substr($digits, str_starts_with($digits, '0') ? 1 : 0);
            $rows = Db::all("SELECT * FROM users WHERE role = 'guest' AND (phone = ? OR phone LIKE ?) LIMIT 15", [$norm, '%' . $tail . '%']);
        }
        if (!$rows) {
            $like = '%' . $q . '%';
            $rows = Db::all("SELECT * FROM users WHERE role = 'guest' AND (CONCAT(first_name, ' ', last_name) LIKE ? OR CONCAT(last_name, ' ', first_name) LIKE ?) ORDER BY last_name LIMIT 15", [$like, $like]);
        }
        return array_map(fn($u) => Auth::publicUser($u) + ['debt_cents' => (int) Db::val("SELECT COALESCE(SUM(amount_cents),0) FROM debts WHERE user_id = ? AND status = 'open'", [$u['id']])], $rows);
    }

    private function requests(): array
    {
        return [
            'monthly' => array_map(fn($r) => ['phone' => Phone::pretty($r['phone'])] + $r, Db::all("SELECT m.*, TRIM(CONCAT(u.first_name,' ',u.last_name)) name, u.phone FROM monthly_requests m JOIN users u ON u.id = m.user_id WHERE m.status = 'pending' ORDER BY m.id")),
            'series' => Perms::has($this->u, 'clubs.manage') ? Series::list($this->u, 'pending') : [],
        ];
    }

    private function dashboard(): array
    {
        $u = $this->staff('res.read');
        $today = Clock::today();
        Reservations::autoProcessClub();
        $unproc = Db::all("SELECT r.id, r.start_at, r.end_at, r.category, TRIM(CONCAT(u.first_name,' ',u.last_name)) who FROM reservations r LEFT JOIN users u ON u.id = r.user_id
            WHERE r.status = 'active' AND r.processed_utc IS NULL AND r.category IN ('recreation','monthly','birthday') AND r.end_at <= ? ORDER BY r.start_at", [Clock::nowLocal()]);
        $req = $this->requests();
        return [
            'today' => $today,
            'cash_today' => Perms::has($u, 'day.view') ? DayClose::totals($today)['total'] : null,
            'unprocessed' => $unproc,
            'pending_requests' => count($req['monthly']) + count($req['series']),
            'day_closed' => DayClose::isClosed($today),
            'unsent_messages' => Perms::has($u, 'settings.manage') ? (int) Db::val("SELECT COUNT(*) FROM outbox WHERE status IN ('pending','not_configured','failed')") : null,
            'cron' => Perms::has($u, 'settings.manage') ? ['last_utc' => Settings::get('last_cron_utc'), 'via' => Settings::get('last_cron_via'), 'error' => Settings::get('last_cron_error')] : null,
        ];
    }

    private function saveStaff(array $in): array
    {
        $o = $this->owner();
        $role = in_array($in['role'] ?? '', ['manager', 'staff'], true) ? $in['role'] : throw new AppError('Uloga: menadžer ili osoblje.');
        $username = mb_strtolower(str_req($in, 'username', 40));
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) throw new AppError('Korisničko ime: mala slova, brojevi, tačka, crtica.');
        $pass = (string) ($in['password'] ?? '');
        if (!empty($in['id'])) {
            $id = (int) $in['id'];
            $set = ['first_name' => str_req($in, 'first_name', 80), 'last_name' => (string) ($in['last_name'] ?? ''), 'username' => $username, 'role' => $role, 'updated_utc' => Clock::nowUtc()];
            if ($pass !== '') { Auth::validatePassword($pass); $set['password_hash'] = password_hash($pass, PASSWORD_DEFAULT); Db::q('DELETE FROM sessions WHERE user_id = ?', [$id]); }
            Db::update('users', $set, "id = ? AND role IN ('manager','staff')", [$id]);
            Audit::log('staff.updated', 'user', $id, ['username' => $username, 'role' => $role]);
        } else {
            Auth::validatePassword($pass);
            if (Db::val('SELECT id FROM users WHERE username = ?', [$username])) throw new AppError('Korisničko ime je zauzeto.');
            $id = Db::insert('users', ['first_name' => str_req($in, 'first_name', 80), 'last_name' => (string) ($in['last_name'] ?? ''), 'username' => $username, 'phone' => Phone::normalize($in['phone'] ?? null),
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'role' => $role, 'created_by' => $o['id'], 'created_utc' => Clock::nowUtc()]);
            Perms::setFor($id, Perms::DEFAULTS[$role]);
            Audit::log('staff.created', 'user', $id, ['username' => $username, 'role' => $role]);
        }
        return Auth::publicUser(Auth::userById($id)) + ['perms' => Perms::forUser(Auth::userById($id))];
    }
}
