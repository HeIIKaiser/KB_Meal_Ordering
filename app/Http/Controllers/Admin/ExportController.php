<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Exports\Invoice\InvoiceData;
use App\Domain\Exports\Invoice\InvoiceFormats;
use App\Domain\Exports\MonthlyData;
use App\Domain\Exports\MonthlyMatrixXlsx;
use App\Domain\Exports\OrderListExport;
use App\Domain\Exports\Payroll\PayrollData;
use App\Domain\Exports\Payroll\PayrollFormats;
use App\Filament\Pages\Exports;
use App\Http\Controllers\Controller;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Payroll exports (payroll + admin): monthly matrix and flat order list. */
class ExportController extends Controller
{
    public function monthly(Request $request): BinaryFileResponse
    {
        $data = $request->validate([
            'mesiac' => ['required', 'date_format:Y-m'],
            'po_jedlach' => ['nullable', 'boolean'],
            'vsetci' => ['nullable', 'boolean'],
        ], ['mesiac.*' => 'Zadajte mesiac v tvare RRRR-MM.']);

        $monthly = MonthlyData::for($data['mesiac'], includeWithoutOrders: $request->boolean('vsetci'));
        $path = app(MonthlyMatrixXlsx::class)->write($monthly, splitBySlot: $request->boolean('po_jedlach'));

        $this->audit('Export: mesačný prehľad '.$data['mesiac']);

        return $this->download($path, MonthlyMatrixXlsx::fileName($monthly), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function list(Request $request): BinaryFileResponse
    {
        $data = $request->validate([
            'od' => ['required', 'date_format:Y-m-d'],
            'do' => ['required', 'date_format:Y-m-d', 'after_or_equal:od'],
            'format' => ['required', 'in:xlsx,csv'],
        ], [
            'od.*' => 'Zadajte dátum od.',
            'do.after_or_equal' => 'Dátum do musí byť rovnaký alebo neskorší ako dátum od.',
            'do.*' => 'Zadajte dátum do.',
        ]);

        $from = LocalTime::date($data['od']);
        $to = LocalTime::date($data['do']);
        abort_if($from->diffInDays($to) > OrderListExport::MAX_DAYS, 422, 'Obdobie môže mať najviac rok.');

        $export = app(OrderListExport::class);
        $path = $data['format'] === 'csv' ? $export->csv($from, $to) : $export->xlsx($from, $to);

        $this->audit('Export: zoznam objednávok '.$from->toDateString().' – '.$to->toDateString().' ('.$data['format'].')');

        return $this->download(
            $path,
            OrderListExport::fileName($from, $to, $data['format']),
            $data['format'] === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
    }

    /**
     * Payroll export in a pluggable format (Vema first). Missing Vema numbers block it;
     * an open month needs an explicit „odhad“ confirmation.
     */
    public function payroll(Request $request): BinaryFileResponse|RedirectResponse
    {
        $data = $request->validate([
            'mesiac' => ['required', 'date_format:Y-m'],
            'format' => ['required', 'in:'.implode(',', array_keys(PayrollFormats::all()))],
            'odhad' => ['nullable', 'boolean'],
        ], ['mesiac.*' => 'Zadajte mesiac v tvare RRRR-MM.', 'format.*' => 'Neznámy formát.']);

        $format = PayrollFormats::get($data['format']);
        $payroll = PayrollData::for($data['mesiac']);
        $back = redirect(Exports::getUrl().'#mzdy')->withInput();

        if ($problems = $format->validate($payroll)) {
            return $back->with('payroll_errors', $problems);
        }
        if (! $payroll->closed && ! $request->boolean('odhad')) {
            return $back->with('payroll_estimate', ['mesiac' => $data['mesiac'], 'format' => $data['format']]);
        }

        $path = $format->write($payroll);
        $this->audit('Export do miezd ('.$format->label().'): '.$data['mesiac'].($payroll->closed ? '' : ' – odhad'));

        return $this->download($path, $format->fileName($data['mesiac']), $format->contentType());
    }

    /** „Fakturácia“: invoiced people only (self-employed); open month needs the „odhad“ confirmation. */
    public function invoice(Request $request): BinaryFileResponse|RedirectResponse
    {
        $data = $request->validate([
            'mesiac' => ['required', 'date_format:Y-m'],
            'format' => ['required', 'in:'.implode(',', array_keys(InvoiceFormats::all()))],
            'odhad' => ['nullable', 'boolean'],
        ], ['mesiac.*' => 'Zadajte mesiac v tvare RRRR-MM.', 'format.*' => 'Neznámy formát.']);

        $format = InvoiceFormats::get($data['format']);
        $invoice = InvoiceData::for($data['mesiac']);
        if (! $invoice->closed && ! $request->boolean('odhad')) {
            return redirect(Exports::getUrl().'#fakturacia')->withInput()->with('invoice_estimate', ['mesiac' => $data['mesiac'], 'format' => $data['format']]);
        }

        $path = $format->write($invoice);
        $this->audit('Export: '.$format->label().' '.$data['mesiac'].($invoice->closed ? '' : ' – odhad'));

        return $this->download($path, $format->fileName($data['mesiac']), $format->contentType());
    }

    private function download(string $path, string $name, string $type): BinaryFileResponse
    {
        return response()->download($path, $name, ['Content-Type' => $type, 'Cache-Control' => 'no-store, private'])
            ->deleteFileAfterSend();
    }

    /** Exports contain personal data of all employees → who exported what is logged. */
    private function audit(string $what): void
    {
        activity('exports')->causedBy(Auth::guard('web')->user())->event('export')->log($what);
    }
}
