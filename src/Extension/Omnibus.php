<?php
namespace Pablop76\Plugin\Hikashop\Omnibus\Extension;

// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

class Omnibus extends CMSPlugin
{
    /**
     * Load the language file on instantiation
     */
    protected $autoloadLanguage = true;
    
    /**
     * Zapisuje cenę produktu do historii po aktualizacji
     * 
     * UWAGA: Zgodnie z dyrektywą Omnibus zapisujemy tylko regularne ceny produktów,
     * NIE ceny po zastosowaniu kuponów/rabatów/promocji. Dyrektywa wymaga pokazania
     * najniższej ceny z ostatnich 30 dni PRZED zastosowaniem obniżki.
     */
    public function onAfterProductUpdate(&$element)
    {
        $this->savePriceHistory($element);
    }

    /**
     * Zapisuje cenę produktu do historii po utworzeniu
     * 
     * UWAGA: Zgodnie z dyrektywą Omnibus zapisujemy tylko regularne ceny produktów,
     * NIE ceny po zastosowaniu kuponów/rabatów/promocji. Dyrektywa wymaga pokazania
     * najniższej ceny z ostatnich 30 dni PRZED zastosowaniem obniżki.
     */
    public function onAfterProductCreate(&$element)
    {
        $this->savePriceHistory($element);
    }

    /**
     * Wspólna funkcja do zapisu historii cen
     */
    private function savePriceHistory($element)
    {
        // Sprawdź czy $element to Event object - jeśli tak, wyciągnij dane
        if ($element instanceof Event) {
            $element = $element->getArgument(0);
        }

        if (empty($element->product_id)) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $productId = (int)$element->product_id;

        // Pobierz aktualne REGULARNE ceny produktu:
        // - price_access = 'all'     -> cena ogólnodostępna, nie cena dla wybranej grupy klientów
        // - price_start_date/end = 0 -> cena stała, nie zaplanowana promocja z zakresem dat
        // Każda waluta to osobny wiersz - pobieramy wszystkie, bo ceny w różnych walutach
        // nie są ze sobą porównywalne liczbowo (MIN po samej wartości mieszałoby waluty)
        $query = $db->getQuery(true)
            ->select($db->quoteName(['price_value', 'price_currency_id']))
            ->from($db->quoteName('#__hikashop_price'))
            ->where($db->quoteName('price_product_id') . ' = ' . $productId)
            ->where($db->quoteName('price_min_quantity') . ' = 0')
            ->where($db->quoteName('price_access') . ' = ' . $db->quote('all'))
            ->where($db->quoteName('price_start_date') . ' = 0')
            ->where($db->quoteName('price_end_date') . ' = 0');

        $db->setQuery($query);
        $prices = $db->loadObjectList();

        foreach ($prices as $price) {
            $this->savePriceHistoryEntry($productId, (int)$price->price_currency_id, (float)$price->price_value, $db);
        }
    }

    /**
     * Zapisuje pojedynczy wpis historii ceny (dla konkretnej waluty), z deduplikacją
     */
    private function savePriceHistoryEntry($productId, $currencyId, $priceValue, $db)
    {
        // Sprawdź czy ta sama cena (w tej samej walucie) już istnieje w historii
        $query = $db->getQuery(true)
            ->select($db->quoteName('price'))
            ->from($db->quoteName('#__hikashop_price_history'))
            ->where($db->quoteName('product_id') . ' = ' . $productId)
            ->where($db->quoteName('currency_id') . ' = ' . $currencyId)
            ->order($db->quoteName('date_added') . ' DESC')
            ->setLimit(1);

        $db->setQuery($query);
        $lastPrice = $db->loadResult();

        // Jeśli cena się nie zmieniła, nie zapisuj duplikatu
        if ($lastPrice !== null && (float)$lastPrice === $priceValue) {
            return;
        }

        // Zapisz cenę do historii
        $data = (object)[
            'product_id' => $productId,
            'price' => $priceValue,
            'currency_id' => $currencyId,
            'date_added' => Factory::getDate()->toSql()
        ];

        $db->insertObject('#__hikashop_price_history', $data);
    }
    
    /**
     * Wyświetla najniższą cenę PRZED wyświetleniem widoku produktu
     */
    public function onHikashopBeforeDisplayView(&$view)
    {
        // Sprawdź czy to frontend
        if (hikashop_isClient('administrator')) {
            return;
        }

        // Sprawdź czy to widok produktu
        if (empty($view->getName()) || $view->getName() != 'product') {
            return;
        }

        // Załaduj CSS z dynamicznymi parametrami (tylko na widoku produktu, gdzie jest używany)
        $this->loadCustomCSS();

        // Sprawdź czy mamy element produktu
        if (!empty($view->element) && !empty($view->element->product_id)) {
            $lowestPriceHtml = $this->getLowestPriceHtml($view->element);
            
            if ($lowestPriceHtml) {
                // Wstrzyknij HTML do extraData->rightMiddle (zaraz po cenie)
                if (!isset($view->element->extraData)) {
                    $view->element->extraData = new \stdClass();
                }
                if (!isset($view->element->extraData->rightMiddle)) {
                    $view->element->extraData->rightMiddle = [];
                }
                $view->element->extraData->rightMiddle[] = $lowestPriceHtml;
            }
        }
    }
    
