<?php
/* Fabrication du bon cadeau en PDF (A5 paysage), même rendu que la version en ligne. */
declare(strict_types=1);

if (!defined('FPDF_FONTPATH')) define('FPDF_FONTPATH', __DIR__ . '/../vendor/fpdf/font/');
require_once __DIR__ . '/../vendor/fpdf/fpdf.php';
require_once __DIR__ . '/../vendor/qrcode/qrcode.php';

final class VoucherDoc extends FPDF {
    function Ellipse(float $x, float $y, float $rx, float $ry, string $style = 'F'): void {
        $op = $style === 'F' ? 'f' : ($style === 'FD' || $style === 'DF' ? 'B' : 'S');
        $lx = 4 / 3 * (M_SQRT2 - 1) * $rx; $ly = 4 / 3 * (M_SQRT2 - 1) * $ry; $k = $this->k; $h = $this->h;
        $this->_out(sprintf('%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c', ($x + $rx) * $k, ($h - $y) * $k, ($x + $rx) * $k, ($h - ($y - $ly)) * $k, ($x + $lx) * $k, ($h - ($y - $ry)) * $k, $x * $k, ($h - ($y - $ry)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', ($x - $lx) * $k, ($h - ($y - $ry)) * $k, ($x - $rx) * $k, ($h - ($y - $ly)) * $k, ($x - $rx) * $k, ($h - $y) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', ($x - $rx) * $k, ($h - ($y + $ly)) * $k, ($x - $lx) * $k, ($h - ($y + $ry)) * $k, $x * $k, ($h - ($y + $ry)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c %s', ($x + $lx) * $k, ($h - ($y + $ry)) * $k, ($x + $rx) * $k, ($h - ($y + $ly)) * $k, ($x + $rx) * $k, ($h - $y) * $k, $op));
    }
    function Circle(float $x, float $y, float $r, string $style = 'F'): void { $this->Ellipse($x, $y, $r, $r, $style); }
}

final class VoucherPdf {
    const W = 595, H = 420;
    static function rgb(string $h): array { return [hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2))]; }
    static function t(string $s): string {   // UTF-8 → Windows-1252 (caractères non imprimables, comme les émojis, retirés)
        $out = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $c = @iconv('UTF-8', 'Windows-1252', $ch);
            if ($c === false) { $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $ch); if ($c === false || $c === '?') $c = ''; }
            $out .= $c;
        }
        return trim(preg_replace('/\s+/', ' ', $out));
    }
    static function fill(FPDF $p, string $hex): void { [$r, $g, $b] = self::rgb($hex); $p->SetFillColor($r, $g, $b); }
    static function color(FPDF $p, string $hex): void { [$r, $g, $b] = self::rgb($hex); $p->SetTextColor($r, $g, $b); }
    static function wrap(FPDF $p, string $text, float $maxW, int $maxLines): array {
        $lines = []; $cur = '';
        foreach (explode(' ', $text) as $w) {
            $t = $cur === '' ? $w : "$cur $w";
            if ($p->GetStringWidth($t) <= $maxW) $cur = $t; else { if ($cur !== '') $lines[] = $cur; $cur = $w; }
        }
        if ($cur !== '') $lines[] = $cur;
        if (count($lines) > $maxLines) { $lines = array_slice($lines, 0, $maxLines); $lines[$maxLines - 1] = preg_replace('/\s*\S*$/', '', $lines[$maxLines - 1]) . '...'; }
        return $lines;
    }

