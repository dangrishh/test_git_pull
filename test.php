<?php
namespace App\Template\PoFocal;

class PoWorkProgramTemplate
{
    private static function esc($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    //  NEW: Convert JSON array / array / text into list items
    private static function toListItems($raw): array
    {
        if ($raw === null) return [];

        // already array
        if (is_array($raw)) {
            return array_values(array_filter(array_map('trim', $raw), fn($v) => $v !== ''));
        }

        $s = trim((string)$raw);
        if ($s === '' || $s === '[]') return [];

        // JSON array string
        if (strlen($s) > 1 && $s[0] === '[') {
            $decoded = json_decode($s, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return array_values(array_filter(array_map('trim', $decoded), fn($v) => $v !== ''));
            }
        }

        // fallback: comma/newline separated
        $parts = preg_split('/\r\n|\r|\n|,/', $s);
        return array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));
    }

    //  NEW: Bullet HTML (TCPDF friendly; avoids <ul><li> issues)
    private static function bulletHtml($raw, string $bullet = '•'): string
    {
        $items = self::toListItems($raw);
        if (!$items) return '-';

        $out = '<div style="line-height:1.2;">';
        foreach ($items as $v) {
            $out .= '<div>'.$bullet.'&nbsp;'.self::esc($v).'</div>';
        }
        $out .= '</div>';

        return $out;
    }

    // PdfLayouts.php (or wherever headerHtml lives)
    public static function headerRow(string $label, string $value): string {
        return '
        <tr>
            <td width="18%" style="padding:0 2mm 1mm 0;"><b>'.self::esc($label).'</b></td>
            <td width="82%" style="padding:0 0 1mm 0;">'.self::esc($value).'</td>
        </tr>';
    }

    public static function headerHtml(array $h): string {
        return '
        <table width="100%" border="0" cellspacing="0" cellpadding="0">
        '.self::headerRow('Region Office:', $h['region_office'] ?? '').'
        '.self::headerRow('Proponent:', $h['wp_proponent'] ?? '').'
        '.self::headerRow('Nature of Work:', $h['wp_nature_work'] ?? '').'
        '.self::headerRow('Mode of Implementation:', $h['wp_mode_implementation'] ?? '').'
        </table>';
    }

    public static function signatoryAndNotesHtml(array $h): string {
        // ---------- TUNABLES ----------
        $linePct    = (float)($h['_sign_line_width_pct']     ?? 60);   // signature line width (%)
        $thickPx    = (float)($h['_sign_line_thickness_px']  ?? 2.2);  // line thickness
        $gapMm      = (float)($h['_sign_line_gap_mm']        ?? 2.0);  // gap between line and name
        $spBefore   = (float)($h['_gap_above_signatory_mm']  ?? 12.0); // space above signatory block
        $spNotes    = (float)($h['_gap_above_notes_mm']      ?? 8.0);  // space above notes

        // Signature image sizing
        // $imgWmm      = isset($h['_sign_img_width_mm']) ? (float)$h['_sign_img_width_mm'] : 50.0;
        $imgWmm = isset($h['_sign_img_width_mm']) ? (float)$h['_sign_img_width_mm'] : 40.0;
        $imgMarginMm = (float)($h['_sign_img_margin_mm'] ?? 1.5);   

        // File resolution
        $defExt  = (string)($h['_esign_default_ext'] ?? 'png');
        $baseDir = rtrim((string)($h['_esign_base_dir'] ?? ''), '/');

        // Header fields
        $certName  = trim((string)($h['signatory_name']           ?? ''));
        $certPos   = trim((string)($h['signatory_position']       ?? 'DOLE PO/FO Head'));
        $focalName = trim((string)($h['signatory_focal_name']     ?? ''));
        $focalPos  = trim((string)($h['signatory_focal_position'] ?? 'DOLE PO/FO Focal Person or Co-Partner'));
        $wpNo      = trim((string)($h['work_program_number']      ?? ''));

        // Raw image paths (from DB)
        $focalRaw = trim((string)($h['focal_signatory_esign_img'] ?? ''));

        // ---------- HELPERS ----------
        $resolveImg = function (string $val) use ($baseDir, $defExt): string {
            $v = trim($val);
            if ($v === '') return '';
            if (stripos($v, 'data:image/') === 0) return $v;  // already base64
            if (preg_match('~^https?://~i', $v)) return $v;   // remote allowed

            $root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
            $dirs = [$baseDir, $root, $root.'/TUPAD']; // try all
            $names = [$v, "$v.$defExt", "assets/img/".basename($v), "assets/download/".basename($v)];
            $exts = ['', '.png', '.PNG', '.jpg', '.jpeg'];

            foreach ($dirs as $d) {
                foreach ($names as $n) {
                    foreach ($exts as $e) {
                        $path = str_replace(['\\','//'], ['/', '/'], "$d/".ltrim($n.$e,'/'));
                        if (is_file($path)) {
                            $data = @file_get_contents($path);
                            if ($data !== false) {
                                $mime = preg_match('/jpe?g$/i', $path) ? 'jpeg' : 'png';
                                return 'data:image/'.$mime.';base64,'.base64_encode($data);
                            }
                        }
                    }
                }
            }
            return '';
        };

        $focalDataUri = $resolveImg($focalRaw);

        $lineDiv = fn() => '<div style="width:'.$linePct.'%;height:'.$thickPx.'px;background:#000;border-radius:'.$thickPx.'px;margin:0 auto;"></div>';

        $sigImg = function(string $dataUri) use ($imgWmm, $imgMarginMm): string {
            if ($dataUri === '') return '';
            $src      = self::esc($dataUri);
            $finalWmm = max(150.0, min(10.0, (float)$imgWmm));
            return '<img src="'.$src.'" width="'.$finalWmm.'" style="display:block; margin:0 auto '.$imgMarginMm.'mm;">';
        };

        $nameBox = function(string $name, string $pos, string $dataUri) use ($linePct, $sigImg): string {
            $img = $sigImg($dataUri);
            $n = $name !== '' ? self::esc($name) : '&nbsp;';
            $p = self::esc($pos);

            return '
            <div style="width:'.$linePct.'%;margin:0 auto;text-align:center;line-height:0.5;padding:0;">
                '.$img.'
                <div style="margin:0;padding:0;font-weight:bold;">'.$n.'</div>
                <div style="margin:0;padding:0;font-size:9px;">'.$p.'</div>
            </div>';
        };

        return '
            <div style="height:'.$spBefore.'mm"></div>
        <table width="100%" border="0" cellspacing="0" cellpadding="2" style="text-align:center;">
            <tr>
                <td width="50%" style="text-align:left;"><b>Prepared by:</b></td>
                <td width="50%" style="text-align:left;"><b>Certified True and Correct by:</b></td>
            </tr>
            <tr>
                <td>'.$lineDiv().'</td>
                <td>'.$lineDiv().'</td>
            </tr>
            <tr>
                <td>'.$nameBox($focalName, $focalPos, $focalDataUri).'</td>
                <td>'.$nameBox($certName,  $certPos,  "").'</td>
            </tr>
        </table>

        <table width="100%" border="0" cellspacing="0" cellpadding="1" style="margin-top:'.$spNotes.'mm;">
            <tr>
                <td width="70%" style="font-size:9px; line-height:1.35; vertical-align:top;">
                    <b>Date:</b> ____________<br/>
                    <b>Notes:</b><br/>
                    <b>Proponent:</b> (a.) LGU (b.) Accredited Co-partner<br/>
                    <b>Mode of Implementation:</b> (a.) Thru Direct Administration (b.) Thru Co-partner, pls. specify<br/>
                    <b>Nature of Work:</b> Specify the type of work i.e. social, economic, and agroforestry project<br/>
                    <b>Activities:</b> Specify the activities to be undertaken<br/>
                    <b>Funding Requirements (others):</b> Other costing include micro-insurance, PPEs, cleaning solutions, etc.
                </td>
                <td width="30%" style="vertical-align:bottom; text-align:right; font-size:10px;">
                    <b>WP No.:</b> '.self::esc($wpNo).'
                </td>
            </tr>
        </table>';
    }

    public static function tableHtml(array $items, bool $includeTotals = true, array $opts = []): string {
        $AUTO = (bool)($opts['auto_widths'] ?? false);

        $W = [
            'brgy'  => 6.0, 'muni'  => 6.0, 'prov'  => 6.0, 'dist'  => 6.0,
            'benef' => 8.0, 'days'  => 6.0, 'acts'  => 8.0,
            'q1'    => 3.0, 'q2'    => 3.0, 'q3'    => 3.0, 'q4'    => 3.0,
            'wage'  => 5.667, 'ppe' => 5.667, 'micro' => 5.666,
            'admin' => 5.667, 'req' => 5.667, 'co' => 5.666,
            'total' => 8.0,
        ];

        if ($AUTO) {
            // keep your auto width logic unchanged
        }

        $G = [
            'proj'   => $W['brgy'] + $W['muni'] + $W['prov'] + $W['dist'],
            'period' => $W['q1'] + $W['q2'] + $W['q3'] + $W['q4'],
            'fund'   => $W['wage'] + $W['ppe'] + $W['micro'] + $W['admin'] + $W['req'] + $W['co'],
        ];
        $w = fn($x) => number_format((float)$x, 3, '.', '') . '%';

        $tdL = ' align="left"  style="vertical-align:middle;"';
        $tdC = ' align="center" style="vertical-align:middle;"';
        $tdR = ' align="right" nobr="true" style="vertical-align:middle;"';
        $int   = fn($v) => '<nobr>'.number_format((float)$v).'</nobr>';
        $money = fn($v) => '<nobr><span style="font-size:8.5px;">'.number_format((float)$v, 2).'</span></nobr>';

        $thead = '
        <table cellpadding="2" cellspacing="0" border="1" width="100%">
        <thead>
        <tr style="font-weight:bold; background-color:#f1f1f1; line-height:1;" height="22">
            <th colspan="4" width="'.$w($G['proj']).'">Project Location</th>
            <th rowspan="2" width="'.$w($W['benef']).'">No. of Target<br>Beneficiaries</th>
            <th rowspan="2" width="'.$w($W['days']).'">No. of Days<br>of Employment</th>
            <th rowspan="2" width="'.$w($W['acts']).'">Activities</th>
            <th colspan="4" width="'.$w($G['period']).'">Period of Implementation</th>
            <th colspan="6" width="'.$w($G['fund']).'">Funding Requirements</th>
            <th rowspan="2" width="'.$w($W['total']).'">Total</th>
        </tr>
        <tr style="font-weight:bold; background-color:#f1f1f1; line-height:1;" height="22">
            <th width="'.$w($W['brgy']).'">Brgy.</th>
            <th width="'.$w($W['muni']).'">Municipality/<br>City</th>
            <th width="'.$w($W['prov']).'">Province</th>
            <th width="'.$w($W['dist']).'">District</th>
            <th width="'.$w($W['q1']).'">Q1</th>
            <th width="'.$w($W['q2']).'">Q2</th>
            <th width="'.$w($W['q3']).'">Q3</th>
            <th width="'.$w($W['q4']).'">Q4</th>
            <th width="'.$w($W['wage']).'">Wages</th>
            <th width="'.$w($W['ppe']).'">PPE</th>
            <th width="'.$w($W['micro']).'">Micro</th>
            <th width="'.$w($W['admin']).'">Admin</th>
            <th width="'.$w($W['req']).'">Requested</th>
            <th width="'.$w($W['co']).'">Co-partner</th>
        </tr>
        </thead>
        <tbody>';

        $rows = '';
        $sum = [
            'benef'     => 0,
            'wages'     => 0,
            'ppe'       => 0,
            'micro'     => 0,
            'admin'     => 0,
            'request'   => 0,
            'copartner' => 0,
            'total'     => 0,
        ];

        foreach ($items as $it) {
            $pi = strtoupper((string)($it['wp_period_implementation'] ?? ''));
            $hasQ1 = (strpos($pi, 'Q1') !== false);
            $hasQ2 = (strpos($pi, 'Q2') !== false);
            $hasQ3 = (strpos($pi, 'Q3') !== false);
            $hasQ4 = (strpos($pi, 'Q4') !== false);
            $mark = '✔';

            $sum['benef']     += (float)($it['wp_no_beneficiaries'] ?? 0);
            $sum['wages']     += (float)($it['wp_wages'] ?? 0);
            $sum['ppe']       += (float)($it['wp_ppe'] ?? 0);
            $sum['micro']     += (float)($it['wp_micro_insurance'] ?? 0);
            $sum['admin']     += (float)($it['admin_cost'] ?? 0);
            $sum['request']   += (float)($it['wp_total_amount_request'] ?? 0);
            $sum['copartner'] += (float)($it['wp_co_partner_total'] ?? 0);
            $sum['total']     += (float)($it['wp_total'] ?? 0);

            $rows .= '<tr>'
                . '<td'.$tdL.' width="'.$w($W['brgy']).'">'.self::esc($it['wp_project_loc_brgy'] ?? '').'</td>'
                . '<td'.$tdL.' width="'.$w($W['muni']).'">'.self::esc($it['wp_city_municipality'] ?? '').'</td>'
                . '<td'.$tdL.' width="'.$w($W['prov']).'">'.self::esc($it['wp_province'] ?? '').'</td>'
                . '<td'.$tdL.' width="'.$w($W['dist']).'">'.self::esc($it['wp_district'] ?? '').'</td>'
                . '<td'.$tdR.' width="'.$w($W['benef']).'">'.$int($it['wp_no_beneficiaries'] ?? 0).'</td>'
                . '<td'.$tdR.' width="'.$w($W['days']).'">'.$int($it['wp_no_days_employment'] ?? 0).'</td>'

                //   FIX: Activities as bullets from wp_nature_desc (JSON array string)
                . '<td'.$tdL.' width="'.$w($W['acts']).'">'.self::bulletHtml($it['wp_nature_desc'] ?? '').'</td>'

                . '<td'.$tdC.' width="'.$w($W['q1']).'">'.($hasQ1 ? $mark : '').'</td>'
                . '<td'.$tdC.' width="'.$w($W['q2']).'">'.($hasQ2 ? $mark : '').'</td>'
                . '<td'.$tdC.' width="'.$w($W['q3']).'">'.($hasQ3 ? $mark : '').'</td>'
                . '<td'.$tdC.' width="'.$w($W['q4']).'">'.($hasQ4 ? $mark : '').'</td>'
                . '<td'.$tdR.' width="'.$w($W['wage']).'">'.$money($it['wp_wages'] ?? 0).'</td>'
                . '<td'.$tdR.' width="'.$w($W['ppe']).'">'.$money($it['wp_ppe'] ?? 0).'</td>'
                . '<td'.$tdR.' width="'.$w($W['micro']).'">'.$money($it['wp_micro_insurance'] ?? 0).'</td>'
                . '<td'.$tdR.' width="'.$w($W['admin']).'">'.$money($it['admin_cost'] ?? 0).'</td>'
                . '<td'.$tdR.' width="'.$w($W['req']).'">'.$money($it['wp_total_amount_request'] ?? 0).'</td>'
                . '<td'.$tdR.' width="'.$w($W['co']).'">'.$money($it['wp_co_partner_total'] ?? 0).'</td>'
                . '<td'.$tdR.' width="'.$w($W['total']).'">'.$money($it['wp_total'] ?? 0).'</td>'
                . '</tr>';
        }

        $tfoot = '';
        if ($includeTotals) {
            $t = $opts['totals'] ?? [];

            $benef     = $t['benef']     ?? $sum['benef'];
            $wages     = $t['wages']     ?? $sum['wages'];
            $ppe       = $t['ppe']       ?? $sum['ppe'];
            $micro     = $t['micro']     ?? $sum['micro'];
            $admin     = $t['admin']     ?? $sum['admin'];
            $request   = $t['request']   ?? $sum['request'];
            $copartner = $t['copartner'] ?? $sum['copartner'];
            $total     = $t['total']     ?? $sum['total'];

            $tfoot = '
            <tr style="font-weight:bold;">
                <td colspan="4" align="right" style="vertical-align:middle;">TOTAL</td>
                <td'.$tdR.'>'.$int($benef).'</td>
                <td'.$tdC.'></td>
                <td'.$tdC.'></td>
                <td'.$tdC.'></td>
                <td'.$tdC.'></td>
                <td'.$tdC.'></td>
                <td'.$tdC.'></td>
                <td'.$tdR.'>'.$money($wages).'</td>
                <td'.$tdR.'>'.$money($ppe).'</td>
                <td'.$tdR.'>'.$money($micro).'</td>
                <td'.$tdR.'>'.$money($admin).'</td>
                <td'.$tdR.'>'.$money($request).'</td>
                <td'.$tdR.'>'.$money($copartner).'</td>
                <td'.$tdR.'>'.$money($total).'</td>
            </tr>';
        }

        return $thead . $rows . $tfoot . '</tbody></table>';
    }

    /** Combine parts with options: include_header / include_table / include_totals */
    public static function fullHtml(array $bundle, array $options = []): string
    {
        $header = $bundle['header'] ?? [];
        $items  = $bundle['items']  ?? [];

        $html = '';
        if (($options['include_header'] ?? true)) {
            $html .= self::headerHtml($header) . '<br />';
        }
        if (($options['include_table'] ?? true)) {
            $html .= self::tableHtml(
                $items,
                $options['include_totals'] ?? true,
                ['auto_widths' => $options['_auto_widths'] ?? true]
            );
        }

        if (($options['include_signatory_block'] ?? true)) {
            $html .= self::signatoryAndNotesHtml($header);
        }

        return $html;
    }

    public static function renderPdf(array $bundle, array $options = []): string
    {
        $orientation = strtoupper($options['orientation'] ?? 'L');
        $format      = $options['format'] ?? 'A4';

        $pdf = new \TCPDF($orientation, PDF_UNIT, $format, true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);

        $pdf->SetCellPadding(0.6);
        $pdf->setCellHeightRatio(1.05);
        $pdf->setHtmlVSpace([
            'table'=>[0=>['h'=>0,'n'=>0],1=>['h'=>0,'n'=>0]],
            'thead'=>[0=>['h'=>0,'n'=>0],1=>['h'=>0,'n'=>0]],
            'tr'   =>[0=>['h'=>0,'n'=>0],1=>['h'=>0,'n'=>0]],
        ]);

        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 9);

        $html = self::fullHtml($bundle, [
            'include_header'          => $options['include_header'] ?? true,
            'include_table'           => true,
            'include_totals'          => $options['include_totals'] ?? true,
            'include_signatory_block' => $options['include_signatory_block'] ?? true,
            '_auto_widths'            => $options['_auto_widths'] ?? true,
            '_gap_above_signatory_mm' => $options['_gap_above_signatory_mm'] ?? ($bundle['header']['_gap_above_signatory_mm'] ?? 12),
            '_gap_above_notes_mm'     => $options['_gap_above_notes_mm'] ?? ($bundle['header']['_gap_above_notes_mm'] ?? 8),
        ]);

        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output('work_program.pdf', 'S');
    }
}


