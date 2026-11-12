# Jedáleň – návod pre správu (administrátor, kuchyňa, mzdy)

Správa je na adrese **https://<adresa-aplikácie>/admin**. Prihlasujete sa e-mailom a heslom.
Zamestnanci objednávajú na hlavnej adrese (bez `/admin`) osobným číslom a PINom.

> Návod sa dopĺňa s každou verziou.

## Kto čo môže

| Rola | Môže |
|---|---|
| **Kuchyňa** | denný prehľad, tlač, uzamknutie/odomknutie, jedálny lístok (týždeň, import), prezerať objednávky a uzavreté dni |
| **Mzdy** | prezerať objednávky, denný prehľad, ceny, skupiny stravníkov, lístky na mesiac a stav mesiaca; exporty pre mzdy |
| **Administrátor** | všetko: zamestnanci, skupiny a lístky, uzavretie mesiaca, PINy, ceny, uzávierky, nastavenia, opravy objednávok, správcovia, história zmien |

Jeden človek môže mať viac rolí.

## Dvojstupňové overenie (2FA) – nepovinné

Kto chce, zapne si ho vpravo hore → ikona účtu → **Profil** → overovacia aplikácia (napr. Google Authenticator).
Uložte si záložné kódy. Ak niekto stratí telefón, administrátor mu 2FA vypne v časti
**Správcovia → Vypnúť 2FA**.

## Denný prehľad – kuchyňa

**Objednávky → Denný prehľad (kuchyňa)** — otvára sa na dnešok a obed. Na úvodnej **Nástenke** sú počty
na dnes a zajtra; kliknutím sa otvorí príslušný prehľad.

- Hore vyberte **deň** (šípky alebo kalendár, *Dnes*) a **jedlo** (Obed / Večera).
- Pod nadpisom je stav: *Objednávky otvorené* (počty sa ešte môžu meniť do uzávierky), *Po uzávierke*,
  *Uzamknuté* (kto a kedy) alebo *Uzavreté*.
- Veľké čísla: **spolu** a počet pre každé menu (z toho dokupovaná strava). Stránka sa sama obnovuje každú minútu.
- Pri obede je uvedená aj **polievka** dňa (podáva sa každému, v zozname sa nepočíta).
- **Zoznam** je zoradený podľa priezviska; políčko *Hľadať meno…* nájde človeka aj bez diakritiky
  (napr. „simko“). Extra jedlá majú značku **Extra**.

### Tlač, PDF, Excel

- **Tlačiť** otvorí stránku na tlač a hneď ponúkne tlač (A4, **jeden riadok na zamestnanca** s celým názvom jedla,
  striedavo biele a sivé riadky, štvorček na odškrtnutie výdaja, v hlavičke dátum, jedlo a čas tlače, dole súčty
  za každé menu). Približne 33 ľudí na stranu; hlavička tabuľky sa opakuje na každej strane.
- **PDF** – rovnaký zoznam ako súbor (napr. na poslanie e-mailom).
- **Excel** – rovnaký zoznam s osobnými číslami a strediskami, dole súčty.

Ak tlačíte pred uzávierkou, v hlavičke je upozornenie „Objednávky ešte otvorené do …“.

### Uzamknúť / Odomknúť

- **Uzamknúť** — zamestnanci od tej chvíle nemôžu na tento deň a jedlo objednávať ani rušiť
  (napr. keď kuchyňa začne variť skôr). Zaznamená sa, kto a kedy uzamkol.
- **Odomknúť** zruší ručné uzamknutie. Po automatickej uzávierke sa tým objednávanie neotvorí —
  neskoré zmeny robí administrátor v časti *Všetky objednávky* (s dôvodom).

## Zamestnanci (administrátor)

**Ľudia → Zamestnanci**

- **Nový zamestnanec:** tlačidlo *Nový zamestnanec* → osobné číslo, priezvisko, meno, stredisko → Uložiť.
  **PIN sa vygeneruje automaticky** — v oznámení kliknite na **Vytlačiť lístok**.
