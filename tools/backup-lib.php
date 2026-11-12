<?php
/** Logički SQL dump bez mysqldump-a (radi i na dijeljenom hostingu). */
final class Backup
{
    public static function dump(string $dir): string
    {
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        $pdo = Db::pdo();
        if (Db::isSqlite()) {
            // SQLite: konzistentna kopija cijele baze (VACUUM INTO), pa gzip
            $tmp = $dir . '/tmp-' . bin2hex(random_bytes(4)) . '.sqlite';
            $pdo->exec('VACUUM INTO ' . $pdo->quote($tmp));
            $file = $dir . '/arena-rs-' . Clock::now()->format('Ymd-His') . '.sqlite.gz';
            file_put_contents($file, gzencode((string) file_get_contents($tmp), 6));
            unlink($tmp);
            return $file;
        }
        $file = $dir . '/arena-rs-' . Clock::now()->format('Ymd-His') . '.sql.gz';
        $gz = gzopen($file, 'wb6');
        gzwrite($gz, "-- Arena rezervacije backup " . Clock::nowUtc() . " UTC\nSET FOREIGN_KEY_CHECKS=0;\n");
        // konzistentan snimak svih tabela bez zaključavanja upisa
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n$create;\n");
            $st = $pdo->query("SELECT * FROM `$t`");
            $n = 0;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row);
                gzwrite($gz, "INSERT INTO `$t` VALUES (" . implode(',', $vals) . ");\n");
                $n++;
            }
            gzwrite($gz, "-- rows $t: $n\n");
        }
        $pdo->exec('COMMIT');
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n-- end\n");
        gzclose($gz);
        return $file;
    }
    public static function prune(string $dir, int $keep): void
    {
        $files = array_merge(glob($dir . '/arena-rs-*.sql.gz'), glob($dir . '/arena-rs-*.sqlite.gz')); sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - $keep)) as $f) unlink($f);
    }
    /** Vraća dump u zadanu (probnu) bazu i upoređuje broj redova. */
    /** SQLite backup: raspakuje se u privremenu datoteku i poredi broj redova s živom bazom. */
    public static function restoreCheckSqlite(string $file): array
    {
        $tmp = sys_get_temp_dir() . '/arena-rs-restore-' . bin2hex(random_bytes(4)) . '.sqlite';
        file_put_contents($tmp, gzdecode((string) file_get_contents($file)));
        $r = new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $out = [];
        foreach ($r->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $got = (int) $r->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
            $live = (int) Db::val("SELECT COUNT(*) FROM \"$t\"");
            $out[$t] = ['expected' => $live, 'restored' => $got, 'ok' => $got <= $live];
        }
        $r = null; unlink($tmp);
        return $out;
    }
    public static function restoreCheck(string $file, PDO $target): array
    {
        $sql = gzdecode((string) file_get_contents($file));
        if (!str_contains($sql, '-- end')) throw new RuntimeException('Backup nije kompletan.');
        $target->exec($sql);
        preg_match_all('/^-- rows (\w+): (\d+)$/m', $sql, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [$_, $t, $n]) { $got = (int) $target->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); $out[$t] = ['expected' => (int) $n, 'restored' => $got, 'ok' => $got === (int) $n]; }
        return $out;
    }
}
