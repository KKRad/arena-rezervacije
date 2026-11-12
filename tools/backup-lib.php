<?php
/** Logički SQL dump bez mysqldump-a (radi i na dijeljenom hostingu). */
final class Backup
{
    public static function dump(string $dir): string
    {
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        $pdo = Db::pdo();
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
        $files = glob($dir . '/arena-rs-*.sql.gz'); sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - $keep)) as $f) unlink($f);
    }
    /** Vraća dump u zadanu (probnu) bazu i upoređuje broj redova. */
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
