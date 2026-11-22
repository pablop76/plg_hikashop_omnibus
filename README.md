# Hikashop Omnibus Plugin

Plugin dla Joomla 6 i HikaShop wyświetlający najniższą cenę produktu z 30 dni **przed wprowadzeniem obniżki**, gdy na produkt działa rabat HikaShopa (dyrektywa Omnibus UE, art. 4 ust. 2 ustawy o informowaniu o cenach towarów i usług).

## ⚖️ Zgodność z dyrektywą Omnibus

Plugin realizuje wymóg **dyrektywy Omnibus (UE) 2019/2161** w zakresie opisanym niżej (patrz „Ograniczenia”):

✅ Informacja o najniższej cenie pojawia się **tylko przy obniżce** (rabat HikaShopa)  
✅ Pokazuje najniższą cenę z 30 dni **przed wprowadzeniem obniżki** (okno kończy się w dniu wprowadzenia obniżki, nie dziś)  
✅ Zapisuje historię cen, po których produkt był oferowany wszystkim klientom: regularnych i obniżonych rabatem HikaShopa, więc cena z poprzedniej promocji też się liczy  
✅ Zwiększenie albo zmniejszenie rabatu traktuje jako nową obniżkę (tzw. promocje kroczące)  
✅ Informacja na karcie produktu, na listach produktów, w modułach HikaShopa i na stronie koszyka  
✅ Towar oferowany krócej niż 30 dni dostaje „Najniższą cenę od wprowadzenia towaru”  
✅ Procent albo kwota rabatu HikaShopa liczone od najniższej ceny, nie od ceny regularnej  
✅ Napis zmienia się po wyborze wariantu  
✅ Wyraźna prezentacja z przekreśloną najniższą ceną  
✅ Opcjonalnie cena regularna z opisem, pokazana tak samo jak najniższa cena  

### Jak to działa?

1. **Zapisywanie cen**: wtyczka zapisuje cenę, po której produkt jest oferowany wszystkim klientom: regularną albo obniżoną rabatem HikaShopa, netto i brutto (z VAT obowiązującym w chwili zapisu). Robi to przy zapisie produktu, imporcie CSV, akcji masowej, przy każdej zmianie rabatu i stawki VAT w panelu, w każdym przebiegu crona HikaShopa i przy wyświetleniu produktu niezalogowanemu klientowi.
2. **Wykrywanie promocji**: sprawdza, czy na produkt działa rabat HikaShopa.
3. **Wyświetlanie**: przy promocji pokazuje najniższą cenę z 30 dni przed wprowadzeniem obniżki.
4. **Automatyczne ukrywanie**: gdy promocja się kończy, informacja znika.

## Funkcje

✅ Automatyczne wykrywanie aktywnych rabatów/promocji HikaShop  
✅ Wyświetlanie najniższej ceny z X dni przed obniżką (konfigurowalne)  
✅ Wsparcie dla cen brutto/netto (z podatkiem)  
✅ Pełna konfiguracja w panelu administracyjnym:
- Liczba dni do sprawdzania historii (domyślnie 30)
- Rozmiar czcionki
- Kolor tekstu
- Przekreślenie ceny
- Cena regularna z opisem (opcjonalnie)
- Marginesy górny/dolny

✅ Wielojęzyczność (PL/EN)  
✅ Automatyczna inicjalizacja historii przy instalacji  

## Wymagania

- Joomla 6.x
- HikaShop (wersja 5.x lub nowsza)
- PHP 8.0+
- MySQL 5.7+

## Instalacja

1. Pobierz najnowszą wersję
2. Zaloguj się do panelu administracyjnego Joomla
3. Przejdź do **System → Install → Extensions**
4. Wybierz pobrany plik ZIP i zainstaluj
5. Przejdź do **System → Manage → Plugins**
6. Znajdź "Omnibus - Najniższa cena z 30 dni" i włącz plugin

## Konfiguracja

Po instalacji możesz skonfigurować plugin w:  
**System → Manage → Plugins → Omnibus**

