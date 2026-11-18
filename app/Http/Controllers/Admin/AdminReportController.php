<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\BuildAdminReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminReportRequest;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    public function __invoke(AdminReportRequest $request, BuildAdminReport $buildAdminReport): Response
    {
        return Inertia::render('admin/Report', [
            'from' => $request->from()->toDateString(),
            'to' => $request->to()->toDateString(),
            'report' => $buildAdminReport->handle($request->from(), $request->to()),
        ]);
    }
}
