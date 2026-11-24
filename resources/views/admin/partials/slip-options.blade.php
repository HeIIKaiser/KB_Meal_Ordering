{{-- Print options on the slips page itself (GET, remembered for this user). --}}
<form method="get" action="{{ route('admin.slips') }}" class="opts">
    @foreach ($selection as $k => $v)
        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
    @endforeach
    <input type="hidden" name="zapamatat" value="1">
    <label>Zoradiť podľa
        <select name="zoradit">
            @foreach (\App\Domain\Employees\Slips\SlipOptions::SORTS as $value => $label)
                <option value="{{ $value }}" @selected($options->sort === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label>Zoskupiť
        <select name="skupiny">
            @foreach (\App\Domain\Employees\Slips\SlipOptions::GROUPS as $value => $label)
                <option value="{{ $value }}" @selected($options->group === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label><input type="hidden" name="strana" value="0"><input type="checkbox" name="strana" value="1" @checked($options->newPage)> Nová strana pre každú skupinu</label>
    <label>Formát
        <select name="format">
            @foreach (\App\Domain\Employees\Slips\SlipOptions::FORMATS as $value => $label)
                <option value="{{ $value }}" @selected($options->format === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <button type="submit">Použiť</button>
</form>
