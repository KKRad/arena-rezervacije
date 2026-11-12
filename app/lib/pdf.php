<?php
declare(strict_types=1);

/**
 * Mali PDF generator bez vanjskih biblioteka. Helvetica (standardni font, WinAnsi).
 * š ž Š Ž postoje u WinAnsi. č ć Č Ć đ se pišu kao osnovno slovo + kvačica/crtica/potez nacrtani linijama
 * (radi u svakom pregledaču, i kad zamjenski font nema te znakove); Đ = Ð (isti izgled).
 * /ActualText čuva pravi tekst za pretragu i kopiranje.
 */
final class Pdf
{
    private array $pages = [];
    private string $cur = '';
    private const MAP = ['č' => 'c', 'ć' => 'c', 'đ' => 'd', 'Č' => 'C', 'Ć' => 'C', 'Đ' => "\xD0", 'š' => "\x9A", 'Š' => "\x8A", 'ž' => "\x9E", 'Ž' => "\x8E", '–' => "\x96", '—' => "\x97", '×' => "\xD7", '„' => "\x84", '“' => "\x93", '”' => "\x94", '−' => '-'];
    private const W = [32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191, 40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278, 48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556, 56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556, 64 => 1015, 65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778, 80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556, 96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556, 104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556, 112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584, 150 => 556, 151 => 1000, 208 => 722, 154 => 500, 138 => 667, 158 => 500, 142 => 611, 215 => 584, 132 => 333, 147 => 333, 148 => 333, 233 => 556, 201 => 667, 183 => 278, 176 => 400];
    private const WB = [32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722, 39 => 238, 40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278, 48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556, 56 => 556, 57 => 556, 58 => 333, 59 => 333, 60 => 584, 61 => 584, 62 => 584, 63 => 611, 64 => 975, 65 => 722, 66 => 722, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722, 73 => 278, 74 => 556, 75 => 722, 76 => 611, 77 => 833, 78 => 722, 79 => 778, 80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667, 89 => 667, 90 => 611, 91 => 333, 92 => 278, 93 => 333, 94 => 584, 95 => 556, 96 => 333, 97 => 556, 98 => 611, 99 => 556, 100 => 611, 101 => 556, 102 => 333, 103 => 611, 104 => 611, 105 => 278, 106 => 278, 107 => 556, 108 => 278, 109 => 889, 110 => 611, 111 => 611, 112 => 611, 113 => 611, 114 => 389, 115 => 556, 116 => 333, 117 => 611, 118 => 556, 119 => 778, 120 => 556, 121 => 556, 122 => 500, 123 => 389, 124 => 280, 125 => 389, 126 => 584, 150 => 556, 151 => 1000, 208 => 722, 154 => 556, 138 => 667, 158 => 500, 142 => 611, 215 => 584, 132 => 500, 147 => 500, 148 => 500, 233 => 556, 201 => 667, 183 => 278, 176 => 400];

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
        $w = 0; $b = self::bytes($s); $t = $bold ? self::WB : self::W;
        for ($i = 0, $n = strlen($b); $i < $n; $i++) $w += $t[ord($b[$i])] ?? 556;
        return $w * $size / 1000;
    }
    public function page(): void { if ($this->cur !== '') $this->pages[] = $this->cur; $this->cur = ' '; }
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, string $align = 'L'): void
    {
        if ($align === 'R') $x -= self::width($s, $size, $bold);
        $u16 = strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8')));
        $this->cur .= sprintf("/Span << /ActualText <FEFF%s> >> BDC BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET EMC\n", $u16, $bold ? 'F2' : 'F1', $size, $x, $y, self::enc($s));
        // dijakritici koji nisu u WinAnsi: crtaju se iznad/preko osnovnog slova
        $prefix = '';
        foreach (mb_str_split($s) as $ch) {
            if (in_array($ch, ['č', 'ć', 'Č', 'Ć', 'đ'], true)) {
                $cx = $x + self::width($prefix, $size, $bold);
                $w = self::width($ch, $size, $bold);
                $lw = $size * ($bold ? 0.09 : 0.065);
                $upper = $ch === 'Č' || $ch === 'Ć';
                $mid = $cx + $w / 2;
                $base = $y + $size * ($upper ? 0.80 : 0.60);
                $h = $size * 0.13;
                if ($ch === 'č' || $ch === 'Č') $this->cur .= sprintf("q %.2F w 1 J 1 j %.2F %.2F m %.2F %.2F l %.2F %.2F l S Q\n", $lw, $mid - $h, $base + $h, $mid, $base, $mid + $h, $base + $h);
                elseif ($ch === 'ć' || $ch === 'Ć') $this->cur .= sprintf("q %.2F w 1 J %.2F %.2F m %.2F %.2F l S Q\n", $lw, $mid - $h * 0.4, $base, $mid + $h * 0.9, $base + $h * 1.2);
                else { $sy = $y + $size * 0.60; $this->cur .= sprintf("q %.2F w %.2F %.2F m %.2F %.2F l S Q\n", $lw * 0.9, $cx + $w * 0.42, $sy, $cx + $w * 0.98, $sy); }
            }
            $prefix .= $ch;
        }
    }
    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.5): void { $this->cur .= sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $w, $x1, $y1, $x2, $y2); }
    public function rect(float $x, float $y, float $w, float $h, string $gray = '0.93'): void { $this->cur .= sprintf("q %s g %.2F %.2F %.2F %.2F re f Q\n", $gray, $x, $y, $w, $h); }

    public function output(): string
    {
        if ($this->cur !== '') { $this->pages[] = $this->cur; $this->cur = ''; }
        $objs = [];

        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objs[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
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
        $dir = Config::dataDir() . '/pdf';
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        $path = $dir . '/' . $st['number'] . '.pdf';
        file_put_contents($path, $p->output());
        return $path;
    }
}