Dostępne opcje:
- **Liczba dni** - ilość dni wstecz do sprawdzania najniższej ceny (domyślnie 30)
- **Rozmiar czcionki** - rozmiar czcionki wyświetlanej ceny (np. 0.85em, 14px)
- **Kolor tekstu** - kolor tekstu (#666666)
- **Przekreślenie** - czy cena ma być przekreślona (Tak/Nie)
- **Dopasuj rabat HikaShopa** - przy produktach z napisem procent albo kwota rabatu HikaShopa (opcja HikaShopa „Wyświetl zniżkę”) są liczone od najniższej ceny, a cena sprzed rabatu bez opisu jest ukryta (domyślnie Tak, patrz niżej)
- **Pokaż cenę regularną** - nad najniższą ceną wiersz „Cena regularna: …” w tym samym wyglądzie (domyślnie Nie, tylko przy włączonym „Dopasuj rabat HikaShopa”, patrz niżej)
- **Margines górny/dolny** - marginesy elementu (np. 10px)

Na listach produktów i w modułach napis jest mniejszy i zwykłej grubości, w kroju kwoty ceny, żeby nie dominował w małych kartach (np. w karuzeli w menu). Wygląd zmienisz w CSS szablonu klasą `.hikashop-omnibus-lowest-price--list`.

### Cron HikaShopa

Cron nie jest potrzebny do poprawnego napisu. Wtyczka zapisuje zmiany od razu przy zapisie produktu, imporcie CSV, akcji masowej, zmianie stawki albo reguły VAT i każdej zmianie rabatu w panelu (formularz, przełącznik publikacji na liście, akcja masowa, usunięcie). Wyłączenie albo usunięcie rabatu od razu zapisuje cenę jego produktów po końcu promocji.

Rabat, który skończył się z datą końca, wtyczka zauważa przy najbliższym wyświetleniu produktu z rabatem niezalogowanemu klientowi, zapisie w panelu albo przebiegu crona. Cenę po końcu zapisuje wtedy z datą końca rabatu, nie z dniem zauważenia, więc okres promocji w historii się zgadza.

Cron HikaShopa (**Komponenty → HikaShop → Konfiguracja**, zakładka cron; tylko wersje płatne: Essential, Business) jest pomocniczy: zapisuje ceny zmienione wprost w bazie albo przez zewnętrzną integrację bez czekania na wyświetlenie produktu.

## Przykład działania

**Scenariusz:**
1. Produkt ma cenę bazową 100 zł
2. W ciągu 30 dni przed obniżką cena bazowa wynosiła: 120 zł, 110 zł, 100 zł
3. Najniższa cena z 30 dni przed obniżką to: **100 zł**

**Bez promocji:**
- Plugin **NIE pokazuje** żadnej informacji

**Z promocją -20%:**
```
Cena: 80 zł
Najniższa cena z 30 dni przed obniżką: 100 zł
```

## Zgodność z dyrektywą Omnibus - szczegóły

Zgodnie z art. 4 ust. 2 ustawy o informowaniu o cenach towarów i usług obok obniżonej ceny pokazuje się najniższą cenę, która obowiązywała w okresie 30 dni **przed wprowadzeniem obniżki**.

Według wyjaśnień Prezesa UOKiK („Informacja o obniżce ceny”, 2023) najniższa cena to cena, w której sklep oferował produkt, także cena z wcześniejszej promocji. Nie ma znaczenia, czy ktoś wtedy kupił ani jak długo ta cena obowiązywała (pkt V.2). Dlatego historia zawiera ceny regularne i ceny po rabacie HikaShopa.

**Przykład:**

- Produkt kosztuje 100 zł. Na Black Friday (20–30.11) jest −20%, czyli 80 zł, potem wraca do 100 zł.
- Od 5.12 rusza promocja świąteczna −10% (90 zł).
- Najniższa cena z 30 dni przed obniżką to **80 zł**, a nie 100 zł. Cena 90 zł nie jest więc obniżką względem tej ceny.

**Promocje kroczące.** Polska nie ma dla nich wyjątku: przy zwiększaniu rabatu każdą obniżkę liczy się od najniższej ceny z 30 dni przed nią (pkt III.6). Przykład UOKiK: 10 zł, od 10.06 rabat do 8 zł, od 15.06 do 4 zł. Dla obniżki z 15.06 najniższa cena to 8 zł. Wtyczka traktuje każdą zmianę wartości rabatu jako nową obniżkę.

### Od kiedy liczą się dni

Okno kończy się w chwili wprowadzenia obniżki, a nie w dniu oglądania strony. Inaczej podwyżka tuż przed promocją mogłaby zniknąć z okna, gdy promocja trwa dłużej. Termin wprowadzenia zostaje, dopóki promocja trwa na niezmienionych warunkach (pkt V.1).

- **Rabat z datą rozpoczęcia** (pole „Data rozpoczęcia” rabatu w HikaShopie): okno kończy się w tej dacie. To wariant dokładny i zalecany.
- **Rabat bez daty rozpoczęcia**: HikaShop nie zapisuje, kiedy rabat włączono, więc robi to wtyczka (tabela `#__hikashop_omnibus_discount`). Przy zapisie rabatu w panelu (formularz, przełącznik publikacji na liście, akcja masowa) zapamiętuje chwilę włączenia. Wyłączenie albo koniec rabatu kasuje ten wpis, więc ponowne włączenie liczy się jako nowa obniżka.
- **Zmiana wartości rabatu** (procent, kwota) to nowa obniżka: okno kończy się w chwili zmiany, także przy rabacie z datą rozpoczęcia.
- **Rabat zmieniony z pominięciem HikaShopa** (np. wprost w bazie) albo włączony przed aktualizacją do 1.2.0: wtyczka zauważa go przy najbliższym przebiegu crona albo wyświetleniu produktu. Rabat z datą rozpoczęcia liczy się od tej daty, rabat bez daty od chwili zauważenia, więc dla takich rabatów ustaw datę rozpoczęcia.

### Nowy towar

Towar oferowany krócej niż 30 dni przed obniżką dostaje napis „Najniższa cena od wprowadzenia towaru” z najniższą ceną od początku sprzedaży (art. 4 ust. 3 ustawy, pkt III.3 wyjaśnień). Początek sprzedaży to data utworzenia produktu w HikaShopie, a gdy później zaczyna się sprzedaż („Początek sprzedaży” produktu), ta data. Import CSV nadpisuje datę utworzenia, więc wcześniejszy wpis w historii ma pierwszeństwo.

Produkt utworzony wcześniej, a wystawiony do sprzedaży później: HikaShop nie zapisuje daty publikacji, więc ustaw „Początek sprzedaży”. Bez tego wtyczka liczy oferowanie od daty utworzenia.

Rabat obowiązujący od pierwszego dnia sprzedaży nie jest obniżką, tylko ceną wprowadzającą (UOKiK: cena „nadchodząca”, pkt III.3). Taki produkt napisu nie dostaje, a przy włączonym „Dopasuj rabat HikaShopa” wtyczka ukrywa też procent i cenę sprzed rabatu HikaShopa.

### Rabat HikaShopa przy cenie

HikaShop pokazuje (opcja „Wyświetl zniżkę”) procent albo kwotę rabatu i przekreśloną cenę sprzed rabatu, liczone od ceny regularnej. Według wyjaśnień UOKiK (pkt VI) wielkość obniżki liczy się od najniższej ceny z 30 dni, a przekreślona cena bez opisu sugeruje, że to cena odniesienia. Przy włączonym „Dopasuj rabat HikaShopa” (domyślnie) wtyczka przy produktach z napisem:

- liczy procent albo kwotę rabatu od najniższej ceny (procent zaokrąglony w dół),
- ukrywa przekreśloną cenę sprzed rabatu HikaShopa, bo cenę odniesienia z opisem pokazuje napis,
- ukrywa procent, gdy cena nie jest niższa od najniższej.

Przykład: produkt 100 zł był dwa tygodnie temu w promocji za 90 zł, teraz ma rabat −20% (80 zł). HikaShop pokazałby „−20%” i przekreślone 100 zł, wtyczka pokazuje „−11%” i napis z ceną 90 zł. Dopasowanie działa w przeglądarce (JavaScript) i obejmuje kartę produktu, listy, moduły i stronę koszyka.

Wtyczka zmienia tylko prezentację rabatu. Cena sprzedaży, czyli ile klient płaci, zostaje taka, jak policzył ją HikaShop.

Przy wyłączonej opcji wtyczka nie zmienia rabatu HikaShopa. Rabat od pierwszego dnia sprzedaży zostaje wtedy z przekreśloną ceną, której towar nigdy nie miał, i bez napisu, więc takiego rabatu nie ustawiaj albo włącz opcję.

### Cena regularna

Wyjaśnienia UOKiK (pkt VI.2, VI.4, VI.9) pozwalają pokazać obok ceny sprzedaży i najniższej ceny z 30 dni trzecią cenę, na przykład regularną, jeśli:

- jest podpisana („Cena regularna”), bo sama przekreślona cena nie mówi, czym jest,
- jest pokazana tak samo jak najniższa cena: ta sama czcionka (wielkość, krój, kolor), a przekreślona tylko wtedy, gdy najniższa też jest przekreślona,
- procent albo kwota rabatu nadal liczą się od najniższej ceny (od ceny regularnej najwyżej dodatkowo, w ten sam sposób),
- kolejność cen jest taka sama w całym sklepie.

Opcja **Pokaż cenę regularną** (domyślnie Nie, dostępna przy włączonym „Dopasuj rabat HikaShopa”) dodaje w bloku napisu, nad najniższą ceną, wiersz „Cena regularna: 100,00 zł” z ceną sprzed rabatu HikaShopa, z podatkiem tak jak najniższa cena. Wiersz ma wygląd napisu, więc zawsze wygląda tak samo jak najniższa cena, i stoi w tym samym miejscu na karcie produktu, listach, w modułach i na stronie koszyka. Zmienia się razem z wariantem. Przy cenie wprowadzającej (rabat od pierwszego dnia sprzedaży) i w module koszyka ceny regularnej nie ma, tak jak napisu. Treść podpisu zmienisz nadpisaniem językowym klucza `PLG_HIKASHOP_OMNIBUS_REGULAR_PRICE_LABEL`.

Przykład: produkt 100 zł był dwa tygodnie temu w promocji za 90 zł, teraz kosztuje 80 zł. Przy cenie widać „Cena regularna: 100,00 zł”, pod nią „Najniższa cena z 30 dni przed obniżką: 90,00 zł”, a rabat HikaShopa to „−11%”.

### VAT

Historia trzyma cenę netto, cenę brutto i stawkę VAT z chwili zapisu. Napis pokazuje cenę brutto, którą klient wtedy płacił, więc zmiana stawki VAT nie przelicza starszych cen. Wpisy sprzed 1.4.0 mają tylko cenę netto: przy pierwszym zapisie produktu po aktualizacji dostają cenę brutto według stawki obowiązującej w tym momencie (wcześniejszej stawki wtyczka nie zna) i potem już się nie zmieniają.

Opis ceny brzmi „Najniższa cena z 30 dni przed obniżką”. Prezes UOKiK ukarał sklep za opis „Najniższa cena przed obniżką”, bo nie wskazywał 30 dni (decyzja DIH-3.99.2024): opis musi jasno mówić, że chodzi o 30 dni przed wprowadzeniem obniżki. Treść napisu zmienisz nadpisaniem językowym klucza `PLG_HIKASHOP_OMNIBUS_LOWEST_PRICE_LABEL`.

## Ograniczenia

- Na listach produktów i w modułach szablon ceny HikaShopa nie ma miejsca dla wtyczek. Napis trafia więc do miejsca `afterProductName` szablonu listy, a skrypt przenosi go tuż pod cenę produktu (bez JavaScriptu zostaje w `afterProductName`). Układy bez tego miejsca (np. `listing_img`) wymagają nadpisania szablonu. Lista albo moduł bez cen napisu nie dostaje.
- Na stronie koszyka napis stoi pod ceną za sztukę. Moduł koszyka (mini koszyk) nie ma miejsca na napis, więc przy włączonym „Dopasuj rabat HikaShopa” wtyczka ukrywa w nim procent i cenę sprzed rabatu: moduł pokazuje cenę do zapłaty, ale nie ogłasza obniżki. Przy wyłączonej opcji ustaw w module „Wyświetl zniżkę” na „Nie”.
- Reaguje tylko na rabaty HikaShopa. Promocja ustawiona jako osobna cena z zakresem dat albo kupon w koszyku nie wyświetlą informacji (cena z zakresem dat trafia jednak do historii).
- Produkt z wariantami: wariant bez własnej ceny korzysta z historii produktu głównego, wariant z własną ceną ma własną historię. Napis zmienia się po wyborze wariantu (zdarzenie HikaShopa `hkAfterUpdateVariant`), więc wymaga JavaScriptu.
- Historia obejmuje ofertę dla wszystkich klientów. Rabaty i ceny tylko dla wybranych grup albo pojedynczych klientów do niej nie trafiają. UOKiK traktuje jednak cenę z promocji dla programu lojalnościowego jako punkt odniesienia dla kolejnych obniżek, więc przy takich promocjach sprawdź napis ręcznie.
- Cena zmieniona z pominięciem HikaShopa (zapytanie SQL, zewnętrzna integracja zapisująca wprost do bazy) trafia do historii z datą najbliższego przebiegu crona albo wyświetlenia produktu, a nie z datą zmiany. To samo dotyczy rabatu wyłączonego wprost w bazie: koniec promocji ma datę zauważenia. Import CSV i akcje masowe HikaShopa wtyczka zapisuje od razu.
- Usunięcie produktu w HikaShopie kasuje jego historię (z wariantami). Historię produktów usuniętych wprost w bazie kasuje przycisk „Wyczyść stare wpisy” w ustawieniach wtyczki.
- Ceny z okresu przed instalacją wtyczki nie są znane: punktem startowym jest cena regularna z dnia instalacji.

## Struktura plików

```
plg_hikashop_omnibus/
├── language/           # Pliki językowe (PL/EN)
│   ├── en-GB/
│   └── pl-PL/
├── media/             # Pliki CSS
│   └── css/
│       └── omnibus.css
├── services/          # Service provider dla Joomla 6
│   └── provider.php
├── sql/               # Skrypty SQL
│   ├── install.mysql.utf8.sql
│   └── uninstall.mysql.utf8.sql
├── src/
│   ├── Extension/
│   │   └── Omnibus.php  # Główna klasa pluginu
│   └── Field/
│       └── HistoryField.php  # Przyciski czyszczenia historii w ustawieniach
└── omnibus.xml        # Manifest instalacyjny
```

## Licencja

GNU General Public License version 2 or later

## Autor

Paweł Półtoraczyk (pablop76)

## Wsparcie

W razie problemów utwórz [Issue](../../issues) na GitHubie.

## Changelog

### v1.5.0 (2026-11-22)

- Nowość: opcja „Pokaż cenę regularną” (domyślnie wyłączona, działa przy „Dopasuj rabat HikaShopa”): nad najniższą ceną wiersz „Cena regularna: …” w tym samym wyglądzie co najniższa cena (czcionka, kolor, przekreślenie), na karcie produktu, listach, w modułach i na stronie koszyka, zmieniany razem z wariantem; przy cenie wprowadzającej go nie ma (wyjaśnienia UOKiK pkt VI.2, VI.4, VI.9)

### v1.4.0 (2026-11-22)

- Nowość: towar oferowany krócej niż 30 dni przed obniżką dostaje napis „Najniższa cena od wprowadzenia towaru” z najniższą ceną od początku sprzedaży (art. 4 ust. 3 ustawy, pkt III.3); rabat od pierwszego dnia sprzedaży nie jest obniżką, więc napisu nie ma
- Nowość: opcja „Dopasuj rabat HikaShopa” (domyślnie włączona): procent albo kwota rabatu HikaShopa liczone od najniższej ceny, przekreślona cena sprzed rabatu bez opisu ukryta, procent ukryty, gdy cena nie jest niższa od najniższej (pkt VI)
- Nowość: napis na karcie produktu zmienia się po wyborze wariantu; wariant z własną ceną ma własną historię
- Nowość: napis na stronie koszyka pod ceną za sztukę
- Nowość: moduł koszyka nie ogłasza obniżki (przy „Dopasuj rabat HikaShopa” procent i cena sprzed rabatu ukryte), bo nie ma w nim miejsca na napis
- Poprawka: cron HikaShopa zapisuje też warianty z własną ceną (wcześniej tylko produkty główne, a warianty trafiały do historii dopiero przy zapisie albo wyświetleniu)
- Poprawka: koniec promocji trafia do historii bez crona. Wyłączenie i usunięcie rabatu w panelu zapisuje cenę od razu, a koniec z datą zapisuje się z datą końca przy najbliższym działaniu wtyczki; wcześniej bez crona najniższa cena przy kolejnej promocji bywała zaniżona
- Poprawka: historia zapisuje cenę brutto i stawkę VAT z chwili zapisu; zmiana VAT nie przelicza już starszych cen, a zmiana stawki albo reguły VAT w panelu od razu zapisuje nowe ceny brutto
- Poprawka: usunięcie produktu kasuje jego historię (z wariantami); „Wyczyść stare wpisy” usuwa też historię produktów usuniętych wprost w bazie
- Poprawka: „Początek sprzedaży” liczy się także przy produkcie bez daty utworzenia
- Wydajność: indeks złożony (produkt, waluta, data) zamiast indeksu samego produktu; przy produkcie z długą historią zapytania wtyczki są kilkadziesiąt razy szybsze
- Baza: kolumny `price_with_tax` i `tax_rate` oraz indeks `product_currency_date` w `#__hikashop_price_history` (przy aktualizacji dodawane automatycznie, istniejące wpisy zostają)

### v1.3.1 (2026-11-21)

- Nowość: import CSV i akcja masowa na produktach (np. „Zaktualizuj wartości” cen) zapisują zmiany cen w historii od razu, bez czekania na cron (zdarzenia HikaShopa `onAfterProductsImport` i `onAfterMassactionProcess`)
- Nowość: akcja masowa na rabatach od razu uzgadnia daty wprowadzenia obniżek; rabat włączony akcją masową liczy się od chwili włączenia, a zmiana jego wartości to nowa obniżka
- Nowość: usunięcie rabatu od razu kasuje jego zapamiętaną datę wprowadzenia (`onAfterDiscountDelete`)
- Poprawka opisu: przełącznik publikacji na liście rabatów wywołuje te same zdarzenia co formularz, więc włączenie nim rabatu liczy się od chwili włączenia
- Opis: cron HikaShopa jest tylko w wersjach płatnych (Essential, Business); w wersji Starter zmiany spoza HikaShopa trafiają do historii przy wyświetleniu produktu

### v1.3.0 (2026-11-21)

- Zmiana: historia zawiera także ceny po rabacie HikaShopa (i ceny z zakresem dat), czyli każdą cenę, po której produkt był oferowany wszystkim klientom. Cena z poprzedniej promocji liczy się teraz do najniższej ceny z 30 dni przed kolejną obniżką (wyjaśnienia Prezesa UOKiK, pkt V.2); wcześniej wtyczka pokazywała wtedy zawyżoną cenę regularną
- Zmiana: zmiana wartości rabatu to nowa obniżka z nowym oknem 30 dni (promocje kroczące, pkt III.6); wcześniej data wprowadzenia zostawała bez zmian
- Nowość: przebieg crona HikaShopa zapisuje zmiany cen, które omijają zapis produktu: import CSV, akcja masowa „Zaktualizuj wartości”, początek i koniec rabatu z datą, rabat przełączony na liście
- Nowość: wyświetlenie produktu niezalogowanemu klientowi uzupełnia historię tego produktu, gdy cena zmieniła się bez zapisu
- Zmiana: rabat zakończony albo wyłączony traci zapamiętaną datę wprowadzenia, więc ponowne włączenie liczy się jako nowa obniżka; rabat z datą rozpoczęcia, który wtyczka zauważa bez zapisu w formularzu, liczy się od tej daty
- Nowość: informacja na listach produktów i w modułach HikaShopa, tuż pod ceną produktu (UOKiK: w najbliższym sąsiedztwie ceny obniżonej); lista albo moduł bez cen jej nie dostaje
- Poprawka: produkt z wariantami bez własnych cen nie dostawał informacji, bo HikaShop podaje na karcie wariant domyślny; teraz liczy się historia produktu głównego
- Poprawka: cena z datą rozpoczęcia z przeszłości i bez daty końca to cena regularna (wcześniej taki produkt nie miał historii ani informacji)
- Nowość: produkt bez historii, który trafia do niej w trakcie obniżki, dostaje najpierw cenę regularną jako punkt startowy
- Zmiana: „Wyczyść historię produktu” zapisuje jako punkt startowy cenę regularną, a przy trwającej obniżce zaraz po niej cenę obniżoną
- Poprawka: błąd wtyczki (np. brak kolumny po aktualizacji) nie blokuje strony produktu ani zapisu w panelu; brakujące kolumny wtyczka dodaje sama
- Baza: kolumna `discounted` w `#__hikashop_price_history` i `conditions` w `#__hikashop_omnibus_discount` (przy aktualizacji dodawane automatycznie)

### v1.2.1 (2026-11-21)

- Poprawka: przy obniżce wprowadzonej przed instalacją wtyczki (np. rabat z datą rozpoczęcia sprzed instalacji) informacja się nie pokazywała, bo historia cen zaczyna się dopiero od instalacji; teraz brany jest najstarszy zapisany wpis, czyli cena regularna z chwili instalacji
- Poprawka: ceny zapisywane przy instalacji dostają datę w UTC, jak pozostałe wpisy historii (wcześniej czas serwera bazy)

### v1.2.0 (2026-11-21)

- Zmiana: okno najniższej ceny kończy się w chwili wprowadzenia obniżki (data rozpoczęcia rabatu albo zapamiętane włączenie), a nie w dniu oglądania strony; zmiany ceny po wprowadzeniu obniżki do okna nie należą (art. 4 ust. 2 ustawy o informowaniu o cenach)
- Zmiana: napis „Najniższa cena z %d dni przed obniżką” zamiast „Najniższa cena z ostatnich %d dni”
- Nowość: tabela `#__hikashop_omnibus_discount` z chwilą włączenia rabatów bez daty rozpoczęcia (przy aktualizacji ze starszej wersji powstaje sama)
- Poprawka: „Usuń stare wpisy” zachowuje historię potrzebną trwającym obniżkom (granica liczona od najwcześniejszej z nich, nie od dziś)

### v1.1.0 (2026-09-30)

- Nowość: w ustawieniach wtyczki zakładka **Historia cen** z czyszczeniem historii: dla wskazanego produktu (kasuje historię i zapisuje cenę bieżącą jako nowy punkt startowy) oraz usuwanie wpisów starszych niż okno „Liczba dni” (zostaje ostatni wpis sprzed okna)
- Poprawka: produkt z ceną niezmienianą dłużej niż okno „Liczba dni” dostawał pustą informację, bo historia zapisuje tylko zmiany ceny; teraz liczy się także ostatni wpis sprzed okna
- Uwaga: czyścić należy tylko pomyłki; cena, po której produkt faktycznie sprzedawano w oknie, musi zostać w historii

### v1.0.1 (2026-07-07)

- Poprawka: `<creationDate>` w manifeście zsynchronizowana z faktyczną datą wydania (Joomla nie robi tego automatycznie)
- Poprawka: `targetplatform` w `updates.xml` zwężony do Joomla 6.x (zgodnie z deklarowanymi wymaganiami — wcześniej dopuszczał też 4.x/5.x, na których wtyczka nie była testowana)

### v1.0 (2026-07-07)
- Pierwsze wydanie
- Zgodność z dyrektywą Omnibus UE
- Automatyczne wykrywanie rabatów HikaShop
- Pełna konfiguracja w panelu administracyjnym
- Wsparcie wielojęzyczne PL/EN
- Zapis historii cen pomija aktywne promocje czasowe i ceny grupowe (tylko cena regularna, ogólnodostępna)
- Historia cen liczona osobno per waluta
- Etykieta najniższej ceny odzwierciedla skonfigurowaną liczbę dni (zamiast sztywnych "30 dni")
- Walidacja wartości CSS z konfiguracji przed wstrzyknięciem do strony
