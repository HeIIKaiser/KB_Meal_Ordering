<?php

namespace App\Domain\Employees\Slips;

use App\Domain\Tickets\EmployeeCalendar;
use App\Models\Employee;
use App\Models\MealGroup;
use App\Support\LocalTime;
use App\Support\NaturalSort;
use Collator;
use Illuminate\Support\Collection;

/**
 * Orders the employees for printing and splits them into sections (stredisko, group or
 * meal form) with a heading such as „Stredisko 14 – Stravovanie (56)“. Groups are the ones
 * valid today; people without one get the last section „Bez skupiny“.
 */
final class SlipLayout
{
    private Collator $collator;

    public function __construct()
    {
        $this->collator = new Collator('sk_SK');
    }

    /**
     * @param  Collection<int, Employee>  $employees  (groupMemberships.group loaded for group options)
     * @return list<array{heading: ?string, employees: Collection<int, Employee>}>
     */
    public function sections(Collection $employees, SlipOptions $options): array
    {
        $today = LocalTime::today();
        $rows = $employees->map(fn (Employee $e) => [
            'employee' => $e,
            'group' => EmployeeCalendar::fromMemberships($e, $e->groupMemberships)->groupOn($today),
        ]);
        $sorted = $rows->sort(fn ($a, $b) => $this->compare($a, $b, $options->sort))->values();

        if (! $options->grouped()) {
            return [['heading' => null, 'employees' => $sorted->pluck('employee')]];
        }

        return $sorted
            ->groupBy(fn ($r) => $this->sectionKey($r, $options->group))
            ->map(fn (Collection $members, string $key) => [
                'key' => $key,
                'sample' => $members->first(),
                'heading' => $this->heading($members->first(), $options->group).' ('.$members->count().')',
                'employees' => $members->pluck('employee')->values(),
            ])
            ->sort(fn ($a, $b) => $this->compareSections($a, $b, $options->group))
            ->map(fn ($s) => ['heading' => $s['heading'], 'employees' => $s['employees']])
            ->values()->all();
    }

    private function compare(array $a, array $b, string $sort): int
    {
        $ea = $a['employee'];
        $eb = $b['employee'];

        return match ($sort) {
            'cislo' => NaturalSort::compare($ea->osobne_cislo, $eb->osobne_cislo) ?: $this->byName($ea, $eb),
            'stredisko' => NaturalSort::compare($ea->stredisko_cislo, $eb->stredisko_cislo)
                ?: $this->text($ea->department, $eb->department) ?: $this->byName($ea, $eb),
            'skupina' => $this->text($a['group']?->name, $b['group']?->name) ?: $this->byName($ea, $eb),
            default => $this->byName($ea, $eb),
        };
    }

    private function byName(Employee $a, Employee $b): int
    {
        return $this->text($a->last_name, $b->last_name) ?: $this->text($a->first_name, $b->first_name)
            ?: NaturalSort::compare($a->osobne_cislo, $b->osobne_cislo);
    }

    /** Slovak alphabetical order, empty values last. */
    private function text(?string $a, ?string $b): int
    {
        $a = trim((string) $a);
        $b = trim((string) $b);
        if ($a === '' || $b === '') {
            return ($a === '') <=> ($b === '');
        }

        return (int) $this->collator->compare($a, $b);
    }

    private function sectionKey(array $row, string $group): string
    {
        $e = $row['employee'];

        return match ($group) {
            'stredisko' => trim((string) $e->stredisko_cislo) === '' && trim((string) $e->department) === ''
                ? '' : 'c:'.trim((string) $e->stredisko_cislo).'|d:'.trim((string) $e->department),
            default => (string) ($row['group']?->id ?? ''),
        };
    }

    private function heading(array $row, string $group): string
    {
        $e = $row['employee'];
        if ($group === 'stredisko') {
            $number = trim((string) $e->stredisko_cislo);
            $name = trim((string) $e->department);

            return match (true) {
                $number !== '' && $name !== '' => "Stredisko $number – $name",
                $number !== '' => "Stredisko $number",
                $name !== '' => "Stredisko $name",
                default => 'Bez strediska',
            };
        }
        $g = $row['group'];

        return match (true) {
            ! $g => 'Bez skupiny',
            $group === 'forma' => 'Forma stravy: '.self::mealForm($g),
            default => 'Skupina: '.$g->name,
        };
    }

    /** The name from the HR sheet (first "Názov v importe"), else the group name. */
    public static function mealForm(MealGroup $group): string
    {
        $alias = collect($group->import_aliases ?? [])->first(fn ($a) => is_string($a) && trim($a) !== '');

        return $alias ? trim($alias) : $group->name;
    }

    private function compareSections(array $a, array $b, string $group): int
    {
        // „Bez strediska“ / „Bez skupiny“ always last
        if (($a['key'] === '') !== ($b['key'] === '')) {
            return $a['key'] === '' ? 1 : -1;
        }
        $ea = $a['sample']['employee'];
        $eb = $b['sample']['employee'];

        return match ($group) {
            'stredisko' => NaturalSort::compare($ea->stredisko_cislo, $eb->stredisko_cislo) ?: $this->text($ea->department, $eb->department),
            'forma' => $this->text(self::mealForm($a['sample']['group']), self::mealForm($b['sample']['group'])),
            default => $this->text($a['sample']['group']->name, $b['sample']['group']->name),
        };
    }
}
