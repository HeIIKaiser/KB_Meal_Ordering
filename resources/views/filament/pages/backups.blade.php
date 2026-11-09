@php use App\Support\LocalTime; @endphp
<x-filament-panels::page>
    @if ($stale)
        <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-sm text-danger-800 dark:bg-danger-950 dark:text-danger-200">
            <strong>Pozor:</strong> posledná záloha je staršia ako 26 hodín (alebo žiadna neexistuje). Skontrolujte, či beží cron
            (<code>php artisan app:hosting-check</code>), alebo vytvorte zálohu ručne.
        </div>
    @endif

    <x-filament::section>
        <x-slot name="heading">Zálohy</x-slot>
        <x-slot name="description">
            Automaticky každú noc o 02:30, uchovávajú sa 30 dní (najmenej 7 posledných). Súbory sú v <code>{{ $directory }}</code> na serveri – nie sú prístupné z webu.
            Obsahujú osobné údaje zamestnancov; sťahovanie sa zapisuje do histórie zmien.
        </x-slot>
        <x-slot name="afterHeader">
            <x-filament::button icon="heroicon-m-plus" wire:click="backupNow" wire:loading.attr="disabled">Zálohovať teraz</x-filament::button>
        </x-slot>

        @if ($files->isEmpty())
            <p class="py-4 text-center text-gray-500">Zatiaľ žiadne zálohy.</p>
        @else
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10">
                    <tr><th class="py-2">Súbor</th><th class="py-2">Vytvorená</th><th class="py-2 text-right">Veľkosť</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($files as $f)
                        <tr>
                            <td class="py-2 font-mono text-xs">{{ $f['name'] }}</td>
                            <td class="py-2">{{ LocalTime::toLocal(\Carbon\CarbonImmutable::createFromTimestamp($f['mtime']))->format('j. n. Y H:i') }}</td>
                            <td class="py-2 text-right">{{ number_format($f['size'] / 1024, 0, ',', ' ') }} kB</td>
                            <td class="py-2 text-right">
                                <x-filament::button size="sm" color="gray" tag="a" icon="heroicon-m-arrow-down-tray" :href="route('admin.backup', $f['name'])">Stiahnuť</x-filament::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <p class="text-sm text-gray-500">Obnova zo zálohy (prepíše všetky údaje) sa robí na serveri príkazom <code>php artisan app:backup-restore &lt;súbor&gt;</code> – pozri README.</p>
</x-filament-panels::page>
