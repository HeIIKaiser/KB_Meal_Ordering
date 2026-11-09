{{--
    Printable kitchen list, shared by the browser print page and the PDF (dompdf).
    Table-only layout (no flex/grid) so dompdf renders it the same way.
    Expects: $o (App\Domain\Kitchen\DailyOverview), $printedAt (string), optional $pdf (bool)
--}}
@php
    use App\Support\SlovakDate;
    $pdf ??= false;
    // dompdf's DejaVu Sans is wider than browser fonts and dompdf ignores <col> widths → shorter texts
    $nameLen = $pdf ? 22 : 25;
    [$dishLen, $dishLenExtra] = $pdf ? [21, 13] : [28, 19];
    $w = ['cb' => 5, 'no' => 6, 'name' => 34, 'menu' => 8, 'dish' => $pdf ? 28 : 35];
@endphp
<style>
    @page { size: A4 portrait; margin: 10mm 9mm 12mm; }
    .sheet { font-family: 'DejaVu Sans', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; color: #000; font-size: 8.6pt; }
    .sheet table { border-collapse: collapse; width: 100%; }
    .sheet .head td { vertical-align: bottom; padding: 0 0 2mm; border-bottom: 1.5pt solid #000; }
    .sheet .title { font-size: 14pt; font-weight: bold; }
    .sheet .meta { text-align: right; font-size: 8pt; }
    .sheet .sum { font-size: 11pt; font-weight: bold; }
    .sheet .list { margin-top: 2mm; table-layout: {{ $pdf ? 'auto' : 'fixed' }}; }
    .sheet .list thead { display: table-header-group; }
    .sheet .list th { font-size: 7.5pt; text-align: left; border-bottom: 0.8pt solid #000; padding: 1mm 1mm; }
    .sheet .list td { padding: 0.9mm 1mm; border-bottom: 0.4pt solid #bbb; vertical-align: middle; white-space: nowrap; overflow: hidden; }
    .sheet .list tr { page-break-inside: avoid; }
    .sheet .cb { }
    .sheet .box { width: 3.2mm; height: 3.2mm; border: 0.8pt solid #000; }
    .sheet .no { color: #666; font-size: 7pt; text-align: right; }
    .sheet .name { font-weight: bold; }
    .sheet .menu { text-align: center; font-weight: bold; font-size: 10pt; }
    .sheet .dish { font-size: 7.4pt; }
    .sheet .gap { border-bottom: 0 !important; }
    .sheet .extra { font-size: 6pt; font-weight: bold; border: 0.8pt solid #000; padding: 0 0.6mm; }
    .sheet .totals { margin-top: 5mm; page-break-inside: avoid; }
    .sheet .totals th, .sheet .totals td { border: 0.8pt solid #000; padding: 1.2mm 2mm; text-align: left; }
    .sheet .totals .n { text-align: right; width: 18mm; font-weight: bold; font-size: 10pt; }
    .sheet .totals tfoot td { font-weight: bold; font-size: 10pt; }
    .sheet .note { font-size: 7pt; color: #333; }
    .sheet .empty { padding: 10mm 0; font-size: 11pt; text-align: center; }
</style>

<div class="sheet">
    <table class="head">
        <tr>
            <td>
                <div class="title">{{ $o->slot->name }} – {{ SlovakDate::day($o->date) }} {{ $o->date->format('j. n. Y') }}</div>
                <div class="sum">Spolu: {{ $o->total() }} @if ($o->extraCount()) (z toho extra: {{ $o->extraCount() }}) @endif</div>
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
            {{-- explicit widths (sum ≈ 180 mm of 192 mm printable) – dompdf needs them for table-layout: fixed --}}
            <colgroup>
                @foreach ([1, 2] as $col)
                    @foreach ($w as $width)<col style="width: {{ $width }}mm">@endforeach
                    @if ($col === 1)<col style="width: 4mm">@endif
                @endforeach
            </colgroup>
            <thead>
                <tr>
                    @foreach ([1, 2] as $col)
                        <th class="cb" style="width: {{ $w['cb'] }}mm"></th><th class="no" style="width: {{ $w['no'] }}mm">#</th><th class="name" style="width: {{ $w['name'] }}mm">Priezvisko Meno</th><th class="menu" style="width: {{ $w['menu'] }}mm">Menu</th><th class="dish" style="width: {{ $w['dish'] }}mm">Jedlo</th>
                        @if ($col === 1)<th class="gap" style="width: 4mm"></th>@endif
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($o->twoColumns() as [$left, $right, $ln, $rn])
                    <tr>
                        @foreach ([[$left, $ln], [$right, $rn]] as $k => [$r, $n])
                            @if ($r)
                                <td class="cb"><div class="box"></div></td>
                                <td class="no">{{ $n }}</td>
                                <td class="name">{{ \Illuminate\Support\Str::limit($r['name'], $nameLen, '…') }}</td>
                                <td class="menu">{{ $r['number'] }}</td>
                                <td class="dish">@if ($r['extra'])<span class="extra">EXTRA</span> @endif{{ \Illuminate\Support\Str::limit($r['dish'], $r['extra'] ? $dishLenExtra : $dishLen, '…') }}@if ($r['note'])<div class="note">{{ \Illuminate\Support\Str::limit($r['note'], 34) }}</div>@endif</td>
                            @else
                                <td class="cb"></td><td class="no"></td><td class="name"></td><td class="menu"></td><td class="dish"></td>
                            @endif
                            @if ($k === 0)<td class="gap"></td>@endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        <thead><tr><th>Menu</th><th>Jedlo</th><th class="n">Darované</th><th class="n">Extra</th><th class="n">Spolu</th></tr></thead>
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