    /**
     * Ładuje niestandardowy CSS z parametrami z konfiguracji
     */
    private function loadCustomCSS()
    {
        // Walidacja formatu przed wstrzyknięciem do <style> - pola konfiguracyjne to zwykły
        // tekst, więc bez tego nieprawidłowa (lub złośliwa) wartość mogłaby wyrenderować
        // dowolny CSS/HTML na każdej stronie frontendu
        $fontSize = $this->sanitizeCssUnit($this->params->get('font_size', '0.85em'), '0.85em');
        $textColor = $this->sanitizeCssColor($this->params->get('text_color', '#666666'), '#666666');
        $strikethrough = $this->params->get('strikethrough', 1);
        $marginTop = $this->sanitizeCssUnit($this->params->get('margin_top', '10px'), '10px');
        $marginBottom = $this->sanitizeCssUnit($this->params->get('margin_bottom', '10px'), '10px');

        $css = "
        .hikashop-omnibus-lowest-price {
            margin-top: {$marginTop};
            margin-bottom: {$marginBottom};
            font-size: {$fontSize};
            color: {$textColor};
        }
        .omnibus-price-value {
            " . ($strikethrough ? "text-decoration: line-through;" : "") . "
        }
        ";
        
        Factory::getApplication()->getDocument()->addStyleDeclaration($css);
    }

    /**
     * Waliduje wartość jednostki CSS (np. "10px", "0.85em", "-5px"), inaczej zwraca domyślną
     */
    private function sanitizeCssUnit($value, $default)
    {
        return preg_match('/^-?\d+(\.\d+)?(px|em|rem|%)$/', (string)$value) ? $value : $default;
    }

    /**
     * Waliduje wartość koloru CSS w formacie hex (np. "#666", "#666666"), inaczej zwraca domyślną
     */
    private function sanitizeCssColor($value, $default)
    {
        return preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', (string)$value) ? $value : $default;
    }

    /**
     * Pobiera sformatowany HTML z najniższą ceną
     */
    private function getLowestPriceHtml($product)
    {
        if (empty($product->prices)) {
            return '';
        }

        $priceObj = reset($product->prices);

        // KLUCZOWE: Zgodnie z dyrektywą Omnibus informacja ma się pokazywać
        // TYLKO przy ogłoszeniu obniżki (rabat, promocja, kupon).
        // HikaShop przechowuje cenę przed rabatem w price_value_without_discount
        $hasDiscount = isset($priceObj->price_value_without_discount)
            && $priceObj->price_value_without_discount > $priceObj->price_value;

        if (!$hasDiscount) {
            return '';
        }

        // Waluta aktualnie wyświetlanej ceny - historia jest zapisywana per waluta,
        // więc porównujemy tylko wpisy w tej samej walucie (bez mieszania kursów)
        $currencyId = (int)($priceObj->price_currency_id ?? 0);

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Pobierz liczbę dni z konfiguracji
        $daysCount = (int)$this->params->get('days_count', 30);

        // Oblicz datę wstecz
        $dateLimit = Factory::getDate('-' . $daysCount . ' days')->toSql();

        // Zapytanie o najniższą cenę z ostatnich X dni (w tej samej walucie)
        $query = $db->getQuery(true)
            ->select('MIN(' . $db->quoteName('price') . ') AS lowest_price')
            ->from($db->quoteName('#__hikashop_price_history'))
            ->where($db->quoteName('product_id') . ' = ' . (int)$product->product_id)
            ->where($db->quoteName('currency_id') . ' = ' . $currencyId)
            ->where($db->quoteName('date_added') . ' >= ' . $db->quote($dateLimit));

        $db->setQuery($query);
        $lowestPrice = $db->loadResult();

        if (!$lowestPrice) {
            return '';
        }

        $priceToShow = (float)$lowestPrice;

        // Jeśli mamy cenę z podatkiem różną od ceny netto, zastosuj ten sam współczynnik
        // do najniższej ceny historycznej (która jest zapisana netto)
        if (isset($priceObj->price_value_with_tax, $priceObj->price_value)
            && $priceObj->price_value > 0
            && $priceObj->price_value_with_tax != $priceObj->price_value) {
            $taxMultiplier = $priceObj->price_value_with_tax / $priceObj->price_value;
            $priceToShow *= $taxMultiplier;
        }

        // Formatuj cenę używając funkcji HikaShop
        $currencyHelper = hikashop_get('class.currency');
        $formattedPrice = $currencyHelper->format($priceToShow, $currencyId);

        // Przygotuj tekst z tłumaczeniem (liczba dni jest wstrzykiwana do etykiety)
        $text = Text::sprintf('PLG_HIKASHOP_OMNIBUS_LOWEST_PRICE_LABEL', $daysCount);

        // Zwróć sformatowany HTML
        return '<div class="hikashop-omnibus-lowest-price">
            <span class="omnibus-price-label">' . $text . ': </span>
            <span class="omnibus-price-value">' . $formattedPrice . '</span>
        </div>';
    }
}