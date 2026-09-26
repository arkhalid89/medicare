<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal native .xlsx writer — no library, no zip extension required.
 * Builds the Office Open XML parts and packs them with a small built-in
 * ZIP writer (deflate when zlib is available, otherwise stored).
 */
final class XlsxWriter
{
    public const STYLE_NORMAL = 4;
    public const STYLE_NUMBER = 5;
    public const STYLE_HEADER = 1;
    public const STYLE_TITLE  = 2;
    public const STYLE_META   = 3;

    /** @var array<int, array{cells: array, style: int}> */
    private array $rows = [];
    private array $widths = [];
    private int $headerRow = 0;
    private int $columnCount = 0;

    public function addRow(array $cells, int $style = self::STYLE_NORMAL): void
    {
        $cells = array_values($cells);
        $this->rows[] = ['cells' => $cells, 'style' => $style];
        if ($style === self::STYLE_HEADER) {
            $this->headerRow = count($this->rows);
            $this->columnCount = count($cells);
        }
        if ($style === self::STYLE_HEADER || $style === self::STYLE_NORMAL) {
            foreach ($cells as $i => $v) {
                $len = min(60, mb_strlen((string) $v) + 2);
                $this->widths[$i] = max($this->widths[$i] ?? 8, $len);
            }
        }
    }

    public function toString(string $sheetName = 'Report'): string
    {
        $sheetName = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $sheetName), 0, 31) ?: 'Report';
        return self::zip([
            '[Content_Types].xml'        => $this->contentTypes(),
            '_rels/.rels'                => $this->rootRels(),
            'docProps/app.xml'           => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>MediCare Practice</Application></Properties>',
            'docProps/core.xml'          => $this->coreProps(),
            'xl/workbook.xml'            => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . self::x($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml'              => $this->styles(),
            'xl/worksheets/sheet1.xml'   => $this->sheet(),
        ]);
    }

    private function sheet(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        if ($this->headerRow > 0) {
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $this->headerRow . '" topLeftCell="A' . ($this->headerRow + 1)
                . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if ($this->widths) {
            $xml .= '<cols>';
            foreach ($this->widths as $i => $w) {
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach ($this->rows as $r => $row) {
            $rowNo = $r + 1;
            $xml .= '<row r="' . $rowNo . '">';
            foreach ($row['cells'] as $c => $value) {
                $ref = self::col($c) . $rowNo;
                if ($value === null || $value === '') {
                    $xml .= '<c r="' . $ref . '" s="' . $row['style'] . '"/>';
                } elseif ($row['style'] === self::STYLE_NORMAL && self::isNumber($value)) {
                    $style = is_float($value) || str_contains((string) $value, '.') ? self::STYLE_NUMBER : self::STYLE_NORMAL;
                    $xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . (0 + $value) . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" s="' . $row['style'] . '" t="inlineStr"><is><t xml:space="preserve">' . self::x((string) $value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if ($this->headerRow > 0 && $this->columnCount > 0) {
            $xml .= '<autoFilter ref="A' . $this->headerRow . ':' . self::col($this->columnCount - 1) . max($this->headerRow, count($this->rows)) . '"/>';
        }
        $xml .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
        return $xml;
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="10"/><name val="Segoe UI"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>'
            . '<font><b/><sz val="14"/><color rgb="FF0A2463"/><name val="Segoe UI"/></font>'
            . '<font><i/><sz val="9"/><color rgb="FF6B7280"/><name val="Segoe UI"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0A2463"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFD1D5DB"/></left><right style="thin"><color rgb="FFD1D5DB"/></right><top style="thin"><color rgb="FFD1D5DB"/></top><bottom style="thin"><color rgb="FFD1D5DB"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            . '</cellXfs></styleSheet>';
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function coreProps(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>MediCare Practice</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    /** 0 => A, 25 => Z, 26 => AA */
    public static function col(int $index): string
    {
        $s = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + (($n - 1) % 26)) . $s;
        }
        return $s;
    }

    /** Numeric cell? Codes with leading zeros (phone numbers) stay text. */
    private static function isNumber(mixed $v): bool
    {
        if (is_int($v) || is_float($v)) {
            return true;
        }
        $s = (string) $v;
        return strlen($s) <= 15 && preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $s) === 1;
    }

    private static function x(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string,string> $files path => contents */
    public static function zip(array $files): string
    {
        $data = '';
        $central = '';
        $offset = 0;
        $time = getdate();
        $dosTime = ($time['hours'] << 11) | ($time['minutes'] << 5) | intdiv($time['seconds'], 2);
        $dosDate = (($time['year'] - 1980) << 9) | ($time['mon'] << 5) | $time['mday'];
        $canDeflate = function_exists('gzdeflate');

        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $size = strlen($content);
            $method = 0;
            $stored = $content;
            if ($canDeflate) {
                $deflated = gzdeflate($content, 6);
                if ($deflated !== false && strlen($deflated) < $size) {
                    $stored = $deflated;
                    $method = 8;
                }
            }
            $compressed = strlen($stored);

            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dosTime, $dosDate, $crc, $compressed, $size, strlen($name), 0) . $name;
            $data .= $local . $stored;

            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $dosTime, $dosDate, $crc, $compressed, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
            $offset += strlen($local) + $compressed;
        }

        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), $offset, 0);
        return $data . $central . $end;
    }
}
