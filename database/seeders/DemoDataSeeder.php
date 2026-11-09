<?php

namespace Database\Seeders;

use App\Domain\Ordering\PriceResolver;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\ClosedDay;
use App\Models\Employee;
use App\Models\MealSlot;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PriceType;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * Realistic Slovak demo data: ~80 employees, staff accounts, menus for the
 * previous, current and next week, and orders. NEVER run in production.
 *
 * Demo logins:  employees 1001–1080, PIN 1234
 *               admin@jedalen.test / kuchyna@jedalen.test / mzdy@jedalen.test, password "heslo1234"
 */
class DemoDataSeeder extends Seeder
{
    private const FIRST_M = ['Ján', 'Peter', 'Martin', 'Tomáš', 'Michal', 'Jozef', 'Marek', 'Lukáš', 'Juraj', 'Milan', 'Pavol', 'Ľubomír', 'Štefan', 'Dušan', 'Matej', 'Ondrej', 'Rastislav', 'Vladimír', 'Igor', 'Roman'];

    private const FIRST_F = ['Mária', 'Anna', 'Zuzana', 'Katarína', 'Jana', 'Eva', 'Lucia', 'Martina', 'Ivana', 'Monika', 'Simona', 'Veronika', 'Andrea', 'Ľudmila', 'Alžbeta', 'Dagmar', 'Silvia', 'Barbora', 'Petra', 'Žaneta'];

    // [masculine, feminine]
    private const LAST = [
        ['Novák', 'Nováková'], ['Kováč', 'Kováčová'], ['Horváth', 'Horváthová'], ['Varga', 'Vargová'], ['Tóth', 'Tóthová'],
        ['Baláž', 'Balážová'], ['Szabó', 'Szabóová'], ['Molnár', 'Molnárová'], ['Šimko', 'Šimková'], ['Lukáč', 'Lukáčová'],
        ['Kráľ', 'Kráľová'], ['Čierny', 'Čierna'], ['Hudák', 'Hudáková'], ['Polák', 'Poláková'], ['Oravec', 'Oravcová'],
        ['Kollár', 'Kollárová'], ['Gajdoš', 'Gajdošová'], ['Šťastný', 'Šťastná'], ['Žiak', 'Žiaková'], ['Chovanec', 'Chovancová'],
        ['Ďurica', 'Ďuricová'], ['Mikuš', 'Mikušová'], ['Bartoš', 'Bartošová'], ['Urban', 'Urbanová'], ['Ťapák', 'Ťapáková'],
    ];

    private const DEPARTMENTS = ['Kuchyňa', 'Recepcia', 'Wellness', 'Balneoterapia', 'Lekári', 'Sestry', 'Údržba', 'Upratovanie', 'Ekonomické', 'Reštaurácia'];

    private const LUNCH = [
        ['Hovädzí guláš, knedľa', '1,3,7,9'], ['Kuracie prsia na prírodno, ryža', '7'], ['Bryndzové halušky so slaninou', '1,3,7'],
        ['Rybie prsty, zemiaková kaša', '1,3,4,7'], ['Segedínsky guláš, knedľa', '1,3,7'], ['Vyprážaný bravčový rezeň, zemiakový šalát', '1,3,7,10'],
        ['Zeleninové rizoto so syrom', '7,9'], ['Sviečková na smotane, knedľa', '1,3,7,9,10'], ['Pečené kuracie stehno, dusená ryža', '9'],
        ['Šošovicový prívarok, vajce, chlieb', '1,3'], ['Plnená paprika, paradajková omáčka, zemiaky', '1,3,7'], ['Granadír', '1,3'],
        ['Kuracie na paprike, cestovina', '1,3,7'], ['Morčacie soté so zeleninou, ryža', '6,9'], ['Vyprážaný syr, hranolky, tatárska omáčka', '1,3,7,10'],
    ];

