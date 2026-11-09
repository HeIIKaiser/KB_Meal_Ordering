<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Kitchen\DailyOverview;
use App\Domain\Kitchen\DailyOverviewXlsx;
use App\Http\Controllers\Controller;
use App\Models\MealSlot;
use App\Support\LocalTime;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/** Kitchen daily list as print page, PDF and XLSX (kitchen, admin, payroll). */
class KitchenExportController extends Controller
{
    public function print(Request $request): Response
    {
        $o = $this->overview($request);

        return response()->view('kitchen.print', [
            'o' => $o,
            'printedAt' => LocalTime::now()->format('j. n. Y H:i'),
            'autoPrint' => $request->boolean('tlac'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function pdf(Request $request): Response
    {
        $o = $this->overview($request);

        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setChroot(resource_path('views'));
        $options->setTempDir(storage_path('app/private'));
        $options->setFontCache(storage_path('app/private'));

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('kitchen.pdf', ['o' => $o, 'pdf' => true, 'printedAt' => LocalTime::now()->format('j. n. Y H:i')])->render(), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$o->fileBase().'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function xlsx(Request $request): BinaryFileResponse
    {
        $o = $this->overview($request);
        $path = app(DailyOverviewXlsx::class)->write($o);

        return response()->download($path, $o->fileBase().'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ])->deleteFileAfterSend();
    }

    private function overview(Request $request): DailyOverview
    {
        $data = $request->validate([
            'datum' => ['required', 'date_format:Y-m-d'],
            'jedlo' => ['required', 'string', 'max:32'],
        ]);

        $slot = MealSlot::query()->where('code', $data['jedlo'])->firstOrFail();

        return DailyOverview::for($data['datum'], $slot);
    }
}
