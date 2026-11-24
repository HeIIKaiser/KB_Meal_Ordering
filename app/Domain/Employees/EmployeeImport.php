<?php

namespace App\Domain\Employees;

use App\Domain\Tickets\EmployeeCalendar;
use App\Domain\Tickets\MealGroupService;
use App\Enums\PricingMode;
use App\Models\Employee;
use App\Models\MealGroup;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Spreadsheet\SheetReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Employee import from XLSX/CSV, upsert by osobné číslo. Never deletes;
 * optionally deactivates employees missing from the file.
 *
 * Recognised columns (header names: diacritics, case, dots and spaces ignored; unknown
 * columns such as "#" are ignored):
 *   osobne_cislo, priezvisko, meno, stredisko | oddelenie | Workplace, aktivny (áno/nie, 1/0),
 *   vema_cislo | Vema (digits), cislo_dochadzka | Dochádzka (WebVisitor number; "181-1" = 181),
 *   max_jedal_za_den (1, 2 or "-" = no cap), fakturacny_nazov, ico, dic, adresa, email,
 *   fakturacna_poznamka (billing, SZČO), skupina | Forma stravy (group name or one of its
 *   "Názvy v importe"; empty = no change).
 * Master sheet (Roman, 2026-11-24): without an osobne_cislo column the Vema number is the
 * osobné číslo (and login). "Name" is split by NameSplitter; "Workplace no." → stredisko_cislo;
 * "Name in attendance" is not stored, only cross-checked against the name.
 * A NEW osobné číslo with the name of an existing employee gets a warning: if a number
 * changed, change it in the admin first — the import matches by osobné číslo.
 * Group start: an employee without any group gets it from today; a change from one group to
 * another applies from the 1st of next month. apply() can override the date for all rows.
 */
class EmployeeImport
{
    private const ALIASES = [
        'osobne_cislo' => ['osobne_cislo', 'os_cislo', 'osobne_c', 'cislo', 'osc'],
        'full_name' => ['name', 'meno_a_priezvisko', 'priezvisko_a_meno', 'cele_meno', 'full_name', 'zamestnanec'],
        'last_name' => ['priezvisko', 'last_name'],
        'first_name' => ['meno', 'first_name', 'krstne_meno'],
        'department' => ['stredisko', 'oddelenie', 'department', 'nazov_strediska', 'workplace', 'pracovisko'],
        'stredisko_cislo' => ['stredisko_cislo', 'cislo_strediska', 'workplace_no', 'workplace_number', 'workplace_nr', 'stredisko_c', 'c_strediska', 'cost_centre', 'cost_center'],
        'is_active' => ['aktivny', 'aktivna', 'active', 'is_active'],
        'group' => ['skupina', 'skupina_stravnikov', 'group', 'forma_stravy', 'meal_form'],
        'vema_cislo' => ['vema_cislo', 'vema', 'cislo_vema', 'vema_no', 'vema_c'],
        'attendance_cislo' => ['cislo_dochadzka', 'dochadzka_cislo', 'cislo_v_dochadzke', 'attendance_cislo', 'dochadzka', 'dochadzka_no', 'dochadzka_c', 'attendance', 'attendance_no'],
        'attendance_name' => ['name_in_attendance', 'meno_v_dochadzke', 'meno_dochadzka', 'attendance_name'],
        'max_meals_per_day' => ['max_jedal_za_den', 'max_jedal', 'max_meals_per_day'],
        'billing_name' => ['fakturacny_nazov', 'billing_name'],
        'ico' => ['ico'],
        'dic' => ['dic'],
        'billing_address' => ['adresa', 'fakturacna_adresa', 'billing_address'],
        'billing_email' => ['email', 'fakturacny_email', 'billing_email'],
        'billing_note' => ['fakturacna_poznamka', 'billing_note'],
    ];

