<?php

namespace App\Domain\Exports\Payroll;

use App\Domain\Employees\VemaNumbers;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * „Vema – zrážky zo mzdy“ — PROVISIONAL (docs/DECISIONS.md, 2026-11-16).
 *
 * Roman will provide the real sample (today payroll imports into Vema the
 * „ZS – podklady pre vyúčtovanie“ XLSX exported from Pramen, with personal numbers as
 * plain numbers, 003 → 3). Until then the columns are the provisional ones from
 * docs/ATTENDANCE.md §7. THE WHOLE LAYOUT IS columns() + write() below: adapting to the
 * real format should only touch this class (and its test).
 */
final class VemaFormat implements PayrollFormat
{
    public function key(): string
    {
        return 'vema';
    }

    public function label(): string
    {
        return 'Formát Vema – predbežný';
    }

    public function isProvisional(): bool
    {
        return true;
    }

    /** Header → value. @return array<string, callable(PayrollRow): (int|float|string|null)> */
    public static function columns(): array
    {
        return [
            'vema_cislo' => fn (PayrollRow $r) => VemaNumbers::export($r->employee->vema_cislo), // number, no leading zeros
            'priezvisko' => fn (PayrollRow $r) => $r->employee->last_name,
            'meno' => fn (PayrollRow $r) => $r->employee->first_name,
            'stredisko_cislo' => fn (PayrollRow $r) => (string) $r->employee->stredisko_cislo,
            'zamestnanecka_ks' => fn (PayrollRow $r) => $r->employeeMeals,
            'dokupovana_ks' => fn (PayrollRow $r) => $r->extraMeals,
            'povinna_ks' => fn (PayrollRow $r) => $r->mandatoryMeals,
            'suma_eur' => fn (PayrollRow $r) => round($r->totalCents / 100, 2),
        ];
    }

    public function validate(PayrollData $data): array
    {
        return $data->rows
            ->filter(fn (PayrollRow $r) => VemaNumbers::export($r->employee->vema_cislo) === null)
            ->map(fn (PayrollRow $r) => $r->employee->fullNameReversed().' (osobné č. '.$r->employee->osobne_cislo.') nemá Vema číslo.')
            ->values()->all();
    }

    public function fileName(string $month): string
    {
        return 'vema_zrazky_'.$month.'.xlsx';
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function write(PayrollData $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vema').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Vema '.$data->month);

        $columns = self::columns();
        $writer->addRow(Row::fromValues(array_keys($columns), (new Style)->setFontBold()));
        $money = (new Style)->setFormat('0.00');
        foreach ($data->rows->sortBy(fn (PayrollRow $r) => VemaNumbers::export($r->employee->vema_cislo)) as $row) {
            $cells = [];
            foreach ($columns as $header => $value) {
                $v = $value($row);
                $cells[] = $header === 'suma_eur' ? Cell::fromValue($v, $money) : Cell::fromValue($v);
            }
            $writer->addRow(new Row($cells));
        }
        $writer->close();

        return $path;
    }
}
