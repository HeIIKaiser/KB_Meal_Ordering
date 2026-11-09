<?php

namespace App\Domain\Exports;

use App\Support\LocalTime;
use App\Support\SlovakDate;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Monthly payroll / attendance export (brief §7.2).
 *
 * ALL layout decisions of this export live in this class, so it can be adapted
 * to the input sheet of Roman's existing workbook (OPEN §13.6) without touching
 * the numbers (MonthlyData).
 *
 * Sheet "Mesiac": one block per employee — header row with day-of-week, then one
 * row per price type (or per price type × slot with $splitBySlot), day cells are
 * COUNTS, weekends shaded, "Spolu" and "Suma €" at the end.
 * Sheet "Súhrn": one row per employee — the sheet payroll imports.
 */
class MonthlyMatrixXlsx
{
    private const MONEY = '#,##0.00 "€"';

    private const WEEKEND = 'E7E5E4';

    public function write(MonthlyData $data, bool $splitBySlot = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mesiac').'.xlsx';
        // Printing: A4 landscape, all days on one page width (rows continue on further pages).
        $options = new Options;
        $options->setPageSetup(new PageSetup(PageOrientation::LANDSCAPE, PaperSize::A4, fitToHeight: 0, fitToWidth: 1));

        $writer = new Writer($options);
        $writer->openToFile($path);

        $this->matrixSheet($writer, $data, $splitBySlot);
        $writer->addNewSheetAndMakeItCurrent();
        $this->summarySheet($writer, $data);

        $writer->close();

        return $path;
    }

    public static function fileName(MonthlyData $data): string
    {
        return 'stravovanie_'.$data->month->format('Y-m').'_mesacny.xlsx';
    }

    private function matrixSheet(Writer $writer, MonthlyData $data, bool $split): void
    {
        $days = $data->daysInMonth();
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Mesiac');
        $sheet->setColumnWidth(38, 1);
        $sheet->setColumnWidth(18, 2);
        $sheet->setColumnWidthForRange(4.2, 3, 2 + $days);
        $sheet->setColumnWidth(8, 3 + $days);
        $sheet->setColumnWidth(11, 4 + $days);
        $sheet->setSheetView((new SheetView)->setFreezeRow(4)->setFreezeColumn('C'));

        $weekend = [];
        for ($d = 1; $d <= $days; $d++) {
            $weekend[$d] = $data->month->setDay($d)->isWeekend();
        }

        $plain = new Style;
        $bold = (new Style)->setFontBold();
        $day = (new Style)->setCellAlignment(CellAlignment::CENTER);
        $dayBold = (new Style)->setCellAlignment(CellAlignment::CENTER)->setFontBold();
        $shade = (new Style)->setBackgroundColor(self::WEEKEND)->setCellAlignment(CellAlignment::CENTER);
        $shadeBold = (new Style)->setBackgroundColor(self::WEEKEND)->setFontBold()->setCellAlignment(CellAlignment::CENTER);
        $money = (new Style)->setFormat(self::MONEY);
        $moneyBold = (new Style)->setFormat(self::MONEY)->setFontBold();
        $header = (new Style)->setFontBold()->setBackgroundColor('D6D3D1');

        $monthLabel = SlovakDate::monthYear($data->month);
        $writer->addRow(Row::fromValues(['Stravovanie – '.$monthLabel], (new Style)->setFontBold()->setFontSize(14)));
        $writer->addRow(Row::fromValues([
            'Vytvorené '.LocalTime::now()->format('j. n. Y H:i'),
            'Počty jedál podľa dní; suma podľa cien v čase objednania. Iba platné (nezrušené) objednávky.',
        ]));
        $writer->addRow(Row::fromValues([]));

        // Column header: day numbers
        $cells = [Cell::fromValue('Zamestnanec', $header), Cell::fromValue('', $header)];
        for ($d = 1; $d <= $days; $d++) {
            $cells[] = Cell::fromValue($d, (new Style)->setFontBold()->setBackgroundColor('D6D3D1')->setCellAlignment(CellAlignment::CENTER));
        }
        $cells[] = Cell::fromValue('Spolu', $header);
        $cells[] = Cell::fromValue('Suma €', $header);
        $writer->addRow(new Row($cells));

        // Row definitions: [label, typeId, slotId|null]
        $lines = [];
        foreach ($data->priceTypes as $type) {
            if ($split) {
                foreach ($data->slots as $slot) {
                    $lines[] = [$type->name.' – '.mb_strtolower($slot->name), $type->id, $slot->id];
                }
            } else {
                $lines[] = [$type->name, $type->id, null];
            }
        }

        foreach ($data->employees as $entry) {
            $e = $entry['employee'];

            // employee header row with day-of-week
            $cells = [
                Cell::fromValue($e->fullNameReversed().' (osobné č. '.$e->osobne_cislo.')', $bold),
                Cell::fromValue($monthLabel, $bold),
            ];
            for ($d = 1; $d <= $days; $d++) {
                $cells[] = Cell::fromValue(SlovakDate::dayShort($data->month->setDay($d)), $weekend[$d] ? $shadeBold : $dayBold);
            }
            $cells[] = Cell::fromValue('', $bold);
            $cells[] = Cell::fromValue('', $bold);
            $writer->addRow(new Row($cells));

            foreach ($lines as [$label, $typeId, $slotId]) {
                $cells = [Cell::fromValue('', $plain), Cell::fromValue($label, $plain)];
                for ($d = 1; $d <= $days; $d++) {
                    $cells[] = Cell::fromValue(MonthlyData::count($entry, $typeId, $slotId, $d), $weekend[$d] ? $shade : $day);
                }
                $cells[] = Cell::fromValue(MonthlyData::count($entry, $typeId, $slotId), $bold);
                $cells[] = Cell::fromValue(MonthlyData::cents($entry, $typeId, $slotId) / 100, $money);
                $writer->addRow(new Row($cells));
            }

            // per-employee total
            $cells = [Cell::fromValue('', $plain), Cell::fromValue('Spolu', $bold)];
            for ($d = 1; $d <= $days; $d++) {
                $cells[] = Cell::fromValue(MonthlyData::count($entry, null, null, $d), $weekend[$d] ? $shadeBold : $dayBold);
            }
            $cells[] = Cell::fromValue(MonthlyData::count($entry), $bold);
            $cells[] = Cell::fromValue(MonthlyData::cents($entry) / 100, $moneyBold);
            $writer->addRow(new Row($cells));

            $writer->addRow(Row::fromValues([]));
        }
    }

