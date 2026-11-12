# Vzorové súbory na import (prezentácia / skúšanie)

Vymyslené údaje, ktoré sa dajú hneď naimportovať. Vygenerované skriptom `php tools/make-samples.php`.

| Súbor | Kam | Čo ukáže |
|---|---|---|
| `zamestnanci.xlsx` (alebo `.csv`) | *Zamestnanci → Import z XLSX/CSV* | 82 zamestnancov (osobné čísla 2001–2082) v 10 strediskách, z toho 2 neaktívni. Každý dostane PIN automaticky → potom **Nevytlačené lístky (80)** |
| `zamestnanci_zmeny.xlsx` | ten istý import, **po** prvom | Kontrola zmien: 2 noví, 3 zmeny (presun do iného strediska, zmena priezviska, deaktivácia), 1 zamestnanec chýba v súbore → ponuka *Deaktivovať* |
| `jedalny_listok_4_tyzdne.xlsx` (alebo `.csv`) | *Jedálny lístok → Import lístka* | 4 týždne od 9. 11. 2026: každý deň polievka, obed 1, obed 2, večera; alergény v zátvorke. 2 dni sú zámerne bez alergénov (funguje aj tak) |
| `jedalny_listok_s_chybami.csv` | *Import lístka* | Hlásenie chýb po riadkoch: zlý dátum, alergén 15, duplicitný deň. Správne riadky sa dajú uložiť |
| `zamestnanci_skupiny.csv` | *Zamestnanci → Import*, **po** `zamestnanci.xlsx` | Stĺpec `skupina`: 2001–2020 → „12 h“, 2021–2030 → „8 h“ (skupiny najprv vytvorte v *Skupiny stravníkov*, „12 h“ v režime lístkov). Zmena platí od 1. dňa budúceho mesiaca |
| `konecne_listky_2026-11.xlsx` (alebo `.csv`) | *Objednávky → Konečné lístky (import)*, mesiac november 2026 | Konečný počet lístkov (14 – 22) pre 20 ľudí zo skupiny „12 h“; náhľad ukáže, komu sa zmenia ceny, potom *Uzavrieť mesiac* |

Objednávky sa importovať nedajú — tie vzniknú objednávaním v aplikácii (alebo cez *Objednať za zamestnanca*).
