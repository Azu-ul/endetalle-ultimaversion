<?php

namespace App\Services\Export;

use ZipArchive;

/**
 * Escribe un .xlsx de verdad (se abre en Excel, LibreOffice y Google Sheets) sin librerías externas.
 * Hojas con encabezado en negrita, primera fila fija y filtros. Todo va como texto o número.
 */
class XlsxWriter
{
    /** @var array<int, array{name: string, headers: string[], rows: array<int, array<int, string|int|float|null>>, widths: int[]}> */
    private array $sheets = [];

    /**
     * @param string[] $headers
     * @param array<int, array<int, string|int|float|null>> $rows
     * @param int[] $widths ancho de cada columna en caracteres
     */
    public function addSheet(string $name, array $headers, array $rows, array $widths = []): self
    {
        // Excel no acepta nombres de hoja de más de 31 caracteres ni con \ / ? * [ ] :
        $name = mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $name), 0, 31);
        $this->sheets[] = compact('name', 'headers', 'rows', 'widths');

        return $this;
    }

    public function save(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el archivo Excel.');
        }

        $overrides = '';
        $sheetEntries = '';
        $relEntries = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            $sheetEntries .= '<sheet name="' . $this->esc($sheet['name']) . "\" sheetId=\"{$n}\" r:id=\"rId{$n}\"/>";
            $relEntries .= "<Relationship Id=\"rId{$n}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$n}.xml\"/>";
            $zip->addFromString("xl/worksheets/sheet{$n}.xml", $this->sheetXml($sheet));
        }
        $styleRel = 'rId' . (count($this->sheets) + 1);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<sheets>{$sheetEntries}</sheets></workbook>");
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $relEntries
            . "<Relationship Id=\"{$styleRel}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>"
            . '</Relationships>');
        // Estilo 0: normal. Estilo 1: encabezado en negrita sobre fondo amarillo suave.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFACC15"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');

        $zip->close();
    }

    private function sheetXml(array $sheet): string
    {
        $cols = '';
        foreach ($sheet['headers'] as $i => $_) {
            $width = $sheet['widths'][$i] ?? 18;
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . "\" width=\"{$width}\" customWidth=\"1\"/>";
        }

        $lastCol = $this->columnName(max(count($sheet['headers']), 1));
        $lastRow = count($sheet['rows']) + 1;

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . "<cols>{$cols}</cols><sheetData>";

        $xml .= $this->rowXml(1, $sheet['headers'], 1);
        foreach ($sheet['rows'] as $i => $row) {
            $xml .= $this->rowXml($i + 2, $row, 0);
        }

        return $xml . '</sheetData>' . "<autoFilter ref=\"A1:{$lastCol}{$lastRow}\"/></worksheet>";
    }

    private function rowXml(int $rowNumber, array $cells, int $style): string
    {
        $xml = "<row r=\"{$rowNumber}\">";
        foreach (array_values($cells) as $i => $value) {
            $ref = $this->columnName($i + 1) . $rowNumber;
            $s = $style ? ' s="1"' : '';
            if ($value === null || $value === '') {
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $xml .= "<c r=\"{$ref}\"{$s}><v>{$value}</v></c>";
            } else {
                $xml .= "<c r=\"{$ref}\"{$s} t=\"inlineStr\"><is><t xml:space=\"preserve\">" . $this->esc((string) $value) . '</t></is></c>';
            }
        }

        return $xml . '</row>';
    }

    private function columnName(int $n): string
    {
        $name = '';
        while ($n > 0) {
            $n--;
            $name = chr(65 + $n % 26) . $name;
            $n = intdiv($n, 26);
        }

        return $name;
    }

    private function esc(string $text): string
    {
        // Los caracteres de control no son válidos en XML
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