- **Prístupový list** — **jedna strana A4 na zamestnanca**: meno, osobné číslo a **súčasný PIN** veľkým písmom,
  QR kód s adresou a podrobný návod (prvé prihlásenie, ikona na ploche, objednávanie, uzávierky a ceny podľa
  aktuálnych nastavení, PIN). Ak treba šetriť papier, na stránke tlače je odkaz *Malé lístky (8 na A4)*:
  - jednému zamestnancovi: v riadku tlačidlo **Lístok** (alebo *Tlačiť lístok* pri úprave),
  - viacerým naraz: zaškrtnite ich → *Hromadné akcie* → **Tlačiť prístupové lístky**,
  - všetkým, ktorým ešte lístok nebol vytlačený (napr. po importe): tlačidlo **Nevytlačené lístky (N)** hore.

  Tlač lístka PIN **nemení** — dá sa vytlačiť znova kedykoľvek. Stĺpec **PIN** ukazuje, či sa PIN dá vytlačiť:
  *na lístku* = áno; *zmenil zamestnanec* = zamestnanec si PIN zmenil sám, aplikácia ho nepozná (na lístku je
  len poznámka); *neznámy* = zamestnanec bol založený pred touto funkciou — vydajte mu nový PIN (*Reset PIN*).
  Lístky obsahujú PIN — odovzdávajte ich osobne.
- **Zabudnutý PIN / nový PIN:** pri zamestnancovi **Reset PIN** (viacerým naraz *Nové PINy + prístupové lístky*)
  → otvorí sa lístok s novým PINom. Starý PIN prestane platiť a zamestnanec sa odhlási na všetkých zariadeniach.
- **Odchod zamestnanca:** nemazať — vypnúť **Aktívny**. Prihlásenie prestane fungovať, história objednávok zostane.

### Import zamestnancov z Excelu

*Zamestnanci → Import z XLSX/CSV*

1. Stiahnite **šablónu** alebo použite vlastný súbor so stĺpcami `osobne_cislo`, `priezvisko`, `meno`,
   `stredisko` a voliteľne `aktivny` (áno/nie). Na veľkosti písmen a diakritike v názvoch stĺpcov nezáleží.
2. Vyberte súbor → **Načítať a skontrolovať**. Uvidíte, kto pribudne, čo sa zmení a riadky s chybou.
3. Ak chcete vypnúť ľudí, ktorí v súbore nie sú (odišli), zaškrtnite **Deaktivovať …** (zoznam mien je pod tým).
4. **Uložiť zmeny.** Nikto sa nemaže. **Noví zamestnanci dostanú PIN automaticky** — v oznámení kliknite na
   **Vytlačiť lístky nových** (alebo neskôr tlačidlo *Nevytlačené lístky*).

## Jedálny lístok (kuchyňa, administrátor)

**Jedálny lístok → Jedálny lístok** — tabuľka na celý týždeň (predvolene budúci).

- Pri obede je prvý riadok **P = polievka** (podáva sa ku každému obedu, zamestnanci ju vidia, ale neobjednávajú),
  pod ňou **Menu 1** a **Menu 2**. Pri večeri je jedno jedlo.
- Do políčok píšte názov jedla, vedľa alergény (čísla 1 – 14 oddelené čiarkou, napr. `1,3,7`).
- **+ ďalšie menu** pridá riadok (Menu 4, 5, …).
- **Doplniť z minulého týždňa** skopíruje jedlá do prázdnych políčok (nič neprepíše).
- **Uložiť týždeň.**
- Vymazaný názov = jedlo sa z ponuky odstráni.
- Ak meníte alebo odstraňujete jedlo, ktoré si už niekto objednal, aplikácia ukáže **zoznam dotknutých
  zamestnancov** a čaká na potvrdenie. Ich objednávky zostávajú platné — odporúčame ich informovať.

### Tlač jedálneho lístka na celý týždeň

V tabuľke týždňa tlačidlo **Tlačiť týždeň** (alebo **PDF**): A4 na šírku, pondelok – nedeľa, polievka, Menu 1,
Menu 2, večera s alergénmi a vysvetlivkami alergénov dole. Uzavreté dni sú vyznačené. Vhodné na nástenku.
Tlačte na šírku.

### Import jedálneho lístka z Excelu

