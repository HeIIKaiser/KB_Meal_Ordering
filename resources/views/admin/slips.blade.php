<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Prístupové lístky – Jedáleň</title>
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
        .how { grid-column: 1; font-size: 7.5pt; line-height: 1.3; align-self: end; margin-top: 2mm; }
        .how ol { margin: 0.5mm 0 0; padding-left: 4mm; }
        .empty { padding: 40px; text-align: center; font-size: 18px; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { margin: 0; width: auto; }
        }
    </style>
</head>
<body>
@if ($slips === [])
    <div class="toolbar"><a href="/admin/employees">Späť na zamestnancov</a></div>
    <p class="empty">Lístky sa zobrazujú iba raz, hneď po vygenerovaní PINov.<br>Ak ste ich nevytlačili, vygenerujte PINy znova.</p>
@else
    <div class="toolbar">
        <button type="button" onclick="window.print()">Tlačiť ({{ count($slips) }} {{ count($slips) === 1 ? 'lístok' : (count($slips) < 5 ? 'lístky' : 'lístkov') }})</button>
        <a href="/admin/employees">Späť na zamestnancov</a>
        <span class="warn">⚠ PINy sa zobrazujú iba teraz. Po opustení tejto stránky ich už nebude možné zobraziť — ak treba, vygenerujte nové.</span>
    </div>
    <div class="sheet">
        @foreach ($slips as $s)
            <div class="slip">
                <div class="head">Jedáleň KB SPA – objednávanie stravy</div>
                <div>
                    <div class="name">{{ $s['name'] }}</div>
                    @if ($s['department'])<div class="dept">{{ $s['department'] }}</div>@endif
                    <div class="cred">Osobné číslo: <b>{{ $s['osobne_cislo'] }}</b></div>
                    <div class="cred">PIN: <b>{{ $s['pin'] }}</b></div>
                </div>
                <div class="qr"><img src="{{ $qr }}" alt="QR kód">{{ $url }}</div>
                <div class="how">
                    <strong>Ikona na ploche telefónu:</strong>
                    <ol>
                        <li>Naskenujte QR kód a prihláste sa.</li>
                        <li>Android ⋮ / iPhone □↑ → <em>Pridať na plochu</em>.</li>
                        <li>Objednávajte ikonou <em>Jedáleň</em>.</li>
                    </ol>
                </div>
            </div>
        @endforeach
    </div>
@endif
</body>
</html>
