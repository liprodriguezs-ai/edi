<?php

namespace Modules\Collaborator\Services;

use Illuminate\Support\Str;
use Modules\Company\Models\Company;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CollaboratorExportService
{
    /**
     * Genera un Excel con dos hojas separadas:
     * - Hoja "Empresa": datos de la empresa principal del tenant (una fila)
     * - Hoja "Colaboradores": filas de colaboradores sin datos de empresa
     */
    public function generateExcel(
        string $title,
        $collaborators,
        array $collaboratorColumns,
        array $companyColumns
    ) {
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '512M');

        $spreadsheet = new Spreadsheet;
        $companyHeaders = array_keys($companyColumns);
        $companyTextHeaders = [
            'RFC',
            'Registro patronal',
            'Número de acta constitutiva',
            'Número de notario',
        ];

        $collaboratorHeaders = array_keys($collaboratorColumns);
        $collaboratorTextHeaders = [
            'No. de colaborador',
            'Teléfono',
            'RFC',
            'CURP',
            'NSS',
            'Número de acta de matrimonio',
        ];

        // ── Hoja 1: Empresa ──────────────────────────────────────────────
        $empresaSheet = $spreadsheet->getActiveSheet();
        $empresaSheet->setTitle('Empresa');

        $mainCompany = Company::query()->main()->first();

        // Fila 1 — título
        $lastCompanyCol = Coordinate::stringFromColumnIndex(count($companyColumns));
        $empresaSheet->mergeCells("A1:{$lastCompanyCol}1");
        $empresaSheet->setCellValue('A1', 'empresa');
        $empresaSheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $empresaSheet->getStyle('A1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('F2F2F2');
        $empresaSheet->getRowDimension(1)->setRowHeight(30);

        // Fila 2 — headers empresa
        $colIndex = 1;
        foreach ($companyHeaders as $header) {
            $empresaSheet->setCellValueByColumnAndRow($colIndex, 2, $header);
            $colIndex++;
        }
        $headerStyleEmpresa = $empresaSheet->getStyle("A2:{$lastCompanyCol}2");
        $headerStyleEmpresa->getFont()->setBold(true);
        $headerStyleEmpresa->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('EFEFEF');

        $empresaSheet->freezePane('A3');

        // Fila 3 — datos de la empresa
        if ($mainCompany) {
            $colIndex = 1;
            foreach (array_values($companyColumns) as $resolver) {
                $value = is_callable($resolver) ? $resolver($mainCompany) : ($mainCompany->{$resolver} ?? '');
                $header = $companyHeaders[$colIndex - 1] ?? null;
                $this->setSheetCellValue($empresaSheet, $colIndex, 3, $value, $header, $companyTextHeaders);
                $colIndex++;
            }
        }

        // Auto-ancho y bordes empresa
        for ($i = 1; $i <= count($companyColumns); $i++) {
            $empresaSheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        $maxRowEmpresa = $mainCompany ? 3 : 2;
        $empresaSheet->getStyle("A2:{$lastCompanyCol}{$maxRowEmpresa}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        // ── Hoja 2: Colaboradores ─────────────────────────────────────────
        $colaborSheet = $spreadsheet->createSheet();
        $colaborSheet->setTitle('Colaboradores');

        $lastColabCol = Coordinate::stringFromColumnIndex(count($collaboratorColumns));

        // Fila 1 — título
        $colaborSheet->mergeCells("A1:{$lastColabCol}1");
        $colaborSheet->setCellValue('A1', 'colaboradores');
        $colaborSheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $colaborSheet->getStyle('A1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('F2F2F2');
        $colaborSheet->getRowDimension(1)->setRowHeight(30);

        // Fila 2 — headers colaboradores
        $colIndex = 1;
        foreach ($collaboratorHeaders as $header) {
            $colaborSheet->setCellValueByColumnAndRow($colIndex, 2, $header);
            $colIndex++;
        }
        $headerStyleColab = $colaborSheet->getStyle("A2:{$lastColabCol}2");
        $headerStyleColab->getFont()->setBold(true);
        $headerStyleColab->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('EFEFEF');

        $colaborSheet->freezePane('A3');

        // Fila 3+ — datos de colaboradores
        $rowIndex = 3;
        $mapValues = array_values($collaboratorColumns);
        foreach ($collaborators as $collaborator) {
            $colIndex = 1;
            foreach ($mapValues as $resolver) {
                $value = is_callable($resolver) ? $resolver($collaborator) : ($collaborator->{$resolver} ?? '');
                $header = $collaboratorHeaders[$colIndex - 1] ?? null;
                $this->setSheetCellValue($colaborSheet, $colIndex, $rowIndex, $value, $header, $collaboratorTextHeaders);
                $colIndex++;
            }
            $rowIndex++;

            if ($rowIndex % 500 === 0) {
                gc_collect_cycles();
            }
        }

        $maxRowColab = $rowIndex - 1;

        // Auto-ancho columnas colaboradores
        for ($i = 1; $i <= count($collaboratorColumns); $i++) {
            $colaborSheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        // Bordes finos
        if ($maxRowColab >= 2) {
            $colaborSheet->getStyle("A2:{$lastColabCol}{$maxRowColab}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);
        }

        // Filtros automáticos
        $colaborSheet->setAutoFilter("A2:{$lastColabCol}{$maxRowColab}");

        // Activar primera hoja al abrir
        $spreadsheet->setActiveSheetIndex(0);

        // Descargar
        $safeTitle = Str::slug($title, '_');
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'collab_export_');
        $writer->save($tempFile);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        gc_collect_cycles();

        return response()->download($tempFile, "{$safeTitle}.xlsx")->deleteFileAfterSend(true);
    }

    private function setSheetCellValue(
        Worksheet $sheet,
        int $columnIndex,
        int $rowIndex,
        mixed $value,
        ?string $header,
        array $textHeaders = []
    ): void {
        $normalizedValue = $value ?? '';

        if ($header !== null && in_array($header, $textHeaders, true)) {
            $sheet->setCellValueExplicitByColumnAndRow(
                $columnIndex,
                $rowIndex,
                (string) $normalizedValue,
                DataType::TYPE_STRING
            );

            return;
        }

        $sheet->setCellValueByColumnAndRow($columnIndex, $rowIndex, $normalizedValue);
    }
}
