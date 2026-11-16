<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Token pre most (bridge) na DOCKER01</x-slot>
        <x-slot name="description">
            Most v sieti firmy číta dochádzku z WebVisitora a posiela ju sem na <code>{{ $url }}</code>. Aplikácia sa do siete firmy nikdy nepripája.
            Samotný token sa neukladá (len jeho odtlačok) – po vygenerovaní ho hneď skopírujte do <code>bridge/.env</code> (<code>SYNC_TOKEN=</code>). Nový token okamžite zruší starý.
        </x-slot>

        @if ($newToken)
            <div class="mb-4 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:bg-warning-950">
                <p class="mb-1 font-semibold">Nový token (zobrazí sa len teraz):</p>
                <code class="block select-all break-all rounded bg-white p-2 font-mono text-base dark:bg-gray-900">{{ $newToken }}</code>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::badge :color="$hasToken ? 'success' : 'gray'">{{ $hasToken ? 'Token je nastavený' : 'Token zatiaľ nie je nastavený' }}</x-filament::badge>
            <x-filament::button color="warning" wire:click="generateToken" wire:confirm="{{ $hasToken ? 'Vygenerovať nový token? Most prestane fungovať, kým ho na DOCKER01 nezmeníte.' : 'Vygenerovať token?' }}">
                {{ $hasToken ? 'Vygenerovať nový token' : 'Vygenerovať token' }}
            </x-filament::button>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Pravidlo a prístup</x-slot>
        <form wire:submit="save" class="grid max-w-xl gap-6">
            <div class="grid grid-cols-2 gap-4">
                <label class="grid gap-1">
                    <span class="text-sm font-semibold">1 jedlo, ak odpracované viac ako</span>
                    <x-filament::input.wrapper :valid="! $errors->has('oneMeal')"><x-filament::input type="text" wire:model="oneMeal" placeholder="4:00" /></x-filament::input.wrapper>
                    @error('oneMeal') <span class="text-sm text-danger-600">{{ $message }}</span> @enderror
                </label>
                <label class="grid gap-1">
                    <span class="text-sm font-semibold">2 jedlá od</span>
                    <x-filament::input.wrapper :valid="! $errors->has('twoMeals')"><x-filament::input type="text" wire:model="twoMeals" placeholder="10:00" /></x-filament::input.wrapper>
                    @error('twoMeals') <span class="text-sm text-danger-600">{{ $message }}</span> @enderror
                </label>
            </div>
            <span class="-mt-4 text-sm text-gray-500">Počíta sa čas „Celkom“ z dochádzky (bez automatického odpočtu obeda). Max. jedál za deň pri zamestnancovi to môže znížiť.</span>

            <label class="grid gap-1">
                <span class="text-sm font-semibold">Povolené IP adresy mosta (nepovinné)</span>
                <x-filament::input.wrapper :valid="! $errors->has('allowlist')"><x-filament::input type="text" wire:model="allowlist" placeholder="napr. 203.0.113.10 (verejná adresa firmy)" /></x-filament::input.wrapper>
                <span class="text-sm text-gray-500">Verejné IP adresy alebo rozsahy (CIDR) oddelené čiarkou. Prázdne = z akejkoľvek adresy (token je vždy povinný).</span>
                @error('allowlist') <span class="text-sm text-danger-600">{{ $message }}</span> @enderror
            </label>

            <div><x-filament::button type="submit">Uložiť</x-filament::button></div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
