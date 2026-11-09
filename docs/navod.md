# Jedáleň – návod pre správu (administrátor, kuchyňa, mzdy)

Správa je na adrese **https://<adresa-aplikácie>/admin**. Prihlasujete sa e-mailom a heslom.
Zamestnanci objednávajú na hlavnej adrese (bez `/admin`) osobným číslom a PINom.

> Návod sa dopĺňa s každou verziou.

## Kto čo môže

| Rola | Môže |
|---|---|
| **Kuchyňa** | denný prehľad, tlač, uzamknutie/odomknutie, jedálny lístok (týždeň, import), prezerať objednávky a uzavreté dni |
| **Mzdy** | prezerať objednávky, denný prehľad a ceny; exporty pre mzdy |
| **Administrátor** | všetko: zamestnanci, PINy, ceny, uzávierky, nastavenia, opravy objednávok, správcovia, história zmien |

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
- Veľké čísla: **spolu** a počet pre každé menu (z toho extra). Stránka sa sama obnovuje každú minútu.
- Pri obede je uvedená aj **polievka** dňa (podáva sa každému, v zozname sa nepočíta).
- **Zoznam** je zoradený podľa priezviska; políčko *Hľadať meno…* nájde človeka aj bez diakritiky
  (napr. „simko“). Extra jedlá majú značku **Extra**.

### Tlač, PDF, Excel

- **Tlačiť** otvorí stránku na tlač a hneď ponúkne tlač (A4, dva stĺpce, štvorček na odškrtnutie výdaja,
  v hlavičke dátum, jedlo a čas tlače, dole súčty za každé menu). Približne 50 – 60 ľudí sa zmestí na 1 stranu.
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
- **Prístupový lístok** (meno, osobné číslo, **súčasný PIN**, QR kód s adresou, postup „pridať na plochu“; 8 lístkov na A4):
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

**Nastavenia → Ceny**. Hore je **aktuálna cena** darovaného a extra jedla.
Zmena ceny = **Nová cena** s dátumom *Platí od* (deň jedla, najskôr zajtra). Staré objednávky si
ponechajú cenu, za ktorú boli objednané. Cenu, ktorá už platí, nemožno meniť — zadajte novú.

## Uzávierky a nastavenia (administrátor)

- **Nastavenia → Jedlá a uzávierky:** dokedy sa dá objednať obed/večeru (v ten istý deň alebo deň vopred, čas).
- **Nastavenia → Objednávanie:** koľko dní dopredu sa dá objednať a voliteľne najviac koľko extra jedál (6 €)
  môže mať zamestnanec na jedno jedlo a deň (okrem jedného darovaného). Prázdne = bez obmedzenia (predvolené).

**Ako sa určuje cena:** každý deň je prvý obed a prvá večera zamestnanca za 1,50 € (darované), každé ďalšie jedlo
za 6,00 € (extra). Aplikácia nevie, či mal zamestnanec 8- alebo 12-hodinovú smenu (pri 8 h má nárok len na jedno
darované jedlo), preto je pri mesačných súhrnoch upozornenie *„Cena stravy je odhadom a po porovnaní s dochádzkou
sa môže zmeniť.“* Úpravu podľa dochádzky robia mzdy.

## Objednávky

**Objednávky → Všetky objednávky** — filtre vpravo hore (obdobie, jedlo, typ, stav, zamestnanec), vyhľadávanie podľa mena alebo osobného čísla.

Administrátor navyše:

- **Objednať za zamestnanca** (pre ľudí bez telefónu): zamestnanec, dátum, jedlo, menu. Typ ceny sa určí sám
  (prvé jedlo darované, ďalšie extra) a zobrazí sa pred uložením.
- Pri objednávke **Opraviť → Zmeniť jedlo / Zmeniť typ ceny / Zrušiť**.
  Po uzávierke alebo uzamknutí je **dôvod povinný**; pri zmene typu ceny vždy.

## Exporty pre mzdy (mzdy, administrátor)

**Objednávky → Exporty**

### Mesačný prehľad

Vyberte **mesiac** (prvých 10 dní v mesiaci je predvolený minulý mesiac) → **Stiahnuť Excel**.
Súbor `stravovanie_RRRR-MM_mesacny.xlsx` má dva hárky:

- **Mesiac** – pre každého zamestnanca blok: v hlavičke dni v týždni (víkendy sú podfarbené), pod tým
  počet darovaných a extra jedál v každom dni (obed + večera v ten istý deň = 2), vpravo *Spolu* a *Suma €*.
  Pri zaškrtnutí **Rozdeliť na obed / večeru** sú riadky zvlášť pre obed a večeru.
  Tlačí sa na A4 na šírku, celý mesiac na šírku strany.
- **Súhrn** – jeden riadok na zamestnanca: osobné číslo, priezvisko, meno, stredisko, počty a sumy
  darovaných a extra jedál, celková suma. Tento hárok je určený na prenos do miezd.

Počítajú sa iba platné (nezrušené) objednávky, a to za cenu, za ktorú boli objednané.
V prehľade sú všetci, kto v mesiaci jedli (aj ľudia, ktorí medzičasom odišli). **Aj aktívni zamestnanci bez
objednávok** pridá aj tých, ktorí nejedli ani raz (s nulami).

### Zoznam objednávok

Zvoľte obdobie (max. 1 rok) a formát **Excel** alebo **CSV** → **Stiahnuť**. Jeden riadok na objednávku,
aj zrušené (stĺpec `stav`), s osobným číslom, strediskom, menu, typom, cenou a časom vytvorenia/zrušenia —
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
- Ťuknite znova na objednané jedlo → **Zrušiť objednávku?** → *Áno, zrušiť*.
- Ťuknete na iné jedlo, keď už máte objednané → *Zmeniť na …* (vymení jedlo) alebo
  *Pridať extra za 6,00 €* (druhé jedlo navyše).
- **Polievka** sa podáva ku každému obedu, neobjednáva sa.
- Objednávať a rušiť sa dá do uzávierky (obed do 9:00, večera do 14:00 v ten istý deň — ak nie je určené
  inak; čas vidíte pri jedle: *otvorené do …*). Potom je jedlo *uzamknuté*; zmenu urobí už len administrátor.

## Cena

- **1,50 €** — prvý obed a prvá večera v daný deň (darované).
- **6,00 €** — každé ďalšie jedlo v ten istý deň a jedlo (extra). Pred objednaním extra jedla sa vždy ukáže cena.
- V časti **Môj mesiac** vidíte všetky objednávky a sumu za mesiac.
  *Cena stravy je odhadom a po porovnaní s dochádzkou sa môže zmeniť* (napr. pri 8-hodinovej zmene patrí
  jedno darované jedlo denne).

## Účet a PIN

- **Účet → Zmena PIN**: zadajte súčasný PIN a dvakrát nový (4 – 6 číslic). Na ostatných zariadeniach budete odhlásený.
- **Zabudnutý PIN:** povedzte vedúcemu/administrátorovi — dostanete nový lístok.
- Na **spoločnom telefóne alebo počítači** sa po objednaní odhláste (*Účet → Odhlásiť sa*).
- Po viacerých nesprávnych PINoch sa prihlásenie na chvíľu zablokuje (1 až 15 minút).

