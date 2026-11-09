<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $w->fileBase() }}</title>
    <style>
        body { margin: 0; background: #e7e5e4; }
        .toolbar { font-family: system-ui, sans-serif; padding: 10px 16px; background: #047857; color: #fff; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .toolbar button, .toolbar a { font: inherit; font-weight: 700; background: #fff; color: #047857; border: 0; border-radius: 8px; padding: 8px 16px; text-decoration: none; cursor: pointer; }
        .toolbar .hint { font-size: 14px; }
        .paper { width: 277mm; margin: 12px auto; padding: 9mm 10mm; background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.2); }
        @media print { body { background: #fff; } .toolbar { display: none; } .paper { width: auto; margin: 0; padding: 0; box-shadow: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Tlačiť</button>
        <a href="{{ route('menu.week.pdf', ['tyzden' => $w->monday->toDateString()]) }}">PDF</a>
        <a href="{{ route('menu.week.print', ['tyzden' => $w->monday->subWeek()->toDateString()]) }}">‹ Predchádzajúci týždeň</a>
        <a href="{{ route('menu.week.print', ['tyzden' => $w->monday->addWeek()->toDateString()]) }}">Nasledujúci týždeň ›</a>
        <span class="hint">Tlačte na šírku (A4).</span>
    </div>
    <div class="paper">
        @include('kitchen.week-sheet')
    </div>
    @if ($autoPrint)
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 150));</script>
    @endif
</body>
</html>
