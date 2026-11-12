<x-filament-panels::page>
    <x-filament::section>
        <form wire:submit="save" class="grid max-w-xl gap-6">
            <label class="grid gap-1">
                <span class="text-sm font-semibold">Názov aplikácie</span>
                <x-filament::input.wrapper :valid="! $errors->has('appName')">
                    <x-filament::input type="text" maxlength="30" wire:model="appName" placeholder="napr. KB Zamestnanci" />
                </x-filament::input.wrapper>
                <span class="text-sm text-gray-500">Názov pod ikonou na ploche telefónu, v hlavičke aplikácie a v návode na prístupových listoch. Na telefóne sa zmestí asi 12 znakov. Už nainštalované ikony sa premenujú, keď si ich zamestnanec znova pridá na plochu.</span>
                @error('appName') <span class="text-sm text-danger-600">{{ $message }}</span> @enderror
            </label>

            <label class="grid gap-1">
                <span class="text-sm font-semibold">Názov firmy</span>
                <x-filament::input.wrapper :valid="! $errors->has('company')">
                    <x-filament::input type="text" maxlength="60" wire:model="company" placeholder="napr. KB SPA" />
                </x-filament::input.wrapper>
                <span class="text-sm text-gray-500">Zobrazí sa na prístupových listoch, na prihlasovacej stránke, pri ikone aplikácie a v aplikácii na overovanie (2FA). Prázdne = bez názvu.</span>
                @error('company') <span class="text-sm text-danger-600">{{ $message }}</span> @enderror
            </label>

            <label class="grid gap-1">
                <span class="text-sm font-semibold">Objednávať vopred (dní)</span>
                <x-filament::input.wrapper :valid="! $errors->has('horizon')">
                    <x-filament::input type="number" min="0" max="90" wire:model="horizon" />
                </x-filament::input.wrapper>
                <span class="text-sm text-gray-500">Koľko dní dopredu môžu zamestnanci objednávať (napr. 14).</span>
                @error('horizon') <span class="text-sm text-danger-600">{{ $message }}</span> @enderror
            </label>

            <label class="grid gap-1">
                <span class="text-sm font-semibold">Max. počet dokupovaných jedál (6 €) na jedlo a deň</span>
                <x-filament::input.wrapper :valid="! $errors->has('maxExtra')">
                    <x-filament::input type="number" min="0" max="50" wire:model="maxExtra" placeholder="bez obmedzenia" />
                </x-filament::input.wrapper>
                <span class="text-sm text-gray-500">Okrem zamestnaneckej stravy. Prázdne = bez obmedzenia (predvolené), 0 = extra sa nedá objednať.</span>
                @error('maxExtra') <span class="text-sm text-danger-600">{{ $message }}</span> @enderror
            </label>

            <p class="text-sm text-gray-500">
                Čas uzávierky pre obed a večeru nastavíte v časti
                <a class="font-semibold text-primary-600 underline" href="{{ \App\Filament\Resources\MealSlots\MealSlotResource::getUrl() }}">Jedlá a uzávierky</a>,
                ceny v časti <a class="font-semibold text-primary-600 underline" href="{{ \App\Filament\Resources\Prices\PriceResource::getUrl() }}">Ceny</a>.
            </p>

            <div><x-filament::button type="submit">Uložiť</x-filament::button></div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
