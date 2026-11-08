<?php

namespace App\Http\Controllers;

use App\Services\Statistics\OrpReport;
use Illuminate\Http\Response;

class OrpReportController extends Controller
{
    public function csv(string $month): Response
    {
        $date = OrpReport::month($month);

        return response(OrpReport::csv($date), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="recherches-emploi-'.$date->format('Y-m').'.csv"',
        ]);
    }

    public function print(string $month): Response
    {
        $date = OrpReport::month($month);

        return response()->view('orp.print', [
            'month' => ucfirst($date->locale('fr')->translatedFormat('F Y')),
            'rows' => OrpReport::rows($date),
            'columns' => OrpReport::COLUMNS,
            'name' => auth()->user()?->name,
        ]);
    }
}
