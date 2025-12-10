# Hikashop Omnibus Plugin

Plugin dla Joomla 6 i HikaShop wyświetlający najniższą cenę produktu z ostatnich 30 dni **podczas aktywnych promocji** - pełna zgodność z dyrektywą Omnibus UE.

## ⚖️ Zgodność z dyrektywą Omnibus

Plugin w 100% spełnia wymagania **dyrektywy Omnibus (UE) 2019/2161**:

✅ Informacja o najniższej cenie pojawia się **tylko przy ogłoszeniu obniżki** (rabat, promocja, kupon)  
✅ Pokazuje najniższą cenę z ostatnich 30 dni **przed zastosowaniem obniżki**  
✅ Automatycznie zapisuje historię cen bazowych produktów (nie ceny po kuponach/rabatach)  
✅ Wyraźna prezentacja z przekreśloną ceną bazową  

### Jak to działa?

1. **Zapisywanie cen**: Plugin automatycznie zapisuje **regularne ceny produktów** do historii (nie ceny po kuponach/rabatach)
2. **Wykrywanie promocji**: Sprawdza czy produkt ma aktywny rabat w HikaShop
3. **Wyświetlanie**: Gdy wykryje promocję, pokazuje najniższą cenę bazową z ostatnich X dni
4. **Automatyczne ukrywanie**: Gdy promocja się kończy, informacja znika

## Funkcje

✅ Automatyczne wykrywanie aktywnych rabatów/promocji HikaShop  
✅ Wyświetlanie najniższej ceny z ostatnich X dni (konfigurowalne)  
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
2. W ciągu ostatnich 30 dni cena bazowa wynosiła: 120 zł, 110 zł, 100 zł
3. Najniższa cena z ostatnich 30 dni to: **100 zł**

**Bez promocji:**
- Plugin **NIE pokazuje** żadnej informacji

**Z promocją -20%:**
```
Cena: 80 zł
Najniższa cena z ostatnich 30 dni: 100 zł
```

## Zgodność z dyrektywą Omnibus - szczegóły

Plugin zapisuje **tylko regularne ceny produktów**, NIE ceny po zastosowaniu kuponów/rabatów/promocji.  

**Dlaczego?**  
Zgodnie z dyrektywą Omnibus należy pokazywać najniższą cenę z ostatnich 30 dni **przed zastosowaniem obniżki**. 

**Przykład:**
- Jeśli produkt kosztował 200 zł, potem 180 zł, a teraz jest promocja do 150 zł
- W ogłoszeniu o promocji musisz wskazać: "Najniższa cena z 30 dni: 180 zł"
- Rabat jest liczony od 180 zł (nie od 200 zł)

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
│   └── Extension/
│       └── Omnibus.php  # Główna klasa pluginu
└── omnibus.xml        # Manifest instalacyjny
```

## Licencja

GNU General Public License version 2 or later

## Autor

Paweł Półtoraczyk (pablop76)

## Wsparcie

W razie problemów utwórz [Issue](../../issues) na GitHubie.

## Changelog

### v1.0 (2025-12-07)
- Pierwsze wydanie
- Zgodność z dyrektywą Omnibus UE
- Automatyczne wykrywanie rabatów HikaShop
- Pełna konfiguracja w panelu administracyjnym
- Wsparcie wielojęzyczne PL/EN
