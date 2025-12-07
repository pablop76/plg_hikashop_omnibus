<?php
namespace Pablop76\Plugin\Hikashop\Omnibus\Extension;

// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

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
        
        // Pobierz aktualną cenę produktu
        $query = $db->getQuery(true)
            ->select($db->quoteName(['price_value', 'price_currency_id']))
            ->from($db->quoteName('#__hikashop_price'))
            ->where($db->quoteName('price_product_id') . ' = ' . (int)$element->product_id)
            ->where($db->quoteName('price_min_quantity') . ' = 0')
            ->order($db->quoteName('price_value') . ' ASC')
            ->setLimit(1);
        
        $db->setQuery($query);
        $price = $db->loadObject();
        
        if (!$price) {
            return;
        }
        
        // Sprawdź czy ta sama cena już istnieje w historii
        $query = $db->getQuery(true)
            ->select($db->quoteName('price'))
            ->from($db->quoteName('#__hikashop_price_history'))
            ->where($db->quoteName('product_id') . ' = ' . (int)$element->product_id)
            ->order($db->quoteName('date_added') . ' DESC')
            ->setLimit(1);
        
        $db->setQuery($query);
        $lastPrice = $db->loadResult();
        
        // Jeśli cena się nie zmieniła, nie zapisuj duplikatu
        if ($lastPrice !== null && (float)$lastPrice === (float)$price->price_value) {
            return;
        }
        
        // Zapisz cenę do historii
        $data = (object)[
            'product_id' => (int)$element->product_id,
            'price' => (float)$price->price_value,
            'currency_id' => (int)$price->price_currency_id,
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
        
        // Załaduj CSS z dynamicznymi parametrami
        $this->loadCustomCSS();
        
        // Sprawdź czy to widok produktu
        if (empty($view->getName()) || $view->getName() != 'product') {
            return;
        }
        
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
        $fontSize = $this->params->get('font_size', '0.85em');
        $textColor = $this->params->get('text_color', '#666666');
        $strikethrough = $this->params->get('strikethrough', 1);
        $marginTop = $this->params->get('margin_top', '10px');
        $marginBottom = $this->params->get('margin_bottom', '10px');
        
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
     * Pobiera sformatowany HTML z najniższą ceną
     */
    private function getLowestPriceHtml($product)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        
        // Pobierz liczbę dni z konfiguracji
        $daysCount = (int)$this->params->get('days_count', 30);
        
        // Oblicz datę wstecz
        $dateLimit = Factory::getDate('-' . $daysCount . ' days')->toSql();
        
        // Zapytanie o najniższą cenę z ostatnich X dni
        $query = $db->getQuery(true)
            ->select('MIN(' . $db->quoteName('price') . ') AS lowest_price')
            ->select($db->quoteName('currency_id'))
            ->select('COUNT(*) AS entries_count')
            ->from($db->quoteName('#__hikashop_price_history'))
            ->where($db->quoteName('product_id') . ' = ' . (int)$product->product_id)
            ->where($db->quoteName('date_added') . ' >= ' . $db->quote($dateLimit))
            ->group($db->quoteName('currency_id'));
        
        $db->setQuery($query);
        $lowestPrice = $db->loadObject();
        
        if (!$lowestPrice || !$lowestPrice->lowest_price) {
            return '';
        }
        
        // KLUCZOWE: Zgodnie z dyrektywą Omnibus informacja ma się pokazywać 
        // TYLKO przy ogłoszeniu obniżki (rabat, promocja, kupon)
        
        // Sprawdź czy produkt ma obecnie rabat aktywny
        $hasDiscount = false;
        
        if (!empty($product->prices)) {
            $priceObj = reset($product->prices);
            
            // HikaShop przechowuje cenę przed rabatem w price_value_without_discount
            if (isset($priceObj->price_value_without_discount) && $priceObj->price_value_without_discount > $priceObj->price_value) {
                $hasDiscount = true;
            }
        }
        
        // Jeśli NIE MA rabatu/promocji, nie pokazuj informacji
        if (!$hasDiscount) {
            return '';
        }
        
        $priceToShow = $lowestPrice->lowest_price;
        
        // Jeśli produkt ma aktualną cenę z podatkiem, użyj współczynnika
        if (!empty($product->prices)) {
            $currentPrice = reset($product->prices);
            
            // Jeśli mamy cenę z podatkiem różną od ceny netto
            if (isset($currentPrice->price_value_with_tax) 
                && isset($currentPrice->price_value)
                && $currentPrice->price_value > 0
                && $currentPrice->price_value_with_tax != $currentPrice->price_value) {
                // Zastosuj ten sam współczynnik do najniższej ceny
                $taxMultiplier = $currentPrice->price_value_with_tax / $currentPrice->price_value;
                $priceToShow = $lowestPrice->lowest_price * $taxMultiplier;
            }
        }
        
        // Formatuj cenę używając funkcji HikaShop
        $currencyHelper = hikashop_get('class.currency');
        $formattedPrice = $currencyHelper->format($priceToShow, $lowestPrice->currency_id);
        
        // Przygotuj tekst z tłumaczeniem
        $text = Text::_('PLG_HIKASHOP_OMNIBUS_LOWEST_PRICE_LABEL');
        
        // Zwróć sformatowany HTML
        return '<div class="hikashop-omnibus-lowest-price">
            <span class="omnibus-price-label">' . $text . ': </span>
            <span class="omnibus-price-value">' . $formattedPrice . '</span>
        </div>';
    }
}