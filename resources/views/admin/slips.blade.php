<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Prístup do aplikácie {{ $appName }}</title>
    {{-- One A4 portrait page per employee: credentials + full instructions. Browser print. --}}
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #111; margin: 0; background: #e7e5e4;
               -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .toolbar { padding: 12px 16px; background: #047857; color: #fff; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .toolbar button, .toolbar a { font: inherit; font-weight: 700; background: #fff; color: #047857; border: 0; border-radius: 8px; padding: 8px 16px; text-decoration: none; cursor: pointer; }
        .warn { flex-basis: 100%; font-size: 14px; }
        .empty { padding: 40px; text-align: center; font-size: 18px; }

        .page { width: 210mm; height: 297mm; margin: 12px auto; background: #fff; padding: 10mm 15mm 8mm; overflow: hidden;
                display: flex; flex-direction: column; break-after: page; page-break-after: always; box-shadow: 0 1px 4px rgba(0,0,0,.2); }
        .page:last-child { break-after: auto; page-break-after: auto; }

        .brand { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #047857; padding-bottom: 3mm; }
        .brand .app { font-size: 22pt; font-weight: 800; color: #047857; }
        .brand .sub { font-size: 10.5pt; color: #444; text-align: right; }

        .who { margin-top: 3mm; }
        .who .name { font-size: 22pt; font-weight: 800; line-height: 1.1; }
        .who .dept { font-size: 12pt; color: #555; margin-top: 1mm; }

        .creds { display: flex; gap: 8mm; margin-top: 3mm; align-items: stretch; }
        .box { flex: 1; border: 2px solid #111; border-radius: 4mm; padding: 2mm 5mm; }
        .box .lbl { font-size: 10pt; text-transform: uppercase; letter-spacing: .08em; color: #444; font-weight: 700; }
        .box .val { font-family: ui-monospace, Menlo, Consolas, 'DejaVu Sans Mono', monospace; font-size: 25pt; font-weight: 800; letter-spacing: .12em; line-height: 1.15; }
        .box .nopin { font-size: 11pt; margin-top: 2mm; }
        .qr { width: 36mm; text-align: center; font-size: 8.5pt; color: #333; }
        .qr img { width: 36mm; height: 36mm; display: block; }

        /* Web address for people using a PC — big, highlighted, full https:// form */
        .web { margin-top: 3mm; border: 2.5px solid #047857; background: #ecfdf5; border-radius: 4mm; padding: 2mm 5mm; }
        .web .lbl { font-size: 11pt; font-weight: 700; color: #065f46; }
        .web .addr { font-size: 23pt; font-weight: 800; color: #000; letter-spacing: .01em; line-height: 1.2; overflow-wrap: anywhere; }

        .secret { margin-top: 2mm; font-size: 10.5pt; color: #7c2d12; font-weight: 600; }

        .cols { display: flex; gap: 8mm; margin-top: 3mm; flex: 1; }
        .col { flex: 1; }
        h2 { font-size: 12pt; margin: 0 0 1.5mm; color: #047857; display: flex; gap: 2mm; align-items: center; }
        h2 .n { display: inline-flex; width: 7mm; height: 7mm; border-radius: 50%; background: #047857; color: #fff; align-items: center; justify-content: center; font-size: 11pt; }
        section { margin-bottom: 2.5mm; }
        ol, ul { margin: 0; padding-left: 5mm; font-size: 9.6pt; line-height: 1.32; }
        li { margin-bottom: 0.4mm; }
        .os { font-weight: 700; }
        em { font-style: normal; font-weight: 700; }

        .foot { border-top: 1px solid #bbb; padding-top: 2.5mm; font-size: 9pt; color: #555; display: flex; justify-content: space-between; }

        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .page { margin: 0; box-shadow: none; }
        }
    </style>
</head>
<body>
@if ($slips === [])
    <div class="toolbar"><a href="/admin/employees">Späť na zamestnancov</a></div>
    <p class="empty">Nie sú vybraní žiadni zamestnanci.<br>V zozname zamestnancov ich označte a zvoľte <em>Tlačiť prístupové lístky</em>.</p>
@else
    <div class="toolbar">
        <button type="button" onclick="window.print()">Tlačiť ({{ count($slips) }} {{ count($slips) === 1 ? 'strana' : (count($slips) < 5 ? 'strany' : 'strán') }} A4)</button>
        <a href="/admin/employees">Späť na zamestnancov</a>
        <a href="{{ route('admin.slips', ['zamestnanci' => $ids, 'format' => 'male']) }}">Malé lístky (8 na A4)</a>
        <span class="warn">⚠ Každý list obsahuje PIN — odovzdajte ho osobne. Tlačte jednostranne na A4 na výšku, bez okrajov navyše (mierka 100 %).@if (collect($slips)->whereNull('pin')->isNotEmpty()) Pri {{ collect($slips)->whereNull('pin')->count() }} zamestnancoch PIN nie je možné vytlačiť (pozri list).@endif</span>
    </div>

    @foreach ($slips as $s)
        <div class="page">
            <div class="brand">
                <div class="app">{{ $appName }}</div>
                <div class="sub">{{ $company ? $company.' – ' : '' }}objednávanie stravy pre zamestnancov<br>prístupové údaje (osobné, neodovzdávajte ďalej)</div>
            </div>

            <div class="who">
                <div class="name">{{ $s['name'] }}</div>
                @if ($s['department'])<div class="dept">{{ $s['department'] }}</div>@endif
            </div>

            <div class="creds">
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4mm;">
                    <div class="box">
                        <div class="lbl">Osobné číslo</div>
                        <div class="val">{{ $s['osobne_cislo'] }}</div>
                    </div>
                    <div class="box">
                        <div class="lbl">PIN</div>
                        @if ($s['pin'] !== null)
                            <div class="val">{{ $s['pin'] }}</div>
                        @else
                            <div class="val">—</div>
                            <div class="nopin">{{ $s['note'] }}</div>
                        @endif
                    </div>
                </div>
                <div class="qr">
                    <img src="{{ $qr }}" alt="QR kód s adresou aplikácie">
                    <div style="margin-top: 1.5mm">Na telefóne: naskenujte fotoaparátom</div>
                </div>
            </div>
            <div class="web">
                <div class="lbl">Na počítači otvorte prehliadač a napíšte túto adresu:</div>
                <div class="addr">{{ $displayUrl }}</div>
            </div>
            <div class="secret">PIN nikomu neukazujte. Kto ho pozná, môže objednávať na vaše meno.</div>

            <div class="cols">
                <div class="col">
                    <section>
                        <h2><span class="n">1</span> Prvé prihlásenie</h2>
                        <ol>
                            <li><span class="os">Telefón:</span> naskenujte <em>QR kód</em> fotoaparátom.</li>
                            <li><span class="os">Počítač:</span> otvorte prehliadač (Chrome, Edge…), do riadka s adresou úplne hore napíšte adresu zo zeleného rámčeka a stlačte <em>Enter</em>.</li>
                            <li>Zadajte <em>osobné číslo</em> a <em>PIN</em> z tohto listu a ťuknite na <em>Prihlásiť sa</em>.</li>
                            <li>Na tomto zariadení už zostanete prihlásený — PIN nebudete zadávať pri každom objednaní. Na počítači si stránku uložte medzi obľúbené (hviezdička ☆ v riadku s adresou).</li>
                        </ol>
                    </section>
                    <section>
                        <h2><span class="n">2</span> Ikona na ploche telefónu</h2>
                        <ul>
                            <li><span class="os">Android (Chrome):</span> vpravo hore ponuka <em>⋮</em> → <em>Pridať na plochu</em> alebo <em>Inštalovať aplikáciu</em> → <em>Pridať</em>.</li>
                            <li><span class="os">iPhone (Safari):</span> dole tlačidlo <em>Zdieľať</em> (štvorček so šípkou) → <em>Pridať na plochu</em> → <em>Pridať</em>.</li>
                            <li>Odteraz otvárajte aplikáciu ikonou <em>{{ $appName }}</em> ako bežnú aplikáciu.</li>
                        </ul>
                    </section>
                    <section>
                        <h2><span class="n">3</span> Ako objednať</h2>
                        <ul>
                            <li>Hore vyberte <em>deň</em> (alebo potiahnite prstom doľava/doprava).</li>
                            <li><em>Ťuknite na jedlo = objednané.</em> Zobrazí sa ✓ a cena.</li>
                            <li>Ťuknite znova na objednané jedlo → <em>Zrušiť</em> alebo <em>Dokúpiť ďalšie rovnaké</em>.</li>
                            <li>Ťuknite na iné jedlo → <em>Zmeniť</em> alebo <em>Dokúpiť ďalšie jedlo</em>.</li>
                            <li>Polievka sa podáva ku každému obedu, neobjednáva sa.</li>
                        </ul>
                    </section>
                </div>
                <div class="col">
                    <section>
                        <h2><span class="n">4</span> Dokedy objednávať</h2>
                        <ul>
                            @foreach ($info['cutoffs'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                            <li>Dopredu najviac na {{ $info['horizon'] }} dní.</li>
                            <li>Po uzávierke je jedlo <em>uzamknuté</em> — zmenu vtedy urobí už len administrátor.</li>
                        </ul>
                    </section>
                    <section>
                        <h2><span class="n">5</span> Cena</h2>
                        <ul>
                            @foreach ($info['prices'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                            <li>Pred objednaním drahšieho jedla sa vždy zobrazí cena.</li>
                            <li>V časti <em>Môj mesiac</em> vidíte objednávky a sumu za mesiac. Cena stravy je odhadom a po porovnaní s dochádzkou sa môže zmeniť.</li>
                        </ul>
                    </section>
                    <section>
                        <h2><span class="n">6</span> PIN a bezpečnosť</h2>
                        <ul>
                            <li>PIN si môžete zmeniť: <em>Účet → Zmena PIN</em> (4 – 6 číslic).</li>
                            <li>Zabudnutý PIN: povedzte vedúcemu alebo administrátorovi, dostanete nový list.</li>
                            <li>Na spoločnom telefóne či počítači sa po objednaní odhláste (<em>Účet → Odhlásiť sa</em>).</li>
                            <li>Po viacerých nesprávnych PINoch sa prihlásenie na chvíľu zablokuje (1 – 15 minút).</li>
                        </ul>
                    </section>
                </div>
            </div>

            <div class="foot">
                <span>Problém s prihlásením alebo objednávkou? Obráťte sa na vedúceho alebo administrátora aplikácie.</span>
                <span>{{ \App\Support\LocalTime::now()->format('j. n. Y') }}</span>
            </div>
        </div>
    @endforeach
@endif
</body>
</html>
