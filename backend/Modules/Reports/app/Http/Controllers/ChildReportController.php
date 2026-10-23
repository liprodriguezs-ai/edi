<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Reports\Exports\ChildReportExport;
use Modules\Reports\Http\Requests\ChildReportRequest;
use Modules\Reports\Services\ChildReportService;
use Modules\Reports\Services\ChildReportSheet;
use Modules\Reports\Services\ImpactAxisCatalog;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Per-child reports (S4/S5): Concentrado EDI and Reporte general. Not Orion
 * resources — each row is a projection over several modules. Listing is
 * gated by `read.<entity>`, the Excel export by `report.<entity>` (routes).
 * Responses use the Orion/Laravel paginated envelope (data, links, meta).
 */
class ChildReportController
{
    public function __construct(
        private readonly ChildReportService $service,
        private readonly ChildReportSheet $sheet,
        private readonly ImpactAxisCatalog $axes,
    ) {}

    public function ediConcentrate(ChildReportRequest $request): JsonResponse
    {
        $paginator = $this->service->paginateConcentrate($request->filters(), $this->perPage($request), $this->page($request));

        return JsonResource::collection($paginator)
            ->additional(['meta' => ['impact_axes' => $this->axes->publicAxes()]])
            ->response();
    }

    public function ediConcentrateExport(ChildReportRequest $request): BinaryFileResponse
    {
        $lines = [];
        foreach ($this->service->concentrateRows($request->filters()) as $index => $row) {
            $lines[] = $this->sheet->concentrateLine($index + 1, $row);
        }

        return Excel::download(new ChildReportExport($this->sheet->concentrateHeadings(), $lines), 'concentrado_edi.xlsx');
    }

    public function general(ChildReportRequest $request): JsonResponse
    {
        $paginator = $this->service->paginateGeneral($request->filters(), $this->perPage($request), $this->page($request));

        return JsonResource::collection($paginator)->response();
    }

    public function generalExport(ChildReportRequest $request): BinaryFileResponse
    {
        $lines = [];
        foreach ($this->service->generalRows($request->filters()) as $index => $row) {
            $lines[] = $this->sheet->generalLine($index + 1, $row);
        }

        return Excel::download(new ChildReportExport($this->sheet->generalHeadings(), $lines), 'reporte_general.xlsx');
    }

    private function perPage(ChildReportRequest $request): int
    {
        return (int) ($request->validated('limit') ?? 20);
    }

    private function page(ChildReportRequest $request): int
    {
        return (int) ($request->validated('page') ?? 1);
    }
}