    private function summarySheet(Writer $writer, MonthlyData $data): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Súhrn');
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));

        $header = (new Style)->setFontBold()->setBackgroundColor('D6D3D1');
        $money = (new Style)->setFormat(self::MONEY);
        $bold = (new Style)->setFontBold();
        $moneyBold = (new Style)->setFormat(self::MONEY)->setFontBold();

        $titles = ['Osobné číslo', 'Priezvisko', 'Meno', 'Stredisko'];
        foreach ($data->priceTypes as $t) {
            $titles[] = $t->name.' ks';
        }
        foreach ($data->priceTypes as $t) {
            $titles[] = $t->name.' €';
        }
        $titles[] = 'Suma €';

        $sheet->setColumnWidth(13, 1);
        $sheet->setColumnWidth(18, 2, 3, 4);
        $sheet->setColumnWidthForRange(12, 5, count($titles));
        $writer->addRow(Row::fromValues($titles, $header));

        $totals = array_fill(0, count($titles), 0);

        foreach ($data->employees as $entry) {
            $e = $entry['employee'];
            $cells = [
                Cell::fromValue((string) $e->osobne_cislo), // text: keeps leading zeros
                Cell::fromValue($e->last_name),
                Cell::fromValue($e->first_name),
                Cell::fromValue((string) $e->department),
            ];
            $i = 4;
            foreach ($data->priceTypes as $t) {
                $n = MonthlyData::count($entry, $t->id);
                $totals[$i++] += $n;
                $cells[] = Cell::fromValue($n);
            }
            foreach ($data->priceTypes as $t) {
                $c = MonthlyData::cents($entry, $t->id);
                $totals[$i++] += $c;
                $cells[] = Cell::fromValue($c / 100, $money);
            }
            $c = MonthlyData::cents($entry);
            $totals[$i] += $c;
            $cells[] = Cell::fromValue($c / 100, $money);
            $writer->addRow(new Row($cells));
        }

        $cells = [Cell::fromValue('Spolu', $bold), Cell::fromValue(''), Cell::fromValue(''), Cell::fromValue('')];
        $types = $data->priceTypes->count();
        for ($i = 4; $i < count($titles); $i++) {
            $isMoney = $i >= 4 + $types;
            $cells[] = Cell::fromValue($isMoney ? $totals[$i] / 100 : $totals[$i], $isMoney ? $moneyBold : $bold);
        }
        $writer->addRow(new Row($cells));
    }
}