    public function __construct(
        private SheetReader $reader,
        private MealGroupService $groups,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<array{line: int, messages: list<string>}>, missing: list<array{id: int, name: string, osobne_cislo: string}>, warnings: list<array{line: int, message: string}>, unset: list<array{line: int, osobne_cislo: string, name: string}>, group_counts: array<string, int>, key_from_vema: bool, today: string, next_month: string}
     */
    public function preview(string $path, ?string $originalName = null): array
    {
        $existing = Employee::query()->with('groupMemberships.group.modes')->get()->keyBy('osobne_cislo');
        $groups = $this->groupsByImportKey();
        $attendanceOwners = $existing->filter(fn (Employee $e) => $e->attendance_cislo !== null)->mapWithKeys(fn (Employee $e) => [Employee::attendanceNumber($e->attendance_cislo) => $e->osobne_cislo]);
        $byName = $existing->groupBy(fn (Employee $e) => $this->nameKey($e->last_name, $e->first_name));
        $warnings = [];
        $vemaOwners = $existing->filter(fn (Employee $e) => $e->vema_cislo !== null)->mapWithKeys(fn (Employee $e) => [$e->vema_cislo => $e->osobne_cislo]);
        $today = LocalTime::today();
        $nextMonth = $today->startOfMonth()->addMonthNoOverflow();
        $rows = [];
        $errors = [];
        $seen = [];
        $unset = [];
        $groupCounts = [];
        $keyFromVema = false;

        foreach ($this->reader->read($path, $originalName) as ['line' => $line, 'values' => $values]) {
            [$v, $present] = $this->map($values);
            $messages = [];
            $rowWarnings = [];

            // Key: osobné číslo, or the Vema number when the file has no osobne_cislo column.
            $vemaKey = ! isset($present['osobne_cislo']) && isset($present['vema_cislo']);
            $keyFromVema = $keyFromVema || $vemaKey;
            $number = trim((string) ($vemaKey ? $v['vema_cislo'] : $v['osobne_cislo']));
            $employee = null;
            if ($number === '' || mb_strlen($number) > 32) {
                $messages[] = $vemaKey ? 'Chýba alebo je neplatné Vema číslo (= osobné číslo).' : 'Chýba alebo je neplatné osobné číslo.';
            } elseif ($vemaKey && ! preg_match('/^\d{1,16}$/', $number)) {
                $messages[] = 'Vema číslo môže obsahovať len číslice.';
            } else {
                $employee = $existing[$number] ?? null;
                if (! $employee && $vemaKey) {
                    // Created earlier with another osobné číslo ("0181"): match by Vema number, keep the login.
                    $norm = Employee::attendanceNumber($number);
                    $hits = $existing->filter(fn (Employee $e) => ($e->vema_cislo !== null && Employee::attendanceNumber($e->vema_cislo) === $norm)
                        || (ctype_digit($e->osobne_cislo) && Employee::attendanceNumber($e->osobne_cislo) === $norm));
                    if ($hits->count() > 1) {
                        $messages[] = "Vema číslo $number zodpovedá viacerým zamestnancom (".$hits->keys()->join(', ').'). Opravte to najprv v karte zamestnanca.';
                    } elseif ($employee = $hits->first()) {
                        $rowWarnings[] = "Vema číslo $number spárované so zamestnancom s osobným číslom {$employee->osobne_cislo} ({$employee->fullNameReversed()}). Osobné (prihlasovacie) číslo sa nemení.";
                        $number = $employee->osobne_cislo;
                    }
                }
                if (isset($seen[$number])) {
                    $messages[] = ($vemaKey ? 'Vema' : 'Osobné')." číslo $number je v súbore viackrát (aj riadok {$seen[$number]}).";
                }
                $seen[$number] = $line;
            }

            // Name: separate columns win; otherwise one "Name" column ("Priezvisko Meno").
            $data = [];
            $split = null;
            $last = $this->clean($v['last_name']);
            $first = $this->clean($v['first_name']);
            if (($last === null || $first === null) && ($full = $this->clean($v['full_name'])) !== null) {
                $split = NameSplitter::split($full);
                if ($split['first_name'] === null) {
                    $messages[] = "Meno „{$full}“ sa nedá rozdeliť – zapíšte ho ako „Priezvisko Meno“.";
                } else {
                    [$last, $first] = [$split['last_name'], $split['first_name']];
                    if ($split['words'] >= 3) {
                        $rowWarnings[] = "Meno „{$full}“ má viac slov – skontrolujte rozdelenie: priezvisko „{$last}“, meno „{$first}“.";
                    }
                }
            }
            $data = array_filter([
                'last_name' => $last,
                'first_name' => $first,
                'department' => $this->clean($v['department']),
            ], fn ($x) => $x !== null);

            if (! $employee && $messages === [] && (! isset($data['last_name']) || ! isset($data['first_name']))) {
                $messages[] = 'Nový zamestnanec musí mať meno aj priezvisko.';
            }

            if (($centre = $this->clean($v['stredisko_cislo'])) !== null) {
                preg_match('/^\d{1,16}$/', $centre) ? $data['stredisko_cislo'] = $centre : $messages[] = 'Číslo strediska môže obsahovať len číslice.';
            }

            if ($v['is_active'] !== null && $v['is_active'] !== '') {
                $active = $this->bool($v['is_active']);
                if ($active === null) {
                    $messages[] = 'Stĺpec „aktívny“ musí byť áno/nie alebo 1/0.';
                } else {
                    $data['is_active'] = $active;
                }
            } elseif (! $employee) {
                $data['is_active'] = true;
            }

            $vema = $this->clean($v['vema_cislo']);
            if ($vema !== null && ! ($vemaKey && $messages !== [])) {
                if (! preg_match('/^\d{1,16}$/', $vema)) {
                    $messages[] = 'Vema číslo môže obsahovať len číslice.';
                } elseif (($other = $vemaOwners[$vema] ?? null) && $other !== $number) {
                    $messages[] = "Vema číslo $vema už má zamestnanec $other.";
                } else {
                    $data['vema_cislo'] = $vema;
                }
            }
            if (($att = $this->clean($v['attendance_cislo'])) !== null) {
                // WebVisitor shows "osobné číslo-číslo pracovného pomeru" (181-1): store 181.
                if (! preg_match('/^(\d{1,16})(?:-\d{1,4})?$/', $att, $m)) {
                    $messages[] = 'Číslo v dochádzke musí byť číslo, napr. 181 alebo 181-1.';
                } elseif (($other = $attendanceOwners[Employee::attendanceNumber($m[1])] ?? null) && $other !== $number) {
                    $messages[] = "Číslo v dochádzke {$m[1]} už má zamestnanec $other.";
                } else {
                    $data['attendance_cislo'] = $m[1];
                }
            }
            $max = $this->clean($v['max_meals_per_day']);
            if ($max !== null) {
                if (in_array($max, ['-', 'bez', 'bez obmedzenia'], true)) {
                    $data['max_meals_per_day'] = null;
                } elseif (in_array($max, ['1', '2'], true)) {
                    $data['max_meals_per_day'] = (int) $max;
                } else {
                    $messages[] = 'Max. jedál za deň musí byť 1, 2 alebo „-“ (bez obmedzenia).';
                }
            }

            foreach (['billing_name' => 150, 'billing_address' => 255, 'billing_note' => 255] as $f => $max) {
                if (($val = $this->clean($v[$f])) !== null) {
                    $data[$f] = mb_substr($val, 0, $max);
                }
            }
            if (($ico = $this->clean($v['ico'])) !== null) {
                preg_match('/^\d{6,8}$/', $ico) ? $data['ico'] = $ico : $messages[] = 'IČO má 6 až 8 číslic.';
            }
            if (($dic = $this->clean($v['dic'])) !== null) {
                preg_match('/^\d{10}$/', $dic) ? $data['dic'] = $dic : $messages[] = 'DIČ má 10 číslic.';
            }
            if (($mail = $this->clean($v['billing_email'])) !== null) {
                filter_var($mail, FILTER_VALIDATE_EMAIL) ? $data['billing_email'] = $mail : $messages[] = 'Neplatný e-mail pre faktúry.';
            }

            $group = null;
            if (($groupName = $this->clean($v['group'])) !== null) {
                $group = $groups[MealGroup::importKey($groupName)] ?? null;
                if (! $group) {
                    $messages[] = "Neznáma forma stravy / skupina „{$groupName}“. Vytvorte skupinu stravníkov alebo jej doplňte „Názvy v importe“.";
                }
            }

            if ($messages !== []) {
                $errors[] = ['line' => $line, 'messages' => $messages];

                continue;
            }

            $changes = [];
            if ($employee) {
                foreach ($data as $field => $value) {
                    if ($employee->{$field} !== $value && ! ($value === null && $employee->{$field} === null)) {
                        $changes[$field] = ['old' => $employee->{$field}, 'new' => $value];
                    }
                }
            }

            // Group: none yet → from today; one group → another: from the 1st of next month.
            $calendar = $employee ? EmployeeCalendar::fromMemberships($employee, $employee->groupMemberships) : null;
            $hasGroup = $employee && $this->hasGroup($employee, $calendar, $today);
            $groupChange = null;
            $finalGroup = null;
            $finalFrom = $today;
            if ($group) {
                $from = $hasGroup ? $nextMonth : $today;
                $old = $calendar?->groupOn($from);
                if ($old?->id !== $group->id) {
                    $groupChange = ['id' => $group->id, 'name' => $group->name, 'from' => $from->toDateString(), 'rule' => $hasGroup ? 'change' : 'first'];
                    $changes['group'] = ['old' => $old?->name, 'new' => $group->name];
                }
                $finalGroup = $group;
                $finalFrom = $from;
            } elseif ($hasGroup) {
                $finalGroup = $calendar->groupOn($today) ?? $employee->groupMemberships->filter(fn ($m) => $m->meal_group_id)->sortBy('effective_from')->last()?->group;
            }

            $name = trim(($data['last_name'] ?? $employee?->last_name).' '.($data['first_name'] ?? $employee?->first_name));
            $rows[] = [
                'line' => $line,
                'osobne_cislo' => $number,
                'data' => $data,
                'action' => $employee ? ($changes ? 'update' : 'unchanged') : 'create',
                'changes' => $changes,
                'name' => $name,
                'split' => $split && $split['first_name'] !== null ? ['last_name' => $split['last_name'], 'first_name' => $split['first_name']] : null,
                'group' => $groupChange,
            ];

            if ($finalGroup) {
                $groupCounts[$finalGroup->name] = ($groupCounts[$finalGroup->name] ?? 0) + 1;
                $attendance = array_key_exists('attendance_cislo', $data) ? $data['attendance_cislo'] : $employee?->attendance_cislo;
                $mode = $finalGroup->modeOn($finalFrom);
                if ($attendance === null && in_array($mode, [PricingMode::Tickets, PricingMode::Mandatory], true)) {
                    $rowWarnings[] = "{$name}: skupina „{$finalGroup->name}“ ({$mode->label()}) potrebuje dochádzku, ale chýba číslo v dochádzke – použije sa osobné číslo {$number}.";
                }
            } else {
                $unset[] = ['line' => $line, 'osobne_cislo' => $number, 'name' => $name];
            }

            // "Name in attendance" is not stored: it only catches a wrong Dochádzka number.
            if (($attName = $this->clean($v['attendance_name'])) !== null && ($lastName = $data['last_name'] ?? $employee?->last_name) && ($firstName = $data['first_name'] ?? $employee?->first_name)
                && ! NameSplitter::matchesAttendanceName($attName, $lastName, $firstName)) {
                $rowWarnings[] = "Meno v dochádzke „{$attName}“ nesedí s menom {$name} – skontrolujte číslo v dochádzke.";
            }

            if (! $employee && ($same = $byName[$this->nameKey($data['last_name'] ?? '', $data['first_name'] ?? '')] ?? null)) {
                $rowWarnings[] = "Nové osobné číslo $number, ale rovnaké meno má zamestnanec "
                    .$same->map(fn ($e) => $e->osobne_cislo)->join(', ').'. Ak sa mu zmenilo osobné číslo, zmeňte ho najprv v karte zamestnanca – inak vznikne nový človek.';
            }
            foreach ($rowWarnings as $w) {
                $warnings[] = ['line' => $line, 'message' => $w];
            }
        }

        $missing = $existing->filter(fn (Employee $e) => $e->is_active && ! isset($seen[$e->osobne_cislo]))
            ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->fullNameReversed(), 'osobne_cislo' => $e->osobne_cislo])
            ->sortBy('name')->values()->all();
        ksort($groupCounts, SORT_LOCALE_STRING);

        return [
            'rows' => $rows, 'errors' => $errors, 'missing' => $missing, 'warnings' => $warnings,
            'unset' => $unset, 'group_counts' => $groupCounts, 'key_from_vema' => $keyFromVema,
            'today' => $today->toDateString(), 'next_month' => $nextMonth->toDateString(),
        ];
    }

    /**
     * New employees get a PIN automatically. $groupFrom (optional) replaces the start date
     * of every group change in the file; default per row: today / 1st of next month.
     *
     * @return array{created: int, updated: int, deactivated: int, created_ids: list<int>, groups: int}
     */
    public function apply(array $preview, bool $deactivateMissing = false, ?User $by = null, ?string $groupFrom = null): array
    {
        $result = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'created_ids' => [], 'groups' => 0];

        DB::transaction(function () use ($preview, $deactivateMissing, $by, $groupFrom, &$result) {
            foreach ($preview['rows'] as $row) {
                $employee = null;
                if ($row['action'] === 'create') {
                    $employee = Employee::query()->create(['osobne_cislo' => $row['osobne_cislo'], ...$row['data']]);
                    $result['created_ids'][] = $employee->id;
                    $result['created']++;
                } elseif ($row['action'] === 'update') {
                    $employee = Employee::query()->where('osobne_cislo', $row['osobne_cislo'])->firstOrFail();
                    $employee->update($row['data']);
                    $result['updated']++;
                }
                if ($employee && ($row['group'] ?? null)) {
                    $this->groups->assign($employee, $row['group']['id'], $groupFrom ?: $row['group']['from'], $by);
                    $result['groups']++;
                }
            }

            if ($deactivateMissing) {
                foreach ($preview['missing'] as $m) {
                    Employee::query()->findOrFail($m['id'])->update(['is_active' => false]);
                    $result['deactivated']++;
                }
            }
        });

        return $result;
    }

    /** A group today or planned later ("forma stravy" is set up). */
    public static function hasGroup(Employee $employee, ?EmployeeCalendar $calendar = null, ?CarbonImmutable $today = null): bool
    {
        $today ??= LocalTime::today();
        $calendar ??= EmployeeCalendar::for($employee);
        $memberships = $employee->relationLoaded('groupMemberships') ? $employee->groupMemberships : $calendar->memberships();

        return $calendar->groupOn($today) !== null
            || $memberships->contains(fn ($m) => $m->meal_group_id !== null && $m->effective_from->toDateString() > $today->toDateString());
    }

    /** @return array<string, MealGroup> import key (name or "Názvy v importe") → group */
    private function groupsByImportKey(): array
    {
        $out = [];
        foreach (MealGroup::query()->with('modes')->orderBy('id')->get() as $group) {
            foreach ($group->importKeys() as $key) {
                $out[$key] ??= $group;
            }
        }

        return $out;
    }

    /** Master-sheet format (made-up people); the older osobne_cislo/priezvisko/meno columns still work. */
    public static function templateCsv(): string
    {
        $rows = [
            ['Vema', 'Name', 'Workplace no.', 'Workplace', 'Dochádzka', 'Name in attendance', 'Forma stravy'],
            ['901', 'Nováková Mária', '11', 'Administratíva', '901-1', 'Nováková M.', 'Povinná - len obed'],
            ['902', 'Ing. Kováč Peter', '21', 'Kuchyňa', '902-1', 'Kováč P.', 'Povinná - obed+večera'],
            ['903', 'Horváth Varga Zuzana', '12', 'Beauty', '903-1', 'Horváth Varga Z.', 'Objednáva si'],
            ['904', 'Bielik Ján', '41', 'Plniareň', '', '', ''],
        ];

        return "\xEF\xBB\xBF".implode('', array_map(fn ($r) => implode(';', $r)."\r\n", $rows));
    }

    /** @return array{0: array<string, mixed>, 1: array<string, true>} values by field, fields whose column exists */
    private function map(array $values): array
    {
        $out = [];
        $present = [];
        foreach (self::ALIASES as $field => $aliases) {
            $out[$field] = null;
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $values)) {
                    $out[$field] = $values[$alias];
                    $present[$field] = true;
                    break;
                }
            }
        }

        return [$out, $present];
    }

    private function nameKey(string $last, string $first): string
    {
        return Str::ascii(mb_strtolower(trim($last).' '.trim($first)));
    }

    private function clean(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, 100);
    }

    private function bool(mixed $v): ?bool
    {
        $v = Str::ascii(mb_strtolower(trim((string) $v)));

        return match ($v) {
            '1', 'ano', 'a', 'yes', 'true', 'x' => true,
            '0', 'nie', 'n', 'no', 'false' => false,
            default => null,
        };
    }
}
