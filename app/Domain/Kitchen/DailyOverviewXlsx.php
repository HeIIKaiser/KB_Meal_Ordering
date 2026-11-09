<?php

namespace App\Domain\Kitchen;

use App\Support\LocalTime;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Kitchen daily list as XLSX — same content as the print (streaming writer,
 * fine for shared-hosting memory limits). Returns the path of a temp file.
 */
class DailyOverviewXlsx
{
    public function write(DailyOverview $o): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kuchyna').'.xlsx';

        $options = new Options;
        foreach ([1 => 22, 2 => 16, 3 => 12, 4 => 18, 5 => 7, 6 => 40, 7 => 10, 8 => 30] as $col => $width) {
            $options->setColumnWidth($width, $col);
        }

        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName($o->slot->name.' '.$o->date->format('j.n.'));

        $bold = (new Style)->setFontBold();
        $title = (new Style)->setFontBold()->setFontSize(14);
        $header = (new Style)->setFontBold()->setBackgroundColor('E7E5E4');

        $writer->addRow(Row::fromValues([$o->title()], $title));
        $writer->addRow(Row::fromValues(['Vytlačené', LocalTime::now()->format('j. n. Y H:i'), '', 'Spolu', $o->total(), 'z toho dokupovaná: '.$o->extraCount()]));
        if ($o->soup) {
            $writer->addRow(Row::fromValues(['Polievka', $o->soup]));
        }
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(['Priezvisko', 'Meno', 'Osobné číslo', 'Stredisko', 'Menu', 'Jedlo', 'Typ', 'Poznámka'], $header));
        foreach ($o->rows as $r) {
            $writer->addRow(Row::fromValues([
                $r['last_name'], $r['first_name'], $r['osobne_cislo'], (string) $r['department'], $r['number'], $r['dish'], $r['price_type'], (string) $r['note'],
            ], $r['extra'] ? $bold : null));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Súčty', '', '', '', 'Menu', 'Jedlo', 'Zamestnanecká', 'Dokupovaná', 'Spolu'], $header));
        foreach ($o->totals as $t) {
            $writer->addRow(Row::fromValues(['', '', '', '', $t['item']->number, $t['item']->name, $t['donated'], $t['extra'], $t['total']]));
        }
        $writer->addRow(Row::fromValues(['Spolu', '', '', '', '', '', $o->total() - $o->extraCount(), $o->extraCount(), $o->total()], $bold));

        $writer->close();

        return $path;
    }
}
