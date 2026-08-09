<?php

namespace QCH\Core\Http\Controllers;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReportExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadingRow, WithHeadings, WithMapping, WithStyles
{
    protected $collection;

    protected $headers;

    protected $map;

    protected $title;

    protected $totals;

    protected $totalsColumns;

    public function setTotals($totals, $columns)
    {
        $this->totals = $totals;
        $this->totalsColumns = $columns;
        \Log::info('ReportExport::setTotals called', [
            'totals' => $this->totals,
            'columns_count' => count($this->totalsColumns),
        ]);
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold text.
            1 => ['font' => ['bold' => true, 'size' => 15]],
            2 => ['font' => ['bold' => true]],
        ];

        $count = count($this->headers);
        $columns = [];

        for ($i = 1; $i <= $count; $i++) {
            $columns['B'.$i] = ['font' => ['bold' => true]];
        }

        return $columns;

        /* return [
            // Style the first row as bold text.
            1    => ['font' => ['bold' => true]],

            // Styling a specific cell by coordinate.
            'B2' => ['font' => ['italic' => true]],

            // Styling an entire column.
            'C'  => ['font' => ['size' => 16]],
        ]; */
    }

    public function __construct() {}

    public function setCollection($collection)
    {
        $this->data = $collection;
    }

    public function setHeadings($headers)
    {
        $this->headers = [$this->title, $headers];
    }

    public function setTitle($title)
    {
        $this->title = [
            '       ',
            $title,
        ];
    }

    public function collection()
    {
        return $this->data;
    }

    public function headings(): array
    {
        return $this->headers;
    }

    public function setMap($map): array
    {
        $this->map = $map;

        return $map;
    }

    /**
     * @var Invoice
     */
    public function map($row): array
    {
        $generatedColData = [];
        foreach ($this->map as $colName => $colData) {
            if (is_object($colData)) {
                $generatedColData[] = $colData($row);
            } else {
                $generatedColData[] = $row->{$colData};
            }
        }

        return $generatedColData;
    }

    public function drawings()
    {
        $drawing = new Drawing;
        $drawing->setName('logo');
        $drawing->setDescription('logo-arke');
        $drawing->setPath(public_path('logo-siarke.png'));
        $drawing->setHeight(70);
        $drawing->setOffsetX(0);
        $drawing->setOffsetY(0);
        $drawing->setCoordinates('A1');

        return $drawing;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->getRowDimension('1')->setRowHeight(50);
                $sheet->getColumnDimension('A')->setWidth(30);
                $position_last = count($this->headings()[1]);
                $column = Coordinate::stringFromColumnIndex($position_last);
                $cells = "B1:{$column}1";

                $sheet->getStyle("A1:{$column}1")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('F2F2F2');
                $sheet->mergeCells($cells);
                $sheet->getStyle($cells)->getFont()->setBold(true);
                $sheet->getStyle($cells)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle($cells)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                // Agregar fila de totales si existen
                \Log::info('AfterSheet event - Checking totals', [
                    'has_totals' => ! empty($this->totals),
                    'has_columns' => ! empty($this->totalsColumns),
                    'totals' => $this->totals ?? null,
                    'columns' => $this->totalsColumns ?? null,
                ]);

                if ($this->totals && $this->totalsColumns && is_array($this->totals)) {
                    // Obtener la última fila con datos (el título está en la fila 1, headers en fila 2, datos empiezan en fila 3)
                    $lastRow = $sheet->getHighestRow();
                    $totalsRow = $lastRow + 1;

                    \Log::info('Adding totals row to Excel', [
                        'lastRow' => $lastRow,
                        'totalsRow' => $totalsRow,
                        'totals' => $this->totals,
                        'columns_count' => count($this->totalsColumns),
                    ]);

                    // Construir la fila de totales basándose en las columnas
                    $columnIndex = 1;

                    foreach ($this->totalsColumns as $colName) {
                        $colLetter = Coordinate::stringFromColumnIndex($columnIndex);
                        $cellAddress = $colLetter.$totalsRow;

                        // Mapear valores según el nombre de la columna
                        if ($colName === 'Folio') {
                            $sheet->setCellValue($cellAddress, 'TOTALES');
                        } elseif ($colName === 'Cliente') {
                            $sheet->setCellValue($cellAddress, $this->totals['count'].' crédito'.($this->totals['count'] !== 1 ? 's' : ''));
                        } elseif ($colName === 'Monto Total a Recuperar') {
                            // Totales de recuperación
                            $sheet->setCellValue($cellAddress, '$'.number_format($this->totals['totalDebt'], 2));
                        } elseif ($colName === 'Monto Recuperado') {
                            // Totales de recuperación
                            $sheet->setCellValue($cellAddress, '$'.number_format($this->totals['totalRecovered'], 2));
                        } elseif ($colName === '% Recuperado') {
                            // Totales de recuperación
                            $sheet->setCellValue($cellAddress, number_format($this->totals['overallRecoveryPercent'], 1).'%');
                        } elseif ($colName === 'Días de Atraso') {
                            // Totales de recuperación
                            $sheet->setCellValue($cellAddress, $this->totals['totalDelayedDays']);
                        } elseif ($colName === 'Monto') {
                            // Totales de colocación
                            $sheet->setCellValue($cellAddress, '$'.number_format($this->totals['totalAmount'] ?? 0, 2));
                        } elseif ($colName === 'Plazo') {
                            // Totales de colocación
                            $sheet->setCellValue($cellAddress, number_format($this->totals['avgTerms'] ?? 0, 1).' meses');
                        } elseif ($colName === 'Tasa de Interés') {
                            // Totales de colocación
                            $sheet->setCellValue($cellAddress, number_format($this->totals['avgInterestRate'] ?? 0, 2).'%');
                        } else {
                            // Para Promotor/Cobrador, Sucursal, Estatus, Fecha de otorgamiento: dejar vacío
                            $sheet->setCellValue($cellAddress, '');
                        }

                        $columnIndex++;
                    }

                    // Estilizar la fila de totales
                    $lastColumn = Coordinate::stringFromColumnIndex(count($this->totalsColumns));
                    $totalsRange = "A{$totalsRow}:{$lastColumn}{$totalsRow}";

                    $sheet->getStyle($totalsRange)
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('E5E5E5'); // Fondo gris claro

                    $sheet->getStyle($totalsRange)->getFont()->setBold(true);
                    $sheet->getStyle($totalsRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                    $sheet->getStyle($totalsRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                    \Log::info('Totals row added successfully', ['range' => $totalsRange]);
                } else {
                    \Log::warning('Totals row NOT added', [
                        'has_totals' => ! empty($this->totals),
                        'has_columns' => ! empty($this->totalsColumns),
                        'totals_is_array' => is_array($this->totals ?? null),
                    ]);
                }
            },
        ];
    }
}
