<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Prístupové lístky – {{ $appName }}</title>
    <style>
        @page { size: A4; margin: 8mm; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #000; margin: 0; background: #e7e5e4; }
        .toolbar { padding: 12px 16px; background: #047857; color: #fff; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .toolbar button, .toolbar a { font: inherit; font-weight: 700; background: #fff; color: #047857; border: 0; border-radius: 8px; padding: 8px 16px; text-decoration: none; cursor: pointer; }
        .warn { flex-basis: 100%; font-size: 14px; }
        .sheet { width: 194mm; margin: 12px auto; background: #fff; display: grid; grid-template-columns: 1fr 1fr; }
        .slip { height: 68mm; padding: 4mm 5mm; border: 1px dashed #999; display: grid; grid-template-columns: 1fr 27mm; grid-template-rows: auto auto 1fr; column-gap: 3mm; overflow: hidden; break-inside: avoid; page-break-inside: avoid; }
        .head { grid-column: 1 / 3; font-size: 9pt; font-weight: 700; white-space: nowrap; border-bottom: 1px solid #000; padding-bottom: 1mm; margin-bottom: 2mm; }
        .name { font-size: 12pt; font-weight: 700; line-height: 1.2; }
        .dept { font-size: 8.5pt; color: #444; }
        .cred { font-size: 9.5pt; margin-top: 1.5mm; }
        .cred b { font-size: 16pt; letter-spacing: 1.5px; font-family: ui-monospace, Menlo, Consolas, monospace; }
        .qr { grid-row: 2 / 4; grid-column: 2; text-align: center; font-size: 6.5pt; word-break: break-all; }
        .qr img { width: 27mm; height: 27mm; display: block; }
        .nopin span { display: block; font-size: 8pt; color: #444; }
        .how { grid-column: 1; font-size: 7.5pt; line-height: 1.3; align-self: end; margin-top: 2mm; }
        .how ol { margin: 0.5mm 0 0; padding-left: 4mm; }
        .empty { padding: 40px; text-align: center; font-size: 18px; }
        .opts { flex-basis: 100%; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; font-size: 14px; }
        .opts select { font: inherit; padding: 4px; border-radius: 6px; border: 0; }
        .opts label { display: flex; gap: 6px; align-items: center; }
        .section-head { grid-column: 1 / -1; font-size: 15pt; font-weight: 800; padding: 3mm 2mm 2mm; border-bottom: 2px solid #000; break-after: avoid; page-break-after: avoid; }
        /* "Nová strana pre každú skupinu" */
        .section-break { break-before: page; page-break-before: always; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { margin: 0; width: auto; }
        }
    </style>
</head>
<body>
@if ($count === 0)
    <div class="toolbar"><a href="/admin/employees">Späť na zamestnancov</a></div>
    <p class="empty">Nie sú vybraní žiadni zamestnanci.<br>V zozname zamestnancov ich označte a zvoľte <em>Tlačiť lístky</em>.</p>
@else
    <div class="toolbar">
        <button type="button" onclick="window.print()">Tlačiť ({{ $count }} {{ $count === 1 ? 'lístok' : ($count < 5 ? 'lístky' : 'lístkov') }})</button>
        <a href="/admin/employees">Späť na zamestnancov</a>
        @include('admin.partials.slip-options')
        <span class="warn">⚠ Lístky obsahujú PIN — odovzdajte ich osobne, nenechávajte ich v tlačiarni.@if ($noPin) Pri {{ $noPin }} zamestnancoch PIN nie je možné vytlačiť (pozri lístok).@endif</span>
    </div>
  @foreach ($sections as $section)
    <div @class(['sheet', 'section-break' => $options->grouped() && $options->newPage && ! $loop->first])>
        @if ($section['heading'] !== null)
            <div class="section-head">{{ $section['heading'] }}</div>
        @endif
        @foreach ($section['slips'] as $s)
            <div class="slip">
                <div class="head">{{ $appName }}{{ $company ? ' – '.$company : '' }}</div>
                <div>
                    <div class="name">{{ $s['name'] }}</div>
                    @if ($s['department'])<div class="dept">{{ $s['department'] }}</div>@endif
                    <div class="cred">Osobné číslo: <b>{{ $s['osobne_cislo'] }}</b></div>
                    @if ($s['pin'] !== null)
                        <div class="cred">PIN: <b>{{ $s['pin'] }}</b></div>
                    @else
                        <div class="cred nopin">PIN: — <span>{{ $s['note'] }}</span></div>
                    @endif
                </div>
                <div class="qr"><img src="{{ $qr }}" alt="QR kód">{{ $url }}</div>
                <div class="how">
                    <strong>Ikona na ploche telefónu:</strong>
                    <ol>
                        <li>Naskenujte QR kód a prihláste sa.</li>
                        <li>Android ⋮ / iPhone □↑ → <em>Pridať na plochu</em>.</li>
                        <li>Objednávajte ikonou <em>{{ $appName }}</em>.</li>
                    </ol>
                </div>
            </div>
        @endforeach
    </div>
  @endforeach
@endif
</body>
</html>