    static function make(array $v, string $verifyUrl): string {
        $W = self::W; $H = self::H;
        $p = new VoucherDoc('L', 'pt', [$H, $W]);
        $p->SetAutoPageBreak(false); $p->SetMargins(0, 0); $p->AddPage();
        $p->SetTitle('Bon cadeau Aqua Beaute ' . $v['code']); $p->SetAuthor('Aqua Beaute Mutzig');
        $fete = ($v['design'] ?? '') === 'fete';

        if ($fete) {
            self::fill($p, '#8E0657'); $p->Rect(0, 0, $W, $H, 'F');
            self::fill($p, '#B8006E'); $p->Circle($W, $H, 300);
            self::fill($p, '#CE2F87'); $p->Circle($W + 40, $H + 40, 190);
            self::fill($p, '#66105E'); $p->Circle(-60, -40, 200);
            $conf = [[40, 60, '#F7D774'], [130, 25, '#8FD3EE'], [250, 40, '#FFFFFF'], [320, 380, '#F7D774'], [190, 395, '#8FD3EE'], [70, 300, '#FFFFFF'], [420, 25, '#F7D774'], [560, 210, '#8FD3EE'], [470, 260, '#FFFFFF'], [30, 190, '#F7D774'], [300, 170, '#FFFFFF'], [520, 300, '#F7D774']];
            foreach ($conf as $i => [$x, $y, $c]) { self::fill($p, $c); $p->Circle($x, $y, $i % 3 ? 3 : 4.5); }
            $p->SetDrawColor(240, 200, 225); $p->SetLineWidth(0.8);
            foreach ([[470, 90, 26, 32, '#F7D774', '#FBEBB5'], [515, 75, 28, 34, '#8FD3EE', '#C9EBF7'], [552, 102, 21, 26, '#FFFFFF', '#FFFFFF']] as [$x, $y, $rx, $ry, $c, $hl]) {
                $p->Line($x, $y + $ry, $x - 6 + ($x % 12), $y + $ry + 70);
                self::fill($p, $c); $p->Ellipse($x, $y, $rx, $ry);
                if ($c !== '#FFFFFF') { self::fill($p, $hl); $p->Ellipse($x - $rx * 0.35, $y - $ry * 0.3, $rx * 0.18, $ry * 0.25); }
            }
        } else {
            self::fill($p, '#FFFFFF'); $p->Rect(0, 0, $W, $H, 'F');
            self::fill($p, '#FBE8F2'); $p->Circle($W - 40, -30, 190);
            self::fill($p, '#E7F5FB'); $p->Circle(-30, $H + 50, 170);
            $p->SetDrawColor(231, 166, 203); $p->SetLineWidth(0.8); $p->Rect(14, 14, $W - 28, $H - 28, 'D');
        }
        $txt = $fete ? '#FFFFFF' : '#2A2A33'; $sub = $fete ? '#FFFFFF' : '#5B6470'; $accent = $fete ? '#F7D774' : '#5C1360'; $big = $fete ? '#FFFFFF' : '#2B8DBB'; $brand = $fete ? '#FFFFFF' : '#B8006E';

        // en-tête
        $p->SetFont('Times', 'BI', 34); self::color($p, $brand); $p->Text(40, 70, self::t('Aqua Beauté'));
        $p->SetFont('Helvetica', 'B', 8); self::color($p, $sub); $p->Text(42, 88, self::t('INSTITUT DE BEAUTÉ · MUTZIG'));

        // titre et contenu
        $p->SetFont('Times', 'BI', 30); self::color($p, $accent); $p->Text(40, 150, self::t($fete ? ($v['occasion'] ?: 'Joyeux anniversaire') : 'Bon cadeau'));
        $p->SetFont('Helvetica', '', 13); self::color($p, $sub); $p->Text(42, 176, self::t(($v['to'] ?? '') !== '' ? 'Pour ' . $v['to'] : 'Pour vous'));
        if (($v['type'] ?? '') === 'soin') {
            $p->SetFont('Helvetica', 'B', 20); self::color($p, $big);
            foreach (self::wrap($p, self::t($v['soin']), 330, 2) as $i => $l) $p->Text(40, 210 + $i * 24, $l);
        } else {
            $p->SetFont('Helvetica', 'B', 44); self::color($p, $big);
            $p->Text(40, 222, self::t(str_replace('.', ',', (string) ($v['price'] + 0)) . ' €'));
        }
        if (($v['msg'] ?? '') !== '') {
            $p->SetFont('Times', 'I', 12.5); self::color($p, $sub);
            foreach (self::wrap($p, self::t('« ' . $v['msg'] . ' »'), 330, 3) as $i => $l) $p->Text(42, 262 + $i * 16, $l);
        }

        // code, validité, QR
        $p->SetFont('Helvetica', 'B', 8); self::color($p, $sub); $p->Text(42, 328, 'Code');
        $p->SetFont('Courier', 'B', 16); $cw = $p->GetStringWidth($v['code']);
        self::fill($p, $fete ? '#FFFFFF' : '#FBE8F2'); $p->Rect(38, 334, $cw + 16, 24, 'F');
        self::color($p, '#2A2A33'); $p->Text(46, 351, $v['code']);
        $until = $v['untilFr'];
        $valid = $v['type'] === 'soin' ? "Soin offert · valable jusqu'au $until"
            : ($v['type'] === 'accessoire' ? "Valable jusqu'au $until sur les accessoires de la boutique (sacs, bijoux, foulards)" : "Valable jusqu'au $until sur toutes les prestations");
        $p->SetFont('Helvetica', '', 9); self::color($p, $sub); $p->Text(42, 376, self::t($valid));
        $p->SetFont('Helvetica', '', 8); $p->Text(42, 392, self::t('8 rue du Maréchal Foch, 67190 Mutzig · 03 88 23 00 98 · Sur rendez-vous'));

        $qr = QRCode::getMinimumQRCode($verifyUrl, QR_ERROR_CORRECT_LEVEL_M);
        $n = $qr->getModuleCount(); $size = 96; $m = $size / $n; $qx = $W - 140; $qy = $H - 40 - $size;
        self::fill($p, '#FFFFFF'); $p->Rect($qx - 6, $qy - 6, $size + 12, $size + 12, 'F');
        self::fill($p, '#2A2A33');
        for ($r = 0; $r < $n; $r++) for ($c = 0; $c < $n; $c++) if ($qr->isDark($r, $c)) $p->Rect($qx + $c * $m, $qy + $r * $m, $m + 0.2, $m + 0.2, 'F');
        $p->SetFont('Helvetica', '', 7.5); self::color($p, $sub);
        $p->Text($qx, 398, self::t('Présentez ce bon')); $p->Text($qx, 407, self::t("à l'institut"));

        return $p->Output('S');
    }
}
