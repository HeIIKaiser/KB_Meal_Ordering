# Jedáleň – návod pre správu (administrátor, kuchyňa, mzdy)

Správa je na adrese **https://<adresa-aplikácie>/admin**. Prihlasujete sa e-mailom a heslom.
Zamestnanci objednávajú na hlavnej adrese (bez `/admin`) osobným číslom a PINom.

> Návod sa dopĺňa s každou verziou. Časti pre kuchyňu (denný prehľad, tlač, uzamknutie) pribudnú v ďalšej verzii.

## Kto čo môže

| Rola | Môže |
|---|---|
| **Kuchyňa** | jedálny lístok (týždeň, import), prezerať objednávky a zatvorené dni |
| **Mzdy** | iba prezerať objednávky a ceny (exporty pribudnú) |
| **Administrátor** | všetko: zamestnanci, PINy, ceny, uzávierky, nastavenia, opravy objednávok, používatelia správy, história zmien |

Jeden človek môže mať viac rolí.

## Dvojstupňové overenie (2FA) – nepovinné

Kto chce, zapne si ho vpravo hore → ikona účtu → **Profil** → overovacia aplikácia (napr. Google Authenticator).
Uložte si záložné kódy. Ak niekto stratí telefón, administrátor mu 2FA vypne v časti
**Používatelia správy → Vypnúť 2FA**.

## Zamestnanci (administrátor)

**Ľudia → Zamestnanci**

- **Nový zamestnanec:** tlačidlo *Nový zamestnanec* → osobné číslo, priezvisko, meno, stredisko → Uložiť.
  Potom tlačidlo **Reset PIN** vytvorí PIN a otvorí lístok na tlač.
- **Odchod zamestnanca:** nemazať — vypnúť **Aktívny**. Prihlásenie prestane fungovať, história objednávok zostane.
- **Zabudnutý PIN:** pri zamestnancovi **Reset PIN** → vytlačiť lístok a odovzdať. Starý PIN prestane platiť
  a zamestnanec sa odhlási na všetkých zariadeniach.
- **Prístupové lístky pre viac ľudí naraz:** v zozname zaškrtnite zamestnancov → *Hromadné akcie* →
  **Nové PINy + prístupové lístky** → *Vygenerovať a tlačiť*. Na A4 sa zmestí 8 lístkov (meno, osobné číslo,
  PIN, QR kód s adresou, postup „pridať na plochu“).
  **PINy sa zobrazia iba raz.** Ak stránku zavriete bez tlače, treba PINy vygenerovať znova.

### Import zamestnancov z Excelu

*Zamestnanci → Import z XLSX/CSV*

1. Stiahnite **šablónu** alebo použite vlastný súbor so stĺpcami `osobne_cislo`, `priezvisko`, `meno`,
   `stredisko` a voliteľne `aktivny` (áno/nie). Na veľkosti písmen a diakritike v názvoch stĺpcov nezáleží.
2. Vyberte súbor → **Načítať a skontrolovať**. Uvidíte, kto pribudne, čo sa zmení a riadky s chybou.
3. Ak chcete vypnúť ľudí, ktorí v súbore nie sú (odišli), zaškrtnite **Deaktivovať …** (zoznam mien je pod tým).
4. **Uložiť zmeny.** Nikto sa nemaže. Novým zamestnancom potom vytlačte lístky s PINmi.

## Jedálny lístok (kuchyňa, administrátor)

**Jedálny lístok → Jedálny lístok** — tabuľka na celý týždeň (predvolene budúci).

- Do políčok píšte názov jedla, vedľa alergény (čísla 1 – 14 oddelené čiarkou, napr. `1,3,7`).
- **+ ďalšie menu** pridá riadok (Menu 4, 5, …).
- **Doplniť z minulého týždňa** skopíruje jedlá do prázdnych políčok (nič neprepíše).
- **Uložiť týždeň.**
- Vymazaný názov = jedlo sa z ponuky odstráni.
- Ak meníte alebo odstraňujete jedlo, ktoré si už niekto objednal, aplikácia ukáže **zoznam dotknutých
  zamestnancov** a čaká na potvrdenie. Ich objednávky zostávajú platné — odporúčame ich informovať.

### Import jedálneho lístka

**Jedálny lístok → Import lístka**: súbor XLSX/CSV so stĺpcami `datum` (16.11.2026), `jedlo` (obed / vecera),
`cislo_menu`, `nazov`, `alergeny`. Postup ako pri zamestnancoch: načítať → skontrolovať → uložiť.
Riadky s chybou sa preskočia a sú vypísané s číslom riadku.

## Zatvorené dni (administrátor)

**Jedálny lístok → Zatvorené dni → Nový**: dátum, jedlo (prázdne = celý deň), poznámka (napr. *Sviatok*).
Zamestnanci v ten deň uvidia „zatvorené“ s poznámkou a nemôžu objednať.

## Ceny (administrátor; mzdy iba čítajú)

**Nastavenia → Ceny**. Hore je **aktuálna cena** darovaného a extra jedla.
Zmena ceny = **Nová cena** s dátumom *Platí od* (deň jedla, najskôr zajtra). Staré objednávky si
ponechajú cenu, za ktorú boli objednané. Cenu, ktorá už platí, nemožno meniť — zadajte novú.

## Uzávierky a nastavenia (administrátor)

- **Nastavenia → Jedlá a uzávierky:** dokedy sa dá objednať obed/večeru (v ten istý deň alebo deň vopred, čas).
- **Nastavenia → Objednávanie:** koľko dní dopredu sa dá objednať a koľko extra jedál (6 €) môže mať
  zamestnanec na jedno jedlo a deň (okrem jedného darovaného).

## Objednávky

**Objednávky → Všetky objednávky** — filtre vpravo hore (obdobie, jedlo, typ, stav, zamestnanec), vyhľadávanie podľa mena alebo osobného čísla.

Administrátor navyše:

- **Objednať za zamestnanca** (pre ľudí bez telefónu): zamestnanec, dátum, jedlo, menu. Typ ceny sa určí sám
  (prvé jedlo darované, ďalšie extra) a zobrazí sa pred uložením.
- Pri objednávke **Opraviť → Zmeniť jedlo / Zmeniť typ ceny / Zrušiť**.
  Po uzávierke alebo uzamknutí je **dôvod povinný**; pri zmene typu ceny vždy.

## Používatelia správy (administrátor)

**Ľudia → Používatelia správy**: meno, e-mail, role, heslo (min. 12 znakov). Nikoho nemažte — vypnite *Aktívny*.
Sami sebe nemôžete odobrať rolu administrátora.

## História zmien (administrátor)

**Nastavenia → História zmien**: kto, kedy a čo zmenil (objednávky, ceny, jedálny lístok, zamestnanci,
uzamknutia, nastavenia) vrátane dôvodu opravy. Detail záznamu ukáže hodnoty pred a po zmene.
