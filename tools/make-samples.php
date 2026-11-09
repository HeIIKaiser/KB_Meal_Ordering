<?php

// Regenerate: php tools/make-samples.php
// Generates presentation sample files into docs/samples/ (run from the repo root).
require 'vendor/autoload.php';

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

$out = 'docs/samples';
mt_srand(2026);

function xlsx(string $path, array $rows, array $widths = []): void
{
    $w = new Writer;
    $w->openToFile($path);
    foreach ($widths as $col => $width) {
        $w->getCurrentSheet()->setColumnWidth($width, $col);
    }
    $bold = (new Style)->setFontBold()->setBackgroundColor('D6D3D1');
    foreach ($rows as $i => $r) {
        $w->addRow(Row::fromValues($r, $i === 0 ? $bold : null));
    }
    $w->close();
}
function csv(string $path, array $rows): void
{
    $fh = fopen($path, 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    foreach ($rows as $r) {
        fputcsv($fh, $r, ';', '"', '');
    }
    fclose($fh);
}

// ---------------------------------------------------------------- employees
$male = ['Ján', 'Peter', 'Martin', 'Tomáš', 'Michal', 'Jozef', 'Marek', 'Lukáš', 'Juraj', 'Milan', 'Pavol', 'Ľubomír', 'Štefan', 'Dušan', 'Matej', 'Ondrej', 'Rastislav', 'Vladimír', 'Igor', 'Róbert', 'Andrej', 'Miroslav', 'Roman', 'Jakub'];
$female = ['Mária', 'Anna', 'Zuzana', 'Katarína', 'Jana', 'Eva', 'Lucia', 'Martina', 'Ivana', 'Monika', 'Simona', 'Veronika', 'Andrea', 'Ľudmila', 'Alžbeta', 'Dagmar', 'Silvia', 'Barbora', 'Petra', 'Žaneta', 'Daniela', 'Gabriela', 'Viera', 'Helena'];
$surnames = [['Novák', 'Nováková'], ['Kováč', 'Kováčová'], ['Horváth', 'Horváthová'], ['Varga', 'Vargová'], ['Tóth', 'Tóthová'], ['Baláž', 'Balážová'], ['Szabó', 'Szabóová'], ['Molnár', 'Molnárová'], ['Šimko', 'Šimková'], ['Lukáč', 'Lukáčová'], ['Kráľ', 'Kráľová'], ['Čierny', 'Čierna'], ['Hudák', 'Hudáková'], ['Polák', 'Poláková'], ['Oravec', 'Oravcová'], ['Kollár', 'Kollárová'], ['Gajdoš', 'Gajdošová'], ['Šťastný', 'Šťastná'], ['Žiak', 'Žiaková'], ['Chovanec', 'Chovancová'], ['Ďurica', 'Ďuricová'], ['Mikuš', 'Mikušová'], ['Bartoš', 'Bartošová'], ['Urban', 'Urbanová'], ['Ťapák', 'Ťapáková'], ['Krajčír', 'Krajčírová'], ['Jurčo', 'Jurčová'], ['Fábry', 'Fábryová'], ['Michalík', 'Michalíková'], ['Pavlík', 'Pavlíková'], ['Bednár', 'Bednárová'], ['Repka', 'Repková'], ['Halás', 'Halásová'], ['Sloboda', 'Slobodová'], ['Ševčík', 'Ševčíková'], ['Lacko', 'Lacková']];
$departments = ['Kuchyňa' => 10, 'Reštaurácia' => 9, 'Recepcia' => 7, 'Wellness' => 9, 'Balneoterapia' => 10, 'Lekári' => 5, 'Sestry' => 10, 'Údržba' => 6, 'Upratovanie' => 9, 'Ekonomické' => 5];

$employees = [['osobne_cislo', 'priezvisko', 'meno', 'stredisko', 'aktivny']];
$used = [];
$no = 2001;
foreach ($departments as $dept => $count) {
    for ($i = 0; $i < $count; $i++) {
        do {
            $f = mt_rand(0, 1) === 1;
            $last = $surnames[mt_rand(0, count($surnames) - 1)][$f ? 1 : 0];
            $first = ($f ? $female : $male)[mt_rand(0, 23)];
        } while (isset($used["$last $first"]));
        $used["$last $first"] = true;
        $employees[] = [(string) $no++, $last, $first, $dept, 'áno'];
    }
}
// two former employees, to show the "neaktívny" handling
$employees[] = [(string) $no++, 'Bývalá', 'Renáta', 'Recepcia', 'nie'];
$employees[] = [(string) $no++, 'Odišiel', 'Karol', 'Údržba', 'nie'];

xlsx("$out/zamestnanci.xlsx", $employees, [1 => 14, 2 => 18, 3 => 14, 4 => 16, 5 => 9]);
csv("$out/zamestnanci.csv", $employees);

// "second import" showing changes: department change, one leaves (missing in file), two new hires, one deactivated
$changes = $employees;
array_splice($changes, 5, 1);                       // employee 2005 missing → can be deactivated
$changes[2][3] = 'Reštaurácia';                     // 2002 moves from Kuchyňa
$changes[10][1] = 'Kováčová-Nováková';              // 2011 surname change (marriage)
$changes[20][4] = 'nie';                            // 2021 deactivated in the file
$changes[] = [(string) $no++, 'Nováčiková', 'Lenka', 'Wellness', 'áno'];
$changes[] = [(string) $no++, 'Mladý', 'Filip', 'Kuchyňa', 'áno'];
xlsx("$out/zamestnanci_zmeny.xlsx", $changes, [1 => 14, 2 => 18, 3 => 14, 4 => 16, 5 => 9]);

// ---------------------------------------------------------------- menus
$soups = ['Fazuľová polievka (1,9)', 'Paradajková polievka (1,9)', 'Hrachová polievka (1,9)', 'Brokolicová krémová polievka (1,7,9)', 'Kapustnica s klobásou (1,9)', 'Slepačí vývar s rezancami (1,3,9)', 'Šošovicová polievka (1,9)', 'Gulášová polievka (1,9)', 'Zeleninová polievka (9)', 'Hubová polievka (1,7,9)', 'Cesnaková polievka so syrom (1,7)', 'Frankfurtská polievka (1,9,10)', 'Karfiolová polievka (1,7,9)', 'Hovädzí vývar s pečeňovými haluškami (1,3,9)'];
$mains = ['Hovädzí guláš, knedľa (1,3,7)', 'Kuracie prsia na prírodno, ryža (7)', 'Bryndzové halušky so slaninou (1,3,7)', 'Rybie prsty, zemiaková kaša (1,3,4,7)', 'Segedínsky guláš, knedľa (1,3,7)', 'Vyprážaný bravčový rezeň, zemiakový šalát (1,3,7,10)', 'Zeleninové rizoto so syrom (7,9)', 'Sviečková na smotane, knedľa (1,3,7,9,10)', 'Pečené kuracie stehno, dusená ryža (9)', 'Šošovicový prívarok, vajce, chlieb (1,3)', 'Plnená paprika, paradajková omáčka, zemiaky (1,3,7)', 'Granadír (1,3)', 'Kuracie na paprike, cestovina (1,3,7)', 'Morčacie soté so zeleninou, ryža (6,9)', 'Vyprážaný syr, hranolky, tatárska omáčka (1,3,7,10)', 'Bravčové výpečky, kapusta, knedľa (1,3)', 'Kurací rezeň v cornflakes, zemiaky (1,3,7)', 'Lasagne s mletým mäsom (1,3,7,9)', 'Zapekaný losos, brokolica, zemiaky (4,7)', 'Pirohy s bryndzou a slaninou (1,3,7)', 'Hovädzie na hubách, ryža (1,7,9)', 'Kuracie kari, basmati ryža (7,9)', 'Mäsové guľky v paradajkovej omáčke, cestovina (1,3,7)', 'Špenát, vajce, zemiaky (3,7)', 'Bravčová panenka, opekané zemiaky (7)', 'Cestoviny s kuracím mäsom a smotanou (1,3,7)', 'Kapustové strapačky so slaninou (1,3,7)', 'Plnené kuracie prsia, zemiaková kaša (1,3,7)'];
$dinners = ['Palacinky s džemom (1,3,7)', 'Krupicová kaša s kakaom (1,7)', 'Zeleninový šalát s kuracím mäsom (3,10)', 'Pizza toast so syrom (1,7)', 'Ryžový nákyp s ovocím (3,7)', 'Pečené zemiaky s tvarohom (7)', 'Hrachová kaša, párok (1,9)', 'Zapekané cestoviny so šunkou (1,3,7)', 'Tvarohové knedlíky s maslom (1,3,7)', 'Grilovaná zelenina, syr halloumi (7)', 'Langoš so syrom a kečupom (1,7)', 'Šalát caprese, bageta (1,7)', 'Omeleta so zeleninou, chlieb (1,3,7)', 'Kuracie wrapy so zeleninou (1,7,10)', 'Buchty na pare s lekvárom (1,3,7)'];
// some kitchens won't bother with allergens — show that it works
$noAllergens = fn (string $s) => trim(preg_replace('/\s*\([\d,]+\)$/', '', $s));

$menu = [['datum', 'polievka', 'obed_1', 'obed_2', 'vecera']];
$start = new DateTimeImmutable('2026-11-09');     // Monday
for ($d = 0; $d < 28; $d++) {
    $day = $start->modify("+$d days");
    $row = [
        $day->format('j.n.Y'),
        $soups[$d % count($soups)],
        $mains[(2 * $d) % count($mains)],
        $mains[(2 * $d + 7) % count($mains)],
        $dinners[(3 * $d) % count($dinners)],
    ];
    if (in_array($d, [10, 24], true)) {                // two days typed without allergens
        $row = array_map($noAllergens, $row);
        $row[0] = $day->format('j.n.Y');
    }
    $menu[] = $row;
}
xlsx("$out/jedalny_listok_4_tyzdne.xlsx", $menu, [1 => 12, 2 => 38, 3 => 48, 4 => 48, 5 => 40]);
csv("$out/jedalny_listok_4_tyzdne.csv", $menu);

// a file with typical mistakes, to demo the per-row error report
$bad = [
    ['datum', 'polievka', 'obed_1', 'obed_2', 'vecera'],
    ['7.12.2026', 'Fazuľová polievka (1,9)', 'Hovädzí guláš, knedľa (1,3,7)', 'Zeleninové rizoto (7,9)', 'Palacinky (1,3,7)'],
    ['8.12.2026', 'Kapustnica (1,9)', 'Kuracie prsia, ryža (7)', 'Bryndzové halušky (1,3,7)', 'Krupicová kaša (1,7)'],
    ['31.12.2026x', 'Paradajková (1,9)', 'Segedín (1,3,7)', 'Rizoto', 'Toast (1,7)'],          // bad date
    ['9.12.2026', 'Hrachová (1,9)', 'Rezeň (1,3,7,15)', 'Granadír (1,3)', 'Nákyp (3,7)'],     // allergen 15
    ['8.12.2026', 'Duplicita', 'Duplicita', '', ''],                                          // duplicate date
    ['10.12.2026', '', '', '', 'Len večera (1,7)'],                                           // fine: only dinner
];
csv("$out/jedalny_listok_s_chybami.csv", $bad);

echo "done\n";