class  TupadAppraisalTemplate
{
    public static function render(array $bundle): string
    {
        $h     = $bundle['header'] ?? [];
        $items = $bundle['items'] ?? [];

        $province  = $h['wp_province'] ?? ($items[0]['wp_province'] ?? '__________');
        $proponent = 'DOLE - ' . $province . ' Provincial Office';
        $wpNumber  = $h['work_program_number'] ?? '';

        $firstProvince = $items[0]['wp_province'] ?? '';
        $projectTitle  = 'Tulong Panghanapbuhay sa Ating Disadvantaged Workers in '
            . ($firstProvince ? $firstProvince . ' Province' : '__________');

        $coveredAreas    = self::buildCoveredAreas($items);
        $noBeneficiaries = self::sumColumn($items, 'wp_no_beneficiaries');
        $amountRequested = self::sumColumn($items, 'wp_total_amount_request');
        $equity          = self::sumColumn($items, 'wp_co_partner_total');

        $signatoryName = $h['signatory_name'] ?? '';
        $signatoryPos  = $h['signatory_position'] ?? '';
        $signatoryProv = $h['signatory_province'] ?? '';

        //   this is what your PdfService::tupadAppraisal() resolver sets
        //$signatoryEsignPath = $h['cert_signatory_esign_img'] ?? '';

        $focalName     = $h['signatory_focal_name'] ?? '';
        $focalPosition = $h['signatory_focal_position'] ?? '';

        $regionals = $bundle['regional_signatories'] ?? [];
        $byType = [];
        foreach ($regionals as $r) {
            $type = (string)($r['signatory_position_type'] ?? '');
            $byType[$type] = $r;
        }
        $rd   = $byType['1'] ?? [];
        $ard  = $byType['2'] ?? [];
        $imsd = $byType['4'] ?? [];
        $tssd = $byType['5'] ?? [];
        $rfoc = $byType['6'] ?? [];

        // for recommending approval line 1
        $line1Name = $tssd['signatory_name'] ?? $rfoc['signatory_name'] ?? $focalName ?? '';
        $line1Pos  = $tssd['signatory_position'] ?? $rfoc['signatory_position'] ?? $focalPosition ?? '';

        $today = date('F d, Y');

        ob_start(); ?>
<style>
    .appraisal-title {
        font-size: 15pt;
        font-weight: bold;
        text-align: center;
    }
    .section-title {
        font-weight: bold;
        padding: 5px;
        font-size: 11pt;
        width: 100%;
    }
    .tbl {
        width: 100%;
        margin: 0 auto;
        border-collapse: collapse;
        font-size: 10pt;
        table-layout: fixed;
    }
    .tbl td,
    .tbl th {
        border: 0.5px solid #444;
        padding: 5px 7px;
        vertical-align: top;
        word-wrap: break-word;
    }
    .eval-col { width: 12%; text-align: center; }
    .remarks-col { width: 30%; }
    .sign-block {
        table-layout: fixed;
        font-size: 10pt;
    }
    .sign-block td {
        vertical-align: top;
        padding: 5px 7px;
    }
    .small { font-size: 9pt; }
</style>

<div class="appraisal-title">Annex H</div>
<div class="appraisal-title">TUPAD Program Appraisal</div>
<br>

<!-- A. Project Profile -->
<div class="section-title">A. Project Profile</div>
<table class="tbl" style="width:180mm; table-layout:fixed;">
    <tr>
        <td style="width:55mm; background-color:#d9e1f2; font-weight:bold; font-size:10pt;">Project Title:</td>
        <td style="width:125mm;"><?= htmlspecialchars($projectTitle) ?></td>
    </tr>
    <tr>
        <td style="background-color:#d9e1f2; font-weight:bold; font-size:10pt;">Project Proponent:</td>
        <td><?= htmlspecialchars($proponent) ?></td>
    </tr>
    <tr>
        <td style="background-color:#d9e1f2; font-weight:bold; font-size:10pt;">Covered Areas:</td>
        <td><?= htmlspecialchars($coveredAreas ?: 'State areas affected where project will be implemented') ?></td>
    </tr>
    <tr>
        <td style="background-color:#d9e1f2; font-weight:bold; font-size:10pt;">Number of Beneficiaries:</td>
        <td><?= number_format($noBeneficiaries) ?></td>
    </tr>
    <tr>
        <td style="background-color:#d9e1f2; font-weight:bold; font-size:10pt;">Amount of Assistance Requested:</td>
        <td>P <?= number_format($amountRequested, 2) ?></td>
    </tr>
    <tr>
        <td style="background-color:#d9e1f2; font-weight:bold; font-size:10pt;">Source of Funds:</td>
        <td><?= htmlspecialchars($wpNumber ?: '') ?></td>
    </tr>
    <tr>
        <td style="background-color:#d9e1f2; font-weight:bold; font-size:10pt;">Equity of the Proponent:</td>
        <td>P <?= number_format($equity, 2) ?></td>
    </tr>
</table>

<br>

<!-- B. Evaluation -->
<div class="section-title">B. Evaluation</div>
<div style="font-size:10pt; line-height:1.4; text-align:justify; margin-bottom:4mm;">
    Place a check mark (<b>/</b>) in the box if the requirements are met; otherwise, place an <b>X</b>.
    Indicate any observations and recommendations under the remarks column.
</div>
<table class="tbl">
    <tr style="background-color:#d9e1f2; font-weight:bold; text-align:center;">
        <th style="width:58%;">Criteria</th>
        <th class="eval-col">Evaluation<br/>( / or X)</th>
        <th class="remarks-col">Remarks</th>
    </tr>

    <tr>
        <td>
            <b>A. Documentary Requirements</b><br/>
            Complete documentary requirements were submitted
            (refer to attached checklist of requirements)
        </td>
        <td class="eval-col">/</td>
        <td class="remarks-col">Work Program</td>
    </tr>
    <tr>
        <td><b>B. Applicability of Minimum Wage</b><br/>Wage is based on the highest prevailing minimum wage in the region</td>
        <td class="eval-col">/</td>
        <td class="remarks-col"></td>
    </tr>
    <tr>
        <td><b>C. Completeness of Work Program</b><br/>Work program is complete and accurate</td>
        <td class="eval-col">/</td>
        <td class="remarks-col"></td>
    </tr>
    <tr>
        <td><b>D. Provision of Personal Protective Equipment</b><br/>
            • Minimum Personal Protective Equipment (PPEs) i.e. hats and shirt are provided <br/>
            • Other PPEs i.e. helmet, gloves, booths, etc, are provided depending on the nature of work<br/>
            • Reasonable costs of PPEs is observed
        </td>
        <td class="eval-col"></td>
        <td class="remarks-col">Tshirt and hat only at reasonable costs</td>
    </tr>
    <tr>
        <td><b>E. Orientation on Safety and Health and Emergency First Aid</b><br/>Orientation on Safety and Health and Emergency First Aid will be provided</td>
        <td class="eval-col"></td>
        <td class="remarks-col">To be provided on first day of implementation</td>
    </tr>
    <tr>
        <td><b>F. Orientation/Provision of menu of skills training that may be availed by beneficiaries</b></td>
        <td class="eval-col"></td>
        <td class="remarks-col">To be provided on first day of implementation</td>
    </tr>
    <tr>
        <td><b>G. Inclusion of Micro-Insurance Premiums</b><br/>Provision of Micro-Insurance premiums is included</td>
        <td class="eval-col"></td>
        <td class="remarks-col">To be enrolled before actual work</td>
    </tr>
    <tr>
        <td><b>H. Provision of Equity</b><br/>(Equity should be at least 20% of total project cost in case of implementation through co-partner. In case of
                                            direct administration, equity need not be at least 20%
                                            of the total project cost)</td>
        <td class="eval-col"></td>
        <td class="remarks-col"><?= $equity > 0 ? number_format($equity, 2) . ' as indicated in the Work Program' : '' ?></td>
    </tr>
    <tr>
        <td><b>I. No unliquidated funds</b></td>
        <td class="eval-col">/</td>
        <td class="remarks-col"></td>
    </tr>
    <tr>
        <td><b>J. Provision of After-Engagement Services</b></td>
        <td class="eval-col">/</td>
        <td class="remarks-col"></td>
    </tr>
    <tr>
        <td><b>K. Relevance and Viability of the Project</b><br/>
            • Responsive to the needs of community<br/>
            • Nature of work falls under the eligible projects for TUPAD program<br/>
            • With support from partners
        </td>
        <td class="eval-col">/</td>
        <td class="remarks-col"></td>
    </tr>
</table>
<br>

<tcpdf pagebreak="true" />
<div style="margin-top:5mm;"></div>

<!-- C. Signatories -->
<table class="tbl sign-block">
    <tr>
        <td colspan="2"
            style="background-color:#d9e1f2;
                font-weight:bold;
                text-align:center;
                border:0.5px solid #000;
                padding:5px;
                width:100%;
                box-sizing:border-box;">
            Remarks
        </td>
    </tr>

    <!-- reviewed -->
    <tr>
        <td style="width:55%;">
            <b>Reviewed/Evaluated by:</b><br><br>
            <div style="text-align:center;">
                <?php if (!empty($signatoryEsignPath)): ?>
                    <img src="<?= htmlspecialchars($signatoryEsignPath) ?>"
                         style="height:40px; margin-bottom:4px;"><br>
                <?php else: ?>
                    <div style="height:40px; margin-bottom:4px;"></div>
                <?php endif; ?>

                <b><?= htmlspecialchars($signatoryName ?: '') ?></b><br>
                <?= htmlspecialchars($signatoryPos ?: '') ?><br>
                <?= htmlspecialchars($signatoryProv ?: '') ?><br><br>
            </div>
        </td>
        <td style="width:45%; vertical-align:top;">
            <b>Date:</b><br><br><br>
        </td>
    </tr>

    <tr>
        <td style="width:55%;">
            <b>Recommending Approval:</b><br><br>
            <div style="text-align:center;">
                <b><?= htmlspecialchars($line1Name) ?></b><br>
                <?= htmlspecialchars($line1Pos) ?><br><br>
                <b><?= htmlspecialchars($imsd['signatory_name'] ?? '') ?></b><br>
                <?= htmlspecialchars($imsd['signatory_position'] ?? '') ?><br><br>
                <b><?= htmlspecialchars($ard['signatory_name'] ?? '') ?></b><br>
                <?= htmlspecialchars($ard['signatory_position'] ?? '') ?><br><br>
            </div>

            <p class="small" style="margin-top:4mm; text-align:justify; font-size:9px;">
                <i>
                    Note: IMSD Chief to also appraise the status of liquidation on previous DOLE assistance,
                    as applicable, as well as the availability of fund source in the region.
                </i>
            </p>
        </td>

        <td style="width:45%; vertical-align:top;">
            <b>Date:</b><br><br><br>
        </td>
    </tr>
</table>

<table class="tbl" style="width:100%; margin-top:6mm;">
    <tr>
        <td style="text-align:left; padding:8px; line-height:1.6;">
            ___ Approved<br>
            ___ Disapproved<br>
            ___ Other Instructions/Recommendations: ______________________
        </td>
    </tr>
</table>

<table class="tbl">
    <tr>
        <td style="width:55%;">
            <div style="text-align:center;">
                <b><?= htmlspecialchars($rd['signatory_name'] ?? '') ?></b><br>
                <?= htmlspecialchars($rd['signatory_position'] ?? '') ?><br>
            </div>
        </td>
        <td style="width:45%;">
            <b>Date:</b><br><br><br>
        </td>
    </tr>
</table>

<?php
        return ob_get_clean();
    }

    private static function buildCoveredAreas(array $items): string
    {
        $areas = [];
        foreach ($items as $row) {
            $parts = [];
            if (!empty($row['wp_city_municipality'])) {
                $parts[] = $row['wp_city_municipality'];
            }
            if ($parts) {
                $areas[] = implode(', ', $parts);
            }
        }
        $areas = array_unique($areas);
        return implode('; ', $areas);
    }

    private static function sumColumn(array $items, string $key): float
    {
        $sum = 0.0;
        foreach ($items as $row) {
            if (isset($row[$key])) {
                $sum += (float)$row[$key];
            }
        }
        return $sum;
    }
}