**Jedálny lístok → Import lístka**. Najjednoduchšie je stiahnuť **šablónu** a vyplniť ju:
**jeden riadok = jeden deň**, stĺpce

| datum | polievka | obed_1 | obed_2 | vecera |
|---|---|---|---|---|
| 16.11.2026 | Fazuľová polievka (1,9) | Hovädzí guláš, knedľa (1,3,7) | Zeleninové rizoto (7,9) | Palacinky s džemom (1,3,7) |

- Alergény píšte do zátvorky za jedlo (alebo do samostatného stĺpca, napr. `obed_1_alergeny`).
- Prázdna bunka nič nemení — napr. ak chcete doplniť len večeru.
- Postup: vybrať súbor → **Načítať a skontrolovať** → skontrolovať zoznam (nové / zmena / bez zmeny, chybné riadky
  s číslom riadku) → **Uložiť jedálny lístok**. Potom sa otvorí týždeň v tabuľke na kontrolu.
- Súbor môže byť XLSX alebo CSV. Funguje aj starší formát (jeden riadok na jedlo: `datum, jedlo, cislo_menu, nazov, alergeny`).

## Uzavreté dni (administrátor)

**Jedálny lístok → Uzavreté dni → Nový**: dátum, jedlo (prázdne = celý deň), poznámka (napr. *Sviatok*).
Zamestnanci v ten deň uvidia „uzavreté“ s poznámkou a nemôžu objednať.

## Ceny (administrátor; mzdy iba čítajú)

**Nastavenia → Ceny**. Hore je **aktuálna cena** zamestnaneckej a dokupovanej stravy.
Zmena ceny = **Nová cena** s dátumom *Platí od* (deň jedla, najskôr zajtra). Staré objednávky si
ponechajú cenu, za ktorú boli objednané. Cenu, ktorá už platí, nemožno meniť — zadajte novú.

## Uzávierky a nastavenia (administrátor)

- **Nastavenia → Jedlá a uzávierky:** dokedy sa dá objednať obed/večeru (v ten istý deň alebo deň vopred, čas).
- **Nastavenia → Objednávanie:** koľko dní dopredu sa dá objednať a voliteľne najviac koľko dokupovaných jedál (6 €)
  môže mať zamestnanec na jedno jedlo a deň (okrem zamestnaneckej stravy). Prázdne = bez obmedzenia (predvolené).
  Tu je aj **Názov firmy** — zobrazí sa na prístupových listoch, na prihlasovacej stránke a pri ikone aplikácie.

**Ako sa určuje cena:** každý deň je prvý obed a prvá večera zamestnanca sú **zamestnanecká strava** za 1,50 €, každé ďalšie jedlo
je **dokupovaná strava** za 6,00 €. Aplikácia nevie, či mal zamestnanec 8- alebo 12-hodinovú smenu (pri 8 h má nárok len na jednu
zamestnaneckú stravu), preto je pri mesačných súhrnoch upozornenie *„Cena stravy je odhadom a po porovnaní s dochádzkou
sa môže zmeniť.“* Úpravu podľa dochádzky robia mzdy.

## Lístky – skupiny stravníkov (administrátor)

Popri **dennom režime** (prvý obed a prvá večera dňa za 1,50 €) môže skupina zamestnancov používať **režim lístkov**:

- Každý zamestnanec skupiny dostane na mesiac **počet lístkov**. Jeden lístok = jedno jedlo za 1,50 € (zamestnanecká strava).
- **Bez denných limitov** – v jeden deň môže mať aj 3 obedy za lístky.
- Keď lístky v mesiaci minie, každé ďalšie jedlo stojí 6,00 € (dokupovaná strava); aplikácia to pred objednaním povie.
- Lístky patria **mesiacu jedla** (nie dňu objednania), nevyužité na konci mesiaca prepadnú, nedajú sa presúvať.
- Za lístky sú vždy **najskoršie jedlá mesiaca**; za 6 € sú tie posledné. Keď zamestnanec niečo zruší alebo sa zmení počet
  lístkov, ceny v mesiaci sa hneď prepočítajú (napr. 18 lístkov a 19 jedál → posledné jedlo 6 €; zruší jedno → všetky za 1,50 €).

