<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Backup\DatabaseBackup;
use App\Domain\Employees\EmployeeImport;
use App\Domain\Menus\MenuImport;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/** Plain (non-Filament) staff pages: printable access slips and import templates. */
class AdminFilesController extends Controller
{
    /** A4 sheet of access slips. The plain PINs come from the session once (pull). */
    public function slips(Request $request): Response
    {
        $slips = $request->session()->pull('access_slips', []);
        $url = rtrim(config('app.url'), '/').'/';

        $qr = (new QRCode(new QROptions([
            'outputType' => 'svg',
            'outputBase64' => true,
            'eccLevel' => QRCode::ECC_M,
            'addQuietzone' => true,
        ])))->render($url);

        return response()
            ->view('admin.slips', ['slips' => $slips, 'url' => $url, 'qr' => $qr])
            ->header('Cache-Control', 'no-store, private');
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
