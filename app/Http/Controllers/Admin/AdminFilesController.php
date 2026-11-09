<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Backup\DatabaseBackup;
use App\Domain\Employees\EmployeeImport;
use App\Domain\Employees\PinIssuer;
use App\Domain\Menus\MenuImport;
use App\Domain\Ordering\PriceResolver;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\MealSlot;
use App\Models\PriceType;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\Settings;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/** Plain (non-Filament) staff pages: printable access slips and import templates. */
class AdminFilesController extends Controller
{
    /**
     * A4 sheet of access slips with each employee's CURRENT PIN (nothing is changed).
     *   /admin/listky?zamestnanci=12,15,18
     */
    public function slips(Request $request, PinIssuer $issuer): Response
    {
        $ids = collect(explode(',', (string) $request->query('zamestnanci')))
            ->map(fn ($id) => (int) trim($id))->filter()->unique()->take(1000)->values();

        $employees = Employee::query()->whereIn('id', $ids)->alphabetical()->get();
        $slips = $employees->isEmpty() ? [] : $issuer->slips($employees, Auth::guard('web')->user())->all();
        $url = rtrim(config('app.url'), '/').'/';

        $qr = (new QRCode(new QROptions([
            'outputType' => 'svg',
            'outputBase64' => true,
            'eccLevel' => QRCode::ECC_M,
            'addQuietzone' => true,
        ])))->render($url);

        $small = $request->query('format') === 'male';

        return response()
            ->view($small ? 'admin.slips-small' : 'admin.slips', [
                'slips' => $slips,
                'url' => $url,
                // shown in big letters for PC users: full address incl. https://, no trailing slash
                'displayUrl' => rtrim($url, '/'),
                'qr' => $qr,
                'ids' => $ids->implode(','),
                'info' => $small ? null : $this->orderingInfo(),
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Live rules for the instructions on the A4 login sheet, so the sheet stays
     * correct when cutoffs or prices change.
     *
     * @return array{cutoffs: list<string>, horizon: int, prices: list<string>}
     */
    private function orderingInfo(): array
    {
        $today = LocalTime::today();
        $resolver = app(PriceResolver::class);

        return [
            'cutoffs' => MealSlot::query()->active()->ordered()->get()
                ->map(fn ($s) => $s->name.' do '.substr($s->cutoff_time, 0, 5).' '.match ($s->cutoff_days_before) {
                    0 => 'v ten istý deň',
                    1 => 'deň vopred',
                    default => $s->cutoff_days_before.' dni vopred',
                })->all(),
            'horizon' => app(Settings::class)->orderingHorizonDays(),
            'prices' => PriceType::query()->orderBy('sort')->get()
                ->map(fn ($t) => [$t, $resolver->current($t, $today)?->amount_cents])
                ->filter(fn ($p) => $p[1] !== null)
                ->map(fn ($p) => Money::format($p[1]).' – '.($p[0]->isDonated() ? 'prvý obed a prvá večera v daný deň ('.mb_strtolower($p[0]->name).')' : 'každé ďalšie jedlo v ten istý deň ('.mb_strtolower($p[0]->name).')'))
                ->values()->all(),
        ];
    }

    /** Download one backup file (admin only; audited). */
    public function backup(string $file, DatabaseBackup $backup): BinaryFileResponse
    {
        $path = $backup->find($file) ?? abort(404);
        activity('backups')->causedBy(Auth::guard('web')->user())->event('downloaded')->log('Záloha stiahnutá: '.$file);

        return response()->download($path, $file, ['Content-Type' => 'application/gzip', 'Cache-Control' => 'no-store, private']);
    }

    public function template(string $type): Response
    {
        match ($type) {
            'menu' => $this->authorizeRoles(Role::Admin, Role::Kitchen),
            'zamestnanci' => $this->authorizeRoles(Role::Admin),
            default => abort(404),
        };

        [$content, $file] = $type === 'menu'
            ? [MenuImport::templateCsv(), 'sablona_jedalny_listok.csv']
            : [EmployeeImport::templateCsv(), 'sablona_zamestnanci.csv'];

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
        ]);
    }

    private function authorizeRoles(Role ...$roles): void
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            abort(redirect('/admin/login'));
        }

        abort_unless($user->is_active && $user->hasRole(...$roles), 403);
    }
}
