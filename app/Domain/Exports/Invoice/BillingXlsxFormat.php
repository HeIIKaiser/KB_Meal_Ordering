<?php

namespace App\Domain\Exports\Invoice;

use App\Support\Money;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * „Fakturácia“ for accounting — PROVISIONAL (docs/CATEGORIES.md §4): accounting issues the
 * invoices itself; the columns will follow their wishes. THE WHOLE LAYOUT IS summaryColumns(),
 * detailColumns() and write() in this class.
 *
 * Sheet Súhrn: one row per invoiced person. Sheet Detail: one row per meal (invoice attachment).
 */
final class BillingXlsxFormat implements InvoiceFormat
{
    public function key(): string
    {
        return 'fakturacia';
    }

    public function label(): string
    {
        return 'Fakturácia – predbežný formát';
    }

    public function isProvisional(): bool
    {
        return true;
    }

    /** @return array<string, callable(InvoiceRow): (int|float|string|null)> */
    public static function summaryColumns(): array
    {
        return [
            'osobne_cislo' => fn (InvoiceRow $r) => (string) $r->employee->osobne_cislo,
            'priezvisko' => fn (InvoiceRow $r) => $r->employee->last_name,
            'meno' => fn (InvoiceRow $r) => $r->employee->first_name,
            'fakturacny_nazov' => fn (InvoiceRow $r) => (string) $r->employee->billing_name,
            'ico' => fn (InvoiceRow $r) => (string) $r->employee->ico,
            'dic' => fn (InvoiceRow $r) => (string) $r->employee->dic,
            'adresa' => fn (InvoiceRow $r) => (string) $r->employee->billing_address,
            'email' => fn (InvoiceRow $r) => (string) $r->employee->billing_email,
            'poznamka' => fn (InvoiceRow $r) => (string) $r->employee->billing_note,
            'obedy_ks' => fn (InvoiceRow $r) => $r->lunches,
            'vecere_ks' => fn (InvoiceRow $r) => $r->dinners,
            'ostatne_ks' => fn (InvoiceRow $r) => $r->other,
            'jednotkova_cena' => fn (InvoiceRow $r) => implode(' / ', array_map(fn ($c) => Money::format($c), $r->unitPrices)),
            'suma_eur' => fn (InvoiceRow $r) => round($r->totalCents / 100, 2),
        ];
    }

    /** @return array<string, callable(array, InvoiceRow): (int|float|string|null)> */
    public static function detailColumns(): array
    {
        return [
            'osobne_cislo' => fn (array $l, InvoiceRow $r) => (string) $r->employee->osobne_cislo,
            'meno' => fn (array $l, InvoiceRow $r) => $r->employee->fullNameReversed(),
            'datum' => fn (array $l) => $l['date']->format('Y-m-d'),
            'jedlo' => fn (array $l) => $l['meal'],
            'menu' => fn (array $l) => $l['menu'],
            'cena_eur' => fn (array $l) => round($l['cents'] / 100, 2),
        ];
    }

    public function fileName(string $month): string
    {
        return 'fakturacia_'.$month.'.xlsx';
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function write(InvoiceData $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fakt').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->setFontBold();
        $money = (new Style)->setFormat('0.00');

        $writer->getCurrentSheet()->setName('Súhrn');
        $writer->addRow(Row::fromValues(array_keys(self::summaryColumns()), $bold));
        foreach ($data->rows as $row) {
            $cells = [];
            foreach (self::summaryColumns() as $h => $v) {
                $cells[] = $h === 'suma_eur' ? Cell::fromValue($v($row), $money) : Cell::fromValue($v($row));
            }
            $writer->addRow(new Row($cells));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('Detail');
        $writer->addRow(Row::fromValues(array_keys(self::detailColumns()), $bold));
        foreach ($data->rows as $row) {
            foreach ($row->lines as $line) {
                $cells = [];
                foreach (self::detailColumns() as $h => $v) {
                    $cells[] = $h === 'cena_eur' ? Cell::fromValue($v($line, $row), $money) : Cell::fromValue($v($line, $row));
                }
                $writer->addRow(new Row($cells));
            }
        }
        $writer->close();

        return $path;
    }
}
