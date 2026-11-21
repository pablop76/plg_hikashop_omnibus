# Hikashop Omnibus Plugin

Plugin dla Joomla 6 i HikaShop wyświetlający najniższą cenę produktu z 30 dni **przed wprowadzeniem obniżki**, gdy na produkt działa rabat HikaShopa (dyrektywa Omnibus UE, art. 4 ust. 2 ustawy o informowaniu o cenach towarów i usług).

## ⚖️ Zgodność z dyrektywą Omnibus

Plugin realizuje wymóg **dyrektywy Omnibus (UE) 2019/2161** w zakresie opisanym niżej (patrz „Ograniczenia”):

✅ Informacja o najniższej cenie pojawia się **tylko przy obniżce** (rabat HikaShopa)  
✅ Pokazuje najniższą cenę z 30 dni **przed wprowadzeniem obniżki** (okno kończy się w dniu wprowadzenia obniżki, nie dziś)  
✅ Zapisuje historię cen, po których produkt był oferowany wszystkim klientom: regularnych i obniżonych rabatem HikaShopa, więc cena z poprzedniej promocji też się liczy  
✅ Zwiększenie albo zmniejszenie rabatu traktuje jako nową obniżkę (tzw. promocje kroczące)  
✅ Informacja na karcie produktu, na listach produktów i w modułach HikaShopa  
✅ Wyraźna prezentacja z przekreśloną ceną bazową  

### Jak to działa?

1. **Zapisywanie cen**: wtyczka zapisuje cenę, po której produkt jest oferowany wszystkim klientom: regularną albo obniżoną rabatem HikaShopa. Robi to przy zapisie produktu, w każdym przebiegu crona HikaShopa i przy wyświetleniu produktu niezalogowanemu klientowi.
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
- **Margines górny/dolny** - marginesy elementu (np. 10px)

Na listach produktów i w modułach napis jest mniejszy i zwykłej grubości, w kroju kwoty ceny, żeby nie dominował w małych kartach (np. w karuzeli w menu). Wygląd zmienisz w CSS szablonu klasą `.hikashop-omnibus-lowest-price--list`.

### Cron HikaShopa

Ustaw cron HikaShopa (**Komponenty → HikaShop → Konfiguracja**, zakładka cron). Bez niego do historii trafią tylko zmiany cen zapisane w formularzu produktu albo widoczne przy wyświetleniu produktu. Cron zapisuje zmiany, o których HikaShop nie powiadamia wtyczek:

- początek i koniec rabatu z datą,
- ceny zmienione importem CSV albo akcją masową „Zaktualizuj wartości” (HikaShop zapisuje je bezpośrednio w bazie),
- rabat włączony albo wyłączony przyciskiem na liście rabatów.

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
- **Rabat bez daty rozpoczęcia**: HikaShop nie zapisuje, kiedy rabat włączono, więc robi to wtyczka (tabela `#__hikashop_omnibus_discount`). Przy zapisie rabatu w panelu zapamiętuje chwilę włączenia. Wyłączenie albo koniec rabatu kasuje ten wpis, więc ponowne włączenie liczy się jako nowa obniżka.
- **Zmiana wartości rabatu** (procent, kwota) to nowa obniżka: okno kończy się w chwili zmiany, także przy rabacie z datą rozpoczęcia.
- **Rabat włączony bez formularza** (przyciskiem publikacji na liście, akcją masową) albo przed aktualizacją do 1.2.0: wtyczka zauważa go przy najbliższym przebiegu crona albo wyświetleniu produktu. Rabat z datą rozpoczęcia liczy się od tej daty, rabat bez daty od chwili zauważenia, więc dla takich rabatów ustaw datę rozpoczęcia.

Opis ceny brzmi „Najniższa cena z 30 dni przed obniżką”. Prezes UOKiK ukarał sklep za opis „Najniższa cena przed obniżką”, bo nie wskazywał 30 dni (decyzja DIH-3.99.2024): opis musi jasno mówić, że chodzi o 30 dni przed wprowadzeniem obniżki. Treść napisu zmienisz nadpisaniem językowym klucza `PLG_HIKASHOP_OMNIBUS_LOWEST_PRICE_LABEL`.

## Ograniczenia

- Na listach produktów i w modułach szablon ceny HikaShopa nie ma miejsca dla wtyczek. Napis trafia więc do miejsca `afterProductName` szablonu listy, a skrypt przenosi go tuż pod cenę produktu (bez JavaScriptu zostaje w `afterProductName`). Układy bez tego miejsca (np. `listing_img`) wymagają nadpisania szablonu. Lista albo moduł bez cen napisu nie dostaje. W koszyku informacji nie ma.
- Reaguje tylko na rabaty HikaShopa. Promocja ustawiona jako osobna cena z zakresem dat albo kupon w koszyku nie wyświetlą informacji (cena z zakresem dat trafia jednak do historii).
- Produkt z wariantami: wariant bez własnej ceny korzysta z historii produktu głównego. Napis jest liczony dla wariantu pokazanego przy wejściu na kartę i nie zmienia się po wyborze innego wariantu, więc przy różnych cenach wariantów może nie pasować.
- Historia obejmuje ofertę dla wszystkich klientów. Rabaty i ceny tylko dla wybranych grup albo pojedynczych klientów do niej nie trafiają. UOKiK traktuje jednak cenę z promocji dla programu lojalnościowego jako punkt odniesienia dla kolejnych obniżek, więc przy takich promocjach sprawdź napis ręcznie.
- Cena zmieniona z pominięciem formularza produktu (import, akcja masowa, zapytanie SQL) trafia do historii z datą najbliższego przebiegu crona albo wyświetlenia produktu, a nie z datą zmiany.
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
