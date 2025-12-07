# Hikashop Omnibus Plugin

Plugin dla Joomla 6 i HikaShop wyświetlający najniższą cenę produktu z ostatnich 30 dni (zgodność z dyrektywą Omnibus).

## Funkcje

✅ Automatyczne zapisywanie historii cen produktów  
✅ Wyświetlanie najniższej ceny z ostatnich X dni (konfigurowalne)  
✅ Wsparcie dla cen brutto/netto (z podatkiem)  
✅ Pełna konfiguracja w panelu administracyjnym:
- Liczba dni do sprawdzania historii
- Rozmiar czcionki
- Kolor tekstu
- Przekreślenie ceny
- Marginesy

✅ Wielojęzyczność (PL/EN)  
✅ Automatyczna inicjalizacja przy instalacji  

## Wymagania

- Joomla 6.x
- HikaShop (wersja 5.x lub nowsza)
- PHP 8.0+
- MySQL 5.7+

## Instalacja

1. Pobierz najnowszą wersję z [Releases](../../releases)
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
- **Kolor tekstu** - kolor tekstu
- **Przekreślenie** - czy cena ma być przekreślona
- **Margines górny/dolny** - marginesy elementu

## Jak to działa?

1. **Zapisywanie cen**: Plugin automatycznie zapisuje cenę produktu do historii przy każdej zmianie w panelu HikaShop
2. **Wyświetlanie**: Na stronie produktu wyświetlana jest najniższa cena z ostatnich X dni (tylko jeśli była zmiana ceny)
3. **Podatki**: Jeśli produkt ma cenę z podatkiem, plugin automatycznie przelicza historyczną cenę z odpowiednim współczynnikiem VAT

## Zgodność z dyrektywą Omnibus

Plugin zapisuje **tylko regularne ceny produktów**, NIE ceny po zastosowaniu kuponów/rabatów/promocji.  
Zgodnie z dyrektywą Omnibus należy pokazywać najniższą cenę z ostatnich 30 dni **przed zastosowaniem obniżki**.

## Struktura plików

```
plg_hikashop_omnibus/
├── language/           # Pliki językowe (PL/EN)
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
