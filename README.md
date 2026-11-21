# Hikashop Omnibus Plugin

Plugin dla Joomla 6 i HikaShop wyświetlający najniższą cenę produktu z 30 dni **przed wprowadzeniem obniżki**, gdy na produkt działa rabat HikaShopa (dyrektywa Omnibus UE, art. 4 ust. 2 ustawy o informowaniu o cenach towarów i usług).

## ⚖️ Zgodność z dyrektywą Omnibus

Plugin realizuje wymóg **dyrektywy Omnibus (UE) 2019/2161** w zakresie opisanym niżej (patrz „Ograniczenia”):

✅ Informacja o najniższej cenie pojawia się **tylko przy obniżce** (rabat HikaShopa)  
✅ Pokazuje najniższą cenę z 30 dni **przed wprowadzeniem obniżki** (okno kończy się w dniu wprowadzenia obniżki, nie dziś)  
✅ Automatycznie zapisuje historię cen bazowych produktów (nie ceny po kuponach/rabatach)  
✅ Wyraźna prezentacja z przekreśloną ceną bazową  

### Jak to działa?

1. **Zapisywanie cen**: Plugin automatycznie zapisuje **regularne ceny produktów** do historii (nie ceny po kuponach/rabatach)
2. **Wykrywanie promocji**: Sprawdza czy produkt ma aktywny rabat w HikaShop
3. **Wyświetlanie**: Gdy wykryje promocję, pokazuje najniższą cenę bazową z X dni przed wprowadzeniem obniżki
4. **Automatyczne ukrywanie**: Gdy promocja się kończy, informacja znika

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

Plugin zapisuje **tylko regularne ceny produktów**, NIE ceny po zastosowaniu kuponów/rabatów/promocji.  

**Dlaczego?**  
Zgodnie z art. 4 ust. 2 ustawy o informowaniu o cenach towarów i usług obok obniżonej ceny pokazuje się najniższą cenę, która obowiązywała w okresie 30 dni **przed wprowadzeniem obniżki**.

**Przykład:**
- Jeśli produkt kosztował 200 zł, potem 180 zł, a teraz jest promocja do 150 zł
- W ogłoszeniu o promocji musisz wskazać: "Najniższa cena z 30 dni przed obniżką: 180 zł"
- Rabat jest liczony od 180 zł (nie od 200 zł)

### Od kiedy liczą się dni

Okno kończy się w chwili wprowadzenia obniżki, a nie w dniu oglądania strony. Inaczej podwyżka tuż przed promocją mogłaby zniknąć z okna, gdy promocja trwa dłużej.

- **Rabat z datą rozpoczęcia** (pole „Data rozpoczęcia” rabatu w HikaShopie): okno kończy się w tej dacie. To wariant dokładny i zalecany.
- **Rabat bez daty rozpoczęcia**: HikaShop nie zapisuje, kiedy rabat włączono, więc robi to wtyczka. Przy zapisie opublikowanego rabatu w panelu zapamiętuje chwilę włączenia (tabela `#__hikashop_omnibus_discount`). Wyłączenie rabatu kasuje ten wpis, więc ponowne włączenie liczy się jako nowa obniżka. Zmiana samej wartości rabatu daty nie zmienia.
- **Rabat włączony przed aktualizacją do 1.2.0** albo przełączony bez zapisu formularza (np. przyciskiem publikacji na liście): wtyczka zapamiętuje pierwsze wyświetlenie produktu z tym rabatem. Prawdziwego początku obniżki nie zna, więc dla takich rabatów ustaw datę rozpoczęcia.

Opis ceny brzmi „Najniższa cena z 30 dni przed obniżką”. Prezes UOKiK ukarał sklep za opis „Najniższa cena przed obniżką”, bo nie wskazywał 30 dni (decyzja DIH-3.99.2024): opis musi jasno mówić, że chodzi o 30 dni przed wprowadzeniem obniżki. Treść napisu zmienisz nadpisaniem językowym klucza `PLG_HIKASHOP_OMNIBUS_LOWEST_PRICE_LABEL`.

## Ograniczenia

- Informacja pojawia się tylko na stronie produktu, nie na listach produktów ani w modułach.
- Reaguje tylko na rabaty HikaShopa. Promocja ustawiona jako osobna cena z zakresem dat albo kupon w koszyku nie wyświetlą informacji.
- Dla produktów z wariantami bierze pierwszą cenę produktu głównego.
- Historia zapisuje się przy zapisie produktu przez HikaShop. Cena zmieniona z pominięciem HikaShopa (np. zapytaniem SQL) do niej nie trafi.

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