Kto nie je v žiadnej skupine, je v dennom režime. Kým nevytvoríte skupinu v režime lístkov, nič sa nemení.

### Nastavenie skupín

**Ľudia → Skupiny stravníkov → Vytvoriť**: názov (napr. *8 h*, *12 h*, *Brigádnici*), **lístkov na mesiac** (predvolený počet)
a režim. **Zmeniť režim** pri skupine: nový režim a dátum *Platí od* (predvolene dnes, môže byť aj v budúcnosti). Platí pre
jedlá od toho dňa, staršie si ponechajú ceny. Ak už sú od toho dňa objednané jedlá, pred uložením vidíte, koľkým zamestnancom
a objednávkam sa zmení cena a o koľko eur.

**Zaradenie zamestnanca:** v karte zamestnanca pole **Skupina stravníkov** a **Skupina platí od** – predvolene od 1. dňa
budúceho mesiaca, aby sa nezmenili už objednané jedlá. Ak zvolíte skorší dátum, pod poľom uvidíte, čo sa prepočíta.
Hromadne cez **Import zamestnancov** so stĺpcom `skupina` (názov existujúcej skupiny; zmena platí od 1. dňa budúceho mesiaca,
nový zamestnanec od začiatku tohto mesiaca; neznámy názov je chyba v riadku; prázdne = bez zmeny).
V zozname zamestnancov je stĺpec *Skupina* a filter podľa skupiny.

### Lístky na mesiac

**Objednávky → Lístky na mesiac**: zvoľte mesiac.

- Hore **skupiny v režime lístkov** s počtom lístkov na tento mesiac (predvyplnené z minulého mesiaca, inak z predvoleného počtu).
- Dole **zamestnanci** s počtom *pridelené / použité / zostáva* a stĺpcom **Individuálne** (nástup alebo odchod počas mesiaca,
  dlhšia neprítomnosť). Prázdne = počet skupiny.
- **Uložiť** uloží všetko naraz. Ak sa tým zmenia ceny už objednaných jedál, aplikácia najprv ukáže koľko a o koľko eur
  a uloží až po *Uložiť a prepočítať*.

Lístky existujú automaticky na každý mesiac (aj na budúci, na ktorý sa objednáva vopred) – nič netreba generovať.
Pri prechode skupiny na lístky v polovici mesiaca nastavte počet na zvyšok mesiaca tu.

### Koniec mesiaca: konečné lístky a uzavretie

1. Podľa dochádzky pripravte súbor (XLSX alebo CSV) so stĺpcami **`osobne_cislo`** a **`listky`**
   (ďalšie stĺpce ako `priezvisko`, `meno` sa ignorujú; oddeľovač `;` `,` alebo tabulátor; UTF-8 alebo Windows-1250;
   osobné číslo ako text, aby ostali nuly na začiatku). Šablónu stiahnete na stránke importu.

   ```
   osobne_cislo;listky;priezvisko;meno
   1001;19;Nováková;Mária
   1002;22;Kováč;Peter
   ```
2. **Objednávky → Konečné lístky (import)** → mesiac → súbor → **Načítať a skontrolovať**. Pre každého uvidíte
   lístky pred → po, počet jedál, koľkým jedlám sa zmení cena a sumu pred → po. Chyby po riadkoch (neznáme osobné číslo,
   zamestnanec nie je v režime lístkov, záporné alebo neceločíselné číslo) sa preskočia. Zamestnanci v režime lístkov,
   ktorí v súbore chýbajú, sú uvedení ako upozornenie a ponechajú si súčasný počet.
3. **Uložiť a prepočítať** – počty sa uložia ako individuálne lístky na mesiac a ceny sa prepočítajú.
4. **Uzavrieť mesiac** (ponúkne sa hneď, alebo na stránke *Lístky na mesiac*). Po uzavretí sa v mesiaci nedajú meniť
   objednávky, lístky ani typy cien – pre nikoho, ani v dennom režime. Exporty potom namiesto upozornenia na odhad
   uvádzajú *„Mesiac uzavretý – konečné ceny.“* **Otvoriť mesiac** sa dá s povinným dôvodom (zapíše sa do histórie zmien).

## Objednávky

