{{--
    Weekly menu for the board: A4 landscape, Monday–Sunday. Shared by the print page
    and the PDF (dompdf) — table layout only, widths given inline (dompdf ignores <col>).
    Expects: $w (App\Domain\Kitchen\WeeklyMenu), optional $pdf (bool)
--}}
@php
    use App\Support\Allergens;
    use App\Support\SlovakDate;
    $pdf ??= false;
    $n = max(1, count($w->columns));
    $dayW = 26;                                   // mm
    $colW = floor((($pdf ? 248 : 271) - $dayW) / $n);  // printable width ≈ 277 mm (dompdf adds padding on top)
    $hasSoup = collect($w->columns)->contains(fn ($c) => $c['kind'] === 'soup');
@endphp
<style>
    @page { size: A4 landscape; margin: 9mm 10mm 9mm; }
    .wk { font-family: 'DejaVu Sans', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; color: #111; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .wk table { border-collapse: collapse; width: 100%; }
    .wk .top td { vertical-align: bottom; padding: 0 0 2mm; }
    .wk .title { font-size: 26pt; font-weight: bold; }
    .wk .period { font-size: 17pt; font-weight: bold; text-align: right; }
    .wk .grid th { background: #047857; color: #fff; font-size: 12pt; text-align: left; padding: 2mm 2.5mm; border: 0.6pt solid #047857; }
    .wk .grid td { border: 0.6pt solid #9ca3af; padding: 1.8mm 2.5mm; vertical-align: top; font-size: 12pt; line-height: 1.2; }
    .wk .grid tr { page-break-inside: avoid; }
    .wk .day { font-weight: bold; white-space: nowrap; }
    .wk .day .d { display: block; font-weight: normal; font-size: 10pt; color: #444; }
    .wk .weekend td { background: #f3f4f6; }
    .wk .today td.day { background: #d1fae5; }
    .wk .al { font-size: 8.8pt; color: #555; white-space: nowrap; }
    .wk .closed { text-align: center; font-style: italic; color: #555; }
    .wk .empty { color: #aaa; }
    .wk .legend { margin-top: 1.5mm; font-size: 7.6pt; color: #333; line-height: 1.3; }
    .wk .note { font-size: 9pt; color: #333; margin-top: 1.2mm; }
</style>

<div class="wk">
    <table class="top">
        <tr>
            <td class="title">Jedálny lístok</td>
            <td class="period">{{ $w->period() }}</td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th style="width: {{ $dayW }}mm">Deň</th>
                @foreach ($w->columns as $col)
                    <th style="width: {{ $colW }}mm">{{ $col['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($w->days as $day)
                <tr @class(['weekend' => $day['date']->isWeekend(), 'today' => $day['date']->isSameDay(\App\Support\LocalTime::today())])>
                    <td class="day" style="width: {{ $dayW }}mm">
                        {{ \Illuminate\Support\Str::ucfirst(SlovakDate::day($day['date'])) }}
                        <span class="d">{{ SlovakDate::dm($day['date']) }}</span>
                    </td>
                    @if ($day['closed'])
                        <td class="closed" colspan="{{ $n }}">{{ $day['closed'] }}</td>
                    @else
                        @foreach ($day['cells'] as $cell)
                            <td style="width: {{ $colW }}mm" @class(['closed' => $cell['closed'] ?? false, 'empty' => $cell === null])>
                                @if ($cell)
                                    {{ $cell['name'] }}@if ($cell['allergens']) <span class="al">({{ $cell['allergens'] }})</span>@endif
                                @else
                                    –
                                @endif
                            </td>
                        @endforeach
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($hasSoup)
        <div class="note">Polievka sa podáva ku každému obedu.</div>
    @endif
    <div class="legend">
        <strong>Alergény:</strong>
        @foreach (Allergens::NAMES as $num => $name){{ $num }} {{ mb_strtolower($name) }}@if (! $loop->last) · @endif @endforeach
    </div>
</div>
