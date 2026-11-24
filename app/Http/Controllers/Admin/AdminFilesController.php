<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Backup\DatabaseBackup;
use App\Domain\Employees\EmployeeImport;
use App\Domain\Employees\PinIssuer;
use App\Domain\Employees\Slips\SlipLayout;
use App\Domain\Employees\Slips\SlipOptions;
use App\Domain\Employees\Slips\SlipSelection;
use App\Domain\Menus\MenuImport;
use App\Domain\Ordering\PriceResolver;
use App\Domain\Tickets\FinalTicketImport;
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
     * Access slips with each employee's CURRENT PIN (nothing is changed except slip_printed_at).
     *   /admin/listky?zamestnanci=12,15,18&zoradit=cislo&skupiny=stredisko&strana=1&format=male
     *   /admin/listky?vyber=<token>&…   (long selections, see SlipSelection)
     * Options: see SlipOptions (invalid → defaults). &zapamatat=1 stores them for this user.
     */
    public function slips(Request $request, PinIssuer $issuer, SlipLayout $layout): Response
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();
        $options = SlipOptions::fromArray($request->query());
        if ($request->boolean('zapamatat')) {
            $options->remember($user);
        }
        $ids = SlipSelection::resolve($request, $user);

        $employees = Employee::query()->whereIn('id', $ids)->with('groupMemberships.group')->get();
        $sections = $layout->sections($employees, $options);
        $ordered = collect($sections)->flatMap(fn ($s) => $s['employees']);
        $slips = $ordered->isEmpty() ? collect() : $issuer->slips($ordered, $user)->keyBy('employee_id');
        $sections = collect($sections)->map(fn ($s) => [
            'heading' => $s['heading'],
            'slips' => $s['employees']->map(fn (Employee $e) => [...$slips[$e->id], 'section' => $s['heading']])->all(),
        ])->filter(fn ($s) => $s['slips'] !== [])->values()->all();
        $url = rtrim(config('app.url'), '/').'/';

        $qr = (new QRCode(new QROptions([
            'outputType' => 'svg',
            'outputBase64' => true,
            'eccLevel' => QRCode::ECC_M,
            'addQuietzone' => true,
        ])))->render($url);

        $selection = $request->filled('vyber') ? ['vyber' => (string) $request->query('vyber')] : ['zamestnanci' => $ids->implode(',')];

        return response()
            ->view($options->small() ? 'admin.slips-small' : 'admin.slips', [
                'sections' => $sections,
                'count' => $slips->count(),
                'noPin' => $slips->whereNull('pin')->count(),
                'options' => $options,
                'selection' => $selection,
                'url' => $url,
                // shown in big letters for PC users: full address incl. https://, no trailing slash
                'displayUrl' => rtrim($url, '/'),
                'qr' => $qr,
                'info' => $options->small() ? null : $this->orderingInfo(),
                'company' => app(Settings::class)->companyName(),
                'appName' => app(Settings::class)->appName(),
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
            'prices' => PriceType::query()->standard()->orderBy('sort')->get()
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
            'zamestnanci', 'konecne-listky' => $this->authorizeRoles(Role::Admin),
            default => abort(404),
        };

        [$content, $file] = match ($type) {
            'menu' => [MenuImport::templateCsv(), 'sablona_jedalny_listok.csv'],
            'zamestnanci' => [EmployeeImport::templateCsv(), 'sablona_zamestnanci.csv'],
            'konecne-listky' => [FinalTicketImport::templateCsv(), 'sablona_konecne_listky.csv'],
        };

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