**Objednávky → Všetky objednávky** — filtre vpravo hore (obdobie, jedlo, typ, stav, zamestnanec), vyhľadávanie podľa mena alebo osobného čísla.

Administrátor navyše:

- **Objednať za zamestnanca** (pre ľudí bez telefónu): zamestnanec, dátum, jedlo, menu. Typ ceny sa určí sám
  (prvý obed a prvá večera dňa = zamestnanecká strava, ďalšie = dokupovaná) a zobrazí sa pred uložením.
- Pri objednávke **Opraviť → Zmeniť jedlo / Zmeniť typ ceny / Zrušiť**.
  Po uzávierke alebo uzamknutí je **dôvod povinný**; pri zmene typu ceny vždy.
  V režime lístkov sa typ ceny ručne nemení – určuje ho počet lístkov (upravte ho v *Lístky na mesiac*).

## Exporty pre mzdy (mzdy, administrátor)

**Objednávky → Exporty**

### Mesačný prehľad

Vyberte **mesiac** (prvých 10 dní v mesiaci je predvolený minulý mesiac) → **Stiahnuť Excel**.
Súbor `stravovanie_RRRR-MM_mesacny.xlsx` má dva hárky:

- **Mesiac** – pre každého zamestnanca blok: v hlavičke dni v týždni (víkendy sú podfarbené), pod tým
  počet jedál zamestnaneckej a dokupovanej stravy v každom dni (obed + večera v ten istý deň = 2), vpravo *Spolu* a *Suma €*.
  Pri zaškrtnutí **Rozdeliť na obed / večeru** sú riadky zvlášť pre obed a večeru.
  Tlačí sa na A4 na šírku, celý mesiac na šírku strany.
- **Súhrn** – jeden riadok na zamestnanca: osobné číslo, priezvisko, meno, stredisko, počty a sumy
  zamestnaneckej a dokupovanej stravy, celková suma a za nimi *Skupina*, *Režim* (`denny` / `listky` / `zmiesany`
  pri zmene režimu počas mesiaca) a *Lístky pridelené / použité / prepadnuté* (len za časť mesiaca v režime lístkov).
  Tento hárok je určený na prenos do miezd.

Pri otvorenom mesiaci je v exporte upozornenie na odhad (pri lístkoch aj *„Počet lístkov sa na konci mesiaca upraví podľa
dochádzky.“*), pri uzavretom *„Mesiac uzavretý – konečné ceny.“* Stav posledných mesiacov vidíte hore na stránke Exporty.

Počítajú sa iba platné (nezrušené) objednávky, za uloženú cenu (v dennom režime cena z času objednania, v režime lístkov
aktuálny stav podľa počtu lístkov).
V prehľade sú všetci, kto v mesiaci jedli (aj ľudia, ktorí medzičasom odišli). **Aj aktívni zamestnanci bez
objednávok** pridá aj tých, ktorí nejedli ani raz (s nulami).

### Zoznam objednávok

Zvoľte obdobie (max. 1 rok) a formát **Excel** alebo **CSV** → **Stiahnuť**. Jeden riadok na objednávku,
aj zrušené (stĺpec `stav`), s osobným číslom, strediskom, menu, typom, cenou, časom vytvorenia/zrušenia a režimom (`rezim`) —
vhodné na kontingenčnú tabuľku alebo napojenie na iný systém.

Každé stiahnutie exportu sa zapíše do *Histórie zmien*.

## Správcovia (administrátor)

**Ľudia → Správcovia**: meno, e-mail, role, heslo (min. 12 znakov). Nikoho nemažte — vypnite *Aktívny*.
Sami sebe nemôžete odobrať rolu administrátora.

## Zálohy (administrátor)

**Nastavenia → Zálohy.** Databáza sa zálohuje automaticky **každú noc o 02:30** a zálohy sa uchovávajú
30 dní. Tlačidlo **Zálohovať teraz** vytvorí zálohu hneď (napr. pred väčšou zmenou), **Stiahnuť** ju uloží
do počítača. Zálohy obsahujú osobné údaje — ukladajte ich iba na bezpečné miesto.

