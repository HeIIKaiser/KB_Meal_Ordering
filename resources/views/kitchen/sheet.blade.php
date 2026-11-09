{{--
    Printable kitchen list, shared by the browser print page and the PDF (dompdf).
    Table-only layout (no flex/grid) so dompdf renders it the same way.
    Expects: $o (App\Domain\Kitchen\DailyOverview), $printedAt (string), optional $pdf (bool)
--}}
@php
    use App\Support\SlovakDate;
    $pdf ??= false;
    // One employee per row (Roman, 2026-11-10): full dish text, zebra rows. Widths in mm
    // (printable ≈ 192 mm; dompdf adds cell padding on top, so a little less there).
    $w = ['cb' => 6, 'no' => 7, 'name' => 50, 'oc' => 14, 'menu' => 11, 'type' => 22, 'dish' => $pdf ? 58 : 80];
@endphp
<style>
    @page { size: A4 portrait; margin: 10mm 9mm 12mm; }
    .sheet { font-family: 'DejaVu Sans', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; color: #000; font-size: 10pt;
             -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .sheet table { border-collapse: collapse; width: 100%; }
    .sheet .head td { vertical-align: bottom; padding: 0 0 2mm; border-bottom: 1.5pt solid #000; }
    .sheet .title { font-size: 15pt; font-weight: bold; }
    .sheet .meta { text-align: right; font-size: 8.5pt; }
    .sheet .sum { font-size: 12pt; font-weight: bold; }
    .sheet .list { margin-top: 2mm; }
    .sheet .list thead { display: table-header-group; }
    .sheet .list th { font-size: 8.5pt; text-align: left; border-bottom: 1pt solid #000; padding: 1.2mm 1.5mm; background: #fff; }
    .sheet .list td { padding: {{ $pdf ? '0.55mm' : '1.1mm' }} 1.5mm; border-bottom: 0.4pt solid #ccc; vertical-align: middle; }
    .sheet .list tr { page-break-inside: avoid; }
    .sheet .list tr.alt td { background: #ececec; }
    .sheet .box { width: 3.6mm; height: 3.6mm; border: 0.9pt solid #000; background: #fff; }
    .sheet .no { color: #555; font-size: 8pt; text-align: right; }
    .sheet .name { font-weight: bold; }
    .sheet .oc { font-size: 8pt; color: #444; }
    .sheet .menu { text-align: center; font-weight: bold; font-size: 12pt; }
    .sheet .type { font-size: 7.5pt; font-weight: bold; }
    .sheet .dish { font-size: 10pt; }
    .sheet .note { font-size: 8pt; color: #333; font-style: italic; }
    .sheet .totals { margin-top: 5mm; page-break-inside: avoid; }
    .sheet .totals th, .sheet .totals td { border: 0.8pt solid #000; padding: 1.2mm 2mm; text-align: left; }
    .sheet .totals .n { text-align: right; width: 24mm; font-weight: bold; font-size: 10pt; }
    .sheet .totals tfoot td { font-weight: bold; font-size: 10pt; }
    .sheet .empty { padding: 10mm 0; font-size: 11pt; text-align: center; }
</style>

<div class="sheet">
    <table class="head">
        <tr>
            <td>
                <div class="title">{{ $o->slot->name }} – {{ SlovakDate::day($o->date) }} {{ $o->date->format('j. n. Y') }}</div>
                <div class="sum">Spolu: {{ $o->total() }} @if ($o->extraCount()) (z toho dokupovaná: {{ $o->extraCount() }}) @endif</div>
                @if ($o->soup)<div>Polievka: {{ $o->soup }}</div>@endif
            </td>
            <td class="meta">
                Vytlačené {{ $printedAt }}<br>
                @if ($o->lock)
                    Uzamknuté {{ \App\Support\LocalTime::toLocal($o->lock->locked_at)->format('j. n. H:i') }}
                @elseif ($o->availability->isOrderable())
                    <strong>Objednávky ešte otvorené do {{ $o->availability->cutoffAt->format('H:i') }}!</strong>
                @else
                    Po uzávierke
                @endif
            </td>
        </tr>
    </table>

    @if ($o->total() === 0)
        <p class="empty">Žiadne objednávky.</p>
    @else
        <table class="list">
            <thead>
                <tr>
                    <th style="width: {{ $w['cb'] }}mm"></th>
                    <th class="no" style="width: {{ $w['no'] }}mm">#</th>
                    <th style="width: {{ $w['name'] }}mm">Priezvisko Meno</th>
                    <th style="width: {{ $w['oc'] }}mm">Os. č.</th>
                    <th class="menu" style="width: {{ $w['menu'] }}mm">Menu</th>
                    <th style="width: {{ $w['dish'] }}mm">Jedlo</th>
                    <th style="width: {{ $w['type'] }}mm">Typ</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($o->rows as $i => $r)
                    <tr @class(['alt' => $i % 2 === 1])>
                        <td style="width: {{ $w['cb'] }}mm"><div class="box"></div></td>
                        <td class="no" style="width: {{ $w['no'] }}mm">{{ $i + 1 }}</td>
                        <td class="name" style="width: {{ $w['name'] }}mm">{{ $r['name'] }}</td>
                        <td class="oc" style="width: {{ $w['oc'] }}mm">{{ $r['osobne_cislo'] }}</td>
                        <td class="menu" style="width: {{ $w['menu'] }}mm">{{ $r['number'] }}</td>
                        <td class="dish" style="width: {{ $w['dish'] }}mm">{{ $r['dish'] }}@if ($r['note'])<div class="note">{{ $r['note'] }}</div>@endif</td>
                        <td class="type" style="width: {{ $w['type'] }}mm">@if ($r['extra'])DOKUPOVANÁ@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        <thead><tr><th>Menu</th><th>Jedlo</th><th class="n">Zamestnanecká</th><th class="n">Dokupovaná</th><th class="n">Spolu</th></tr></thead>
        <tbody>
            @foreach ($o->totals as $t)
                <tr>
                    <td class="n" style="text-align:center">{{ $t['item']->number }}</td>
                    <td>{{ $t['item']->name }}@unless ($t['item']->is_active) <em>(stiahnuté z ponuky)</em>@endunless</td>
                    <td class="n">{{ $t['donated'] }}</td>
                    <td class="n">{{ $t['extra'] }}</td>
                    <td class="n">{{ $t['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="2">Spolu</td><td class="n">{{ $o->total() - $o->extraCount() }}</td><td class="n">{{ $o->extraCount() }}</td><td class="n">{{ $o->total() }}</td></tr>
        </tfoot>
    </table>
</div>
