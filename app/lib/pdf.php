<?php
declare(strict_types=1);

/**
 * Mali PDF generator bez vanjskih biblioteka. Helvetica (standardni font) sa WinAnsi kodiranjem
 * i /Differences za č ć đ Č Ć Đ (š ž Š Ž već postoje u WinAnsi).
 */
final class Pdf
{
    private array $pages = [];
    private string $cur = '';
    private const MAP = ['č' => "\x81", 'ć' => "\x8D", 'đ' => "\x8F", 'Č' => "\x90", 'Ć' => "\x9D", 'Đ' => "\xA4", 'š' => "\x9A", 'Š' => "\x8A", 'ž' => "\x9E", 'Ž' => "\x8E", '–' => "\x96", '—' => "\x97", '×' => "\xD7", '„' => "\x84", '“' => "\x93", '”' => "\x94", '−' => '-'];
    private const W = [32 => 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];

    /** UTF-8 → jednobajtni niz u kodiranju fonta. */
    public static function bytes(string $s): string
    {
        $out = '';
        foreach (mb_str_split($s) as $ch) {
            if (isset(self::MAP[$ch])) $out .= self::MAP[$ch];
            elseif (strlen($ch) === 1) $out .= $ch;
            else { $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $ch); $out .= ($c === false || $c === '') ? '?' : $c[0]; }
        }
        return $out;
    }
    public static function enc(string $s): string { return strtr(self::bytes($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']); }
    public static function width(string $s, float $size, bool $bold = false): float
    {
        $w = 0; $b = self::bytes($s);
        for ($i = 0, $n = strlen($b); $i < $n; $i++) $w += self::W[ord($b[$i])] ?? 556;
        return $w * $size / 1000 * ($bold ? 1.06 : 1);
    }
    public function page(): void { if ($this->cur !== '') $this->pages[] = $this->cur; $this->cur = ' '; }
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, string $align = 'L'): void
    {
        if ($align === 'R') $x -= self::width($s, $size, $bold);
        $this->cur .= sprintf("BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $y, self::enc($s));
    }
    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.5): void { $this->cur .= sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $w, $x1, $y1, $x2, $y2); }
    public function rect(float $x, float $y, float $w, float $h, string $gray = '0.93'): void { $this->cur .= sprintf("q %s g %.2F %.2F %.2F %.2F re f Q\n", $gray, $x, $y, $w, $h); }

    public function output(): string
    {
        if ($this->cur !== '') { $this->pages[] = $this->cur; $this->cur = ''; }
        $objs = [];
        $diff = '/Differences [129 /ccaron 141 /cacute 143 /dcroat 144 /Ccaron 157 /Cacute 164 /Dcroat]';
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding << /Type /Encoding /BaseEncoding /WinAnsiEncoding $diff >> >>";
        $objs[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding << /Type /Encoding /BaseEncoding /WinAnsiEncoding $diff >> >>";
        $kids = [];
        $n = 5;
        foreach ($this->pages as $content) {
            $objs[$n] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents " . ($n + 1) . " 0 R >>";
            $objs[$n + 1] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
            $kids[] = "$n 0 R"; $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = [];
        foreach ($objs as $i => $o) { $off[$i] = strlen($out); $out .= "$i 0 obj\n$o\nendobj\n"; }
        $x = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($objs as $i => $_) $out .= sprintf("%010d 00000 n \n", $off[$i]);
        $out .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF";
        return $out;
    }

    /** PDF obračuna kluba: datumi, treninzi/utakmice, staze, trajanja, cijene, ukupno. */
    public static function statement(array $st): string
    {
        $p = new self(); $p->page();
        $y = 790;
        $header = function () use ($p, $st, &$y) {
            $p->text(50, $y, 'Arena Bakarni', 16, true); $p->text(545, $y, 'Obračun ' . $st['number'], 12, true, 'R'); $y -= 18;
            $p->text(50, $y, 'Plazuljska bb, 76100 Brčko Distrikt, BiH', 9); $p->text(545, $y, 'Izrađeno: ' . T::human(substr($st['cutoff_at'], 0, 16) . ':00'), 9, false, 'R'); $y -= 26;
            $p->text(50, $y, 'Klub: ' . $st['club_name'], 12, true); $y -= 15;
            $p->text(50, $y, 'Obuhvaćeno: održane i do sada neobračunate stavke' . ($st['kind'] === 'single_match' ? ' (izdvojena utakmica)' : '') . ', period ' . T::humanDate($st['period_from']) . ' – ' . T::humanDate($st['period_to']), 9); $y -= 22;
            $p->rect(45, $y - 4, 505, 16);
            foreach ([[50, 'Datum'], [115, 'Stavka'], [365, 'Staze'], [405, 'Trajanje'], [545, 'Iznos']] as [$x, $t]) $p->text($x, $y, $t, 9, true, $x === 545 ? 'R' : 'L');
            $y -= 18;
        };
        $header();
        foreach ($st['items'] as $it) {
            if ($y < 90) { $p->page(); $y = 790; $header(); }
            $p->text(50, $y, T::humanDate($it['item_date']), 9);
            $desc = $it['description']; while (self::width($desc, 9) > 245 && mb_strlen($desc) > 5) $desc = mb_substr($desc, 0, -2);
            if ($desc !== $it['description']) $desc .= '…';
            $p->text(115, $y, $desc, 9);
            $p->text(365, $y, (string) $it['lane_count'], 9);
            $p->text(405, $y, Statements::hours((int) $it['minutes']) . ' (' . $it['unit_label'] . ')', 8);
            $p->text(545, $y, Money::fmt((int) $it['amount_cents']), 9, false, 'R');
            $y -= 6; $p->line(50, $y, 545, $y, 0.2); $y -= 12;
        }
        $y -= 6; $p->line(50, $y + 10, 545, $y + 10, 0.8);
        $p->text(400, $y - 4, 'Ukupno:', 11, true); $p->text(545, $y - 4, Money::fmt((int) $st['total_cents']), 11, true, 'R');
        $y -= 40;
        $p->text(50, $y, 'Napomena: ovo je evidencija korištenja staza (obračun). Ne predstavlja potvrdu o uplati.', 8);
        $dir = dirname(__DIR__, 2) . '/storage/pdf';
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        $path = $dir . '/' . $st['number'] . '.pdf';
        file_put_contents($path, $p->output());
        return $path;
    }
}