Ak je na stránke **červené upozornenie**, nočná záloha neprebehla (pravdepodobne nebeží plánovač na serveri) —
dajte vedieť správcovi servera. Obnovu zo zálohy robí správca servera (README → Backups).

## História zmien (administrátor)

**Nastavenia → História zmien**: kto, kedy a čo zmenil (objednávky, ceny, jedálny lístok, zamestnanci,
uzamknutia, nastavenia) vrátane dôvodu opravy. Detail záznamu ukáže hodnoty pred a po zmene.

---

# Návod pre zamestnancov

*(Môžete vytlačiť a vyvesiť alebo poslať zamestnancom.)*

## Prvé prihlásenie

1. Od vedúceho dostanete **lístok** s osobným číslom, PINom a QR kódom.
2. Naskenujte **QR kód** fotoaparátom telefónu (alebo zadajte adresu z lístka).
3. Zadajte **osobné číslo** a **PIN** → *Prihlásiť sa*. Na tomto telefóne už zostanete prihlásený.
4. Pridajte si ikonu na plochu:
   - **Android (Chrome):** ponuka ⋮ → *Pridať na plochu* / *Inštalovať aplikáciu*,
   - **iPhone (Safari):** tlačidlo Zdieľať □↑ → *Pridať na plochu*.
5. Odteraz otvárajte aplikáciu ikonou **Jedáleň**.

## Objednávanie

- Hore vyberte **deň** (alebo potiahnite prstom doľava/doprava). Bodka pod dňom = máte objednané.
- **Ťuknite na jedlo = objednané.** Jedlo sa zafarbí a ukáže sa ✓ a cena.
- Ťuknite znova na objednané jedlo → *Zrušiť objednávku* alebo *Dokúpiť ďalšie rovnaké jedlo za 6,00 €*
  (napr. druhá porcia toho istého). Pri viacerých rovnakých sa ruší najprv dokupované.
- Keď zrušíte jedlo za 1,50 € a máte ešte dokupované jedlo (6,00 €) v ten istý obed/večeru, to sa automaticky
  zmení na zamestnaneckú stravu za 1,50 €.
- Ťuknete na iné jedlo, keď už máte objednané → *Zmeniť na …* (vymení jedlo) alebo
  *Dokúpiť ďalšie jedlo za 6,00 €* (jedlo navyše).
- **Polievka** sa podáva ku každému obedu, neobjednáva sa.
- Objednávať a rušiť sa dá do uzávierky (obed do 9:00, večera do 14:00 v ten istý deň — ak nie je určené
  inak; čas vidíte pri jedle: *otvorené do …*). Potom je jedlo *uzamknuté*; zmenu urobí už len administrátor.

## Cena

- **1,50 €** — prvý obed a prvá večera v daný deň (**zamestnanecká strava**).
- **6,00 €** — každé ďalšie jedlo v ten istý deň (**dokupovaná strava**). Pred objednaním sa vždy ukáže cena.
- **Ak ste v skupine s lístkami:** máte na mesiac určitý počet lístkov. Každé jedlo (aj viac v jeden deň) je za lístok
  za 1,50 €, kým lístky nemíňate; potom 6,00 €. Hore pri dňoch vidíte *„Zostáva 7 z 20 lístkov – november“*.
  Nevyužité lístky na konci mesiaca prepadnú. Ak zrušíte jedlo, lístok sa vráti.
- V časti **Môj mesiac** vidíte všetky objednávky a sumu za mesiac (pri lístkoch aj pridelené / použité / zostáva).
  *Cena stravy je odhadom a po porovnaní s dochádzkou sa môže zmeniť* (napr. pri 8-hodinovej zmene patrí
  jedna zamestnanecká strava denne).

## Účet a PIN

- **Účet → Zmena PIN**: zadajte súčasný PIN a dvakrát nový (4 – 6 číslic). Na ostatných zariadeniach budete odhlásený.
- **Zabudnutý PIN:** povedzte vedúcemu/administrátorovi — dostanete nový lístok.
- Na **spoločnom telefóne alebo počítači** sa po objednaní odhláste (*Účet → Odhlásiť sa*).
- Po viacerých nesprávnych PINoch sa prihlásenie na chvíľu zablokuje (1 až 15 minút).