    private const DINNER = [
        ['Zapekané cestoviny so šunkou', '1,3,7'], ['Palacinky s džemom', '1,3,7'], ['Krupicová kaša s kakaom', '1,7'],
        ['Zeleninový šalát s kuracím mäsom', '3,10'], ['Pizza toast so syrom', '1,7'], ['Ryžový nákyp s ovocím', '3,7'],
        ['Pečené zemiaky s tvarohom', '7'], ['Hrachová kaša, párok', '1,9'],
    ];

    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);
        mt_srand(20261109);

        $this->staff();
        $employees = $this->employees();
        $this->menusAndOrders($employees);
    }

    private function staff(): void
    {
        foreach ([
            ['Administrátor', 'admin@jedalen.test', [Role::Admin]],
            ['Kuchyňa', 'kuchyna@jedalen.test', [Role::Kitchen]],
            ['Mzdy', 'mzdy@jedalen.test', [Role::Payroll]],
        ] as [$name, $email, $roles]) {
            User::query()->updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make('heslo1234'),
                'roles' => array_map(fn (Role $r) => $r->value, $roles),
                'is_active' => true,
            ]);
        }
    }

    /** @return Collection<int, Employee> */
    private function employees(): Collection
    {
        $pin = Hash::make('1234');

        for ($i = 1; $i <= 80; $i++) {
            $female = $i % 2 === 0;
            $last = self::LAST[mt_rand(0, count(self::LAST) - 1)][$female ? 1 : 0];
            $first = ($female ? self::FIRST_F : self::FIRST_M)[mt_rand(0, 19)];

            $employee = Employee::query()->firstOrNew(['osobne_cislo' => (string) (1000 + $i)]);
            $employee->fill([
                'first_name' => $first,
                'last_name' => $last,
                'department' => self::DEPARTMENTS[mt_rand(0, count(self::DEPARTMENTS) - 1)],
                'is_active' => $i <= 78, // two former employees
            ]);
            $employee->pin_hash = $pin;
            $employee->save();
        }

        return Employee::query()->active()->get();
    }

    private function menusAndOrders(Collection $employees): void
    {
        $prices = app(PriceResolver::class);
        $donated = PriceType::byCode(PriceType::DONATED);
        $extra = PriceType::byCode(PriceType::EXTRA);
        $slots = [MealSlot::LUNCH => [self::LUNCH, 3, 0.75], MealSlot::DINNER => [self::DINNER, 2, 0.3]];

        $today = LocalTime::today();
        $from = $today->startOfWeek()->subWeek();
        $to = $today->startOfWeek()->addWeeks(2)->subDay();

        // A closed dinner on next week's Wednesday.
        ClosedDay::query()->firstOrCreate(
            ['date' => $today->startOfWeek()->addWeek()->addDays(2)->toDateString(), 'meal_slot_id' => MealSlot::query()->where('code', MealSlot::DINNER)->value('id')],
            ['note' => 'Sanitárny deň – večera sa nevydáva'],
        );

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            foreach ($slots as $code => [$dishes, $options, $share]) {
                $slot = MealSlot::query()->where('code', $code)->firstOrFail();
                $menu = Menu::query()->firstOrCreate(['date' => $day->toDateString(), 'meal_slot_id' => $slot->id]);

                if (! $menu->items()->exists()) {
                    foreach (range(1, $options) as $n) {
                        [$name, $allergens] = $dishes[($day->dayOfYear * $options + $n) % count($dishes)];
                        MenuItem::query()->create(['menu_id' => $menu->id, 'number' => $n, 'name' => $name, 'allergens' => $allergens]);
                    }
                }

                // Orders only up to tomorrow — later days are left for trying out the app.
                if ($day->gt($today->addDay()) || Order::query()->where('date', $day->toDateString())->where('meal_slot_id', $slot->id)->exists()) {
                    continue;
                }

                $items = $menu->items()->get();
                foreach ($employees as $employee) {
                    if (mt_rand() / mt_getrandmax() > $share * ($day->isWeekend() ? 0.4 : 1)) {
                        continue;
                    }
                    $this->order($employee, $day, $slot, $items->random(), $donated, $prices->amountFor($donated, $day));

                    if (mt_rand(1, 100) <= 4) {
                        $this->order($employee, $day, $slot, $items->random(), $extra, $prices->amountFor($extra, $day));
                    }
                }
            }
        }
    }

    private function order(Employee $employee, $day, MealSlot $slot, MenuItem $item, PriceType $type, int $price): void
    {
        $cancelled = mt_rand(1, 100) <= 3 && $type->isDonated() === false;

        Order::query()->create([
            'employee_id' => $employee->id,
            'date' => $day->toDateString(),
            'meal_slot_id' => $slot->id,
            'menu_item_id' => $item->id,
            'price_type_id' => $type->id,
            'unit_price_cents' => $price,
            'status' => $cancelled ? OrderStatus::Cancelled : OrderStatus::Active,
            'cancelled_at' => $cancelled ? now() : null,
        ]);
    }
}
