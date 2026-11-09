<?php

namespace App\Domain\Exports;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Flat order list for any date range (brief §7.3): one row per order, active AND
 * cancelled (see "stav"), for pivoting and future integrations.
 *
 * Streams rows from a DB cursor into the file, so memory stays flat.
 * CSV: UTF-8 with BOM, ";" separator, decimal comma — opens correctly in Slovak Excel.
 */
class OrderListExport
{
    public const COLUMNS = [
        'datum', 'jedlo', 'osobne_cislo', 'priezvisko', 'meno', 'stredisko', 'menu_cislo', 'menu_nazov',
        'typ', 'cena', 'stav', 'vytvorene', 'zrusene', 'vytvoril',
    ];

    public const MAX_DAYS = 366;

    public function xlsx(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zoznam').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Objednávky');
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        foreach ([1 => 11, 2 => 9, 3 => 12, 4 => 16, 5 => 14, 6 => 16, 7 => 7, 8 => 36, 9 => 10, 10 => 9, 11 => 9, 12 => 18, 13 => 18, 14 => 16] as $col => $w) {
            $sheet->setColumnWidth($w, $col);
        }

        $writer->addRow(Row::fromValues(self::COLUMNS, (new Style)->setFontBold()->setBackgroundColor('D6D3D1')));
        $money = (new Style)->setFormat('#,##0.00');
        $date = (new Style)->setFormat('yyyy-mm-dd');
        $dateTime = (new Style)->setFormat('yyyy-mm-dd hh:mm:ss');

        foreach ($this->rows($from, $to) as $r) {
            $writer->addRow(new Row([
                Cell::fromValue($r['datum'], $date),
                Cell::fromValue($r['jedlo']),
                Cell::fromValue($r['osobne_cislo']),
                Cell::fromValue($r['priezvisko']),
                Cell::fromValue($r['meno']),
                Cell::fromValue($r['stredisko']),
                Cell::fromValue($r['menu_cislo']),
                Cell::fromValue($r['menu_nazov']),
                Cell::fromValue($r['typ']),
                Cell::fromValue($r['cena_cents'] / 100, $money),
                Cell::fromValue($r['stav']),
                Cell::fromValue($r['vytvorene'], $dateTime),
                $r['zrusene'] ? Cell::fromValue($r['zrusene'], $dateTime) : Cell::fromValue(''),
                Cell::fromValue($r['vytvoril']),
            ]));
        }

        $writer->close();

        return $path;
    }

    public function csv(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zoznam').'.csv';
        $fh = fopen($path, 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, self::COLUMNS, ';', '"', '');

        foreach ($this->rows($from, $to) as $r) {
            fputcsv($fh, [
                $r['datum']->format('Y-m-d'), $r['jedlo'], $r['osobne_cislo'], $r['priezvisko'], $r['meno'], $r['stredisko'],
                $r['menu_cislo'], $r['menu_nazov'], $r['typ'], number_format($r['cena_cents'] / 100, 2, ',', ''), $r['stav'],
                $r['vytvorene']->format('Y-m-d H:i:s'), $r['zrusene']?->format('Y-m-d H:i:s') ?? '', $r['vytvoril'],
            ], ';', '"', '');
        }

        fclose($fh);

        return $path;
    }

    public static function fileName(CarbonImmutable $from, CarbonImmutable $to, string $ext): string
    {
        $period = $from->isSameDay($from->startOfMonth()) && $to->isSameDay($from->endOfMonth())
            ? $from->format('Y-m')
            : $from->toDateString().'_'.$to->toDateString();

        return "stravovanie_{$period}_zoznam.$ext";
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function rows(CarbonImmutable $from, CarbonImmutable $to): \Generator
    {
        $cursor = Order::query()
            ->toBase()
            ->join('employees', 'employees.id', '=', 'orders.employee_id')
            ->join('meal_slots', 'meal_slots.id', '=', 'orders.meal_slot_id')
            ->join('menu_items', 'menu_items.id', '=', 'orders.menu_item_id')
            ->join('price_types', 'price_types.id', '=', 'orders.price_type_id')
            ->leftJoin('users', 'users.id', '=', 'orders.created_by_user_id')
            ->whereBetween('orders.date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('orders.date')
            ->orderBy('meal_slots.sort')
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name')
            ->orderBy('orders.id')
            ->select([
                'orders.date', 'orders.unit_price_cents', 'orders.status', 'orders.created_at', 'orders.cancelled_at',
                'meal_slots.code as slot_code', 'employees.osobne_cislo', 'employees.last_name', 'employees.first_name',
                'employees.department', 'menu_items.number', 'menu_items.name as dish', 'price_types.code as type_code',
                'users.name as created_by',
            ])
            ->cursor();

        foreach ($cursor as $o) {
            yield [
                'datum' => LocalTime::date($o->date),
                'jedlo' => $o->slot_code,
                'osobne_cislo' => (string) $o->osobne_cislo,
                'priezvisko' => $o->last_name,
                'meno' => $o->first_name,
                'stredisko' => (string) $o->department,
                'menu_cislo' => (int) $o->number,
                'menu_nazov' => $o->dish,
                'typ' => $o->type_code === 'donated' ? 'darovane' : $o->type_code,
                'cena_cents' => (int) $o->unit_price_cents,
                'stav' => $o->status === OrderStatus::Active->value ? 'aktivna' : 'zrusena',
                'vytvorene' => LocalTime::toLocal(CarbonImmutable::parse($o->created_at, 'UTC')),
                'zrusene' => $o->cancelled_at ? LocalTime::toLocal(CarbonImmutable::parse($o->cancelled_at, 'UTC')) : null,
                'vytvoril' => $o->created_by ?? 'sám',
            ];
        }
    }
}
