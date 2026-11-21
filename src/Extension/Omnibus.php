<?php
namespace Pablop76\Plugin\Hikashop\Omnibus\Extension;

// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Session\Session;
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
     * Historia cen, po których produkt był oferowany wszystkim klientom
     */
    private const HISTORY_TABLE = '#__hikashop_price_history';

    /**
     * Chwila wprowadzenia obniżki dla każdego trwającego albo zaplanowanego rabatu
     */
    private const DISCOUNT_TABLE = '#__hikashop_omnibus_discount';

    /**
     * Ile produktów naraz przelicza cron
     */
    private const CHUNK = 200;

    /**
     * Trwa wyliczanie ceny ogólnodostępnej: zdarzenia HikaShopa zawężają wtedy ceny i rabaty
     */
    private $offerSync = false;

    /**
     * Czy rabat był aktywny (opublikowany, niezakończony) przed zapisem w formularzu
     */
    private $liveBeforeSave = [];

    /**
     * Przed zapisem rabatu zapamiętujemy, czy już działał: edycja trwającego rabatu to nie
     * nowa obniżka, a włączenie wyłączonego (albo zakończonego) już tak.
     */
    public function onBeforeDiscountUpdate(&$discount, &$do = null)
    {
        $this->guarded(function () use ($discount) {
            $element = $discount instanceof Event ? $discount->getArgument(0) : $discount;
            $discountId = (int)($element->discount_id ?? 0);

            if ($discountId <= 0) {
                return;
            }

            $db = $this->getDb();
            $query = $db->getQuery(true)
                ->select($db->quoteName(['discount_type', 'discount_published', 'discount_end']))
                ->from($db->quoteName('#__hikashop_discount'))
                ->where($db->quoteName('discount_id') . ' = ' . $discountId);
            $row = $db->setQuery($query)->loadObject();

            $this->liveBeforeSave[$discountId] = $row && $row->discount_type === 'discount'
                && (int)$row->discount_published === 1
                && ((int)$row->discount_end === 0 || (int)$row->discount_end > time());
        });
    }

    public function onAfterDiscountCreate(&$discount)
    {
        $this->guarded(function () use ($discount) {
            $this->discountSaved($discount);
        });
    }

    public function onAfterDiscountUpdate(&$discount)
    {
        $this->guarded(function () use ($discount) {
            $this->discountSaved($discount);
        });
    }

    /**
     * Rabat zapisany w formularzu: włączony teraz dostaje dzisiejszą datę wprowadzenia,
     * pozostałe zmiany (wartość, wyłączenie) rozstrzyga refreshDiscounts().
     */
    private function discountSaved($discount)
    {
        if ($discount instanceof Event) {
            $discount = $discount->getArgument(0);
        }

        $db = $this->getDb();

        $discountId = (int)($discount->discount_id ?? 0);
        if ($discountId <= 0 && !empty($discount->discount_code)) {
            // Przy tworzeniu HikaShop nie wpisuje nowego ID do obiektu (zwraca je tylko z save()),
            // więc szukamy rabatu po kodzie, który jest wymagany
            $query = $db->getQuery(true)
                ->select($db->quoteName('discount_id'))
                ->from($db->quoteName('#__hikashop_discount'))
                ->where($db->quoteName('discount_code') . ' = ' . $db->quote((string)$discount->discount_code))
                ->order($db->quoteName('discount_id') . ' DESC');
            $discountId = (int)$db->setQuery($query, 0, 1)->loadResult();
        }
        if ($discountId <= 0) {
            return;
        }

        $introduced = empty($this->liveBeforeSave[$discountId]) ? $discountId : 0;
        unset($this->liveBeforeSave[$discountId]);

        $this->refreshDiscounts($db, Factory::getDate()->toSql(), $introduced);
    }

    /**
     * Uzgadnia daty wprowadzenia obniżek z rabatami HikaShopa.
     *
     * Wpis ma każdy opublikowany, niezakończony rabat. Wyłączony albo zakończony rabat traci
     * wpis, więc ponowne włączenie to nowa obniżka. Zmiana wartości rabatu to też nowa
     * obniżka: Polska nie ma wyjątku dla tzw. promocji kroczących, każdą obniżkę liczy się
     * od najniższej ceny z 30 dni przed nią (wyjaśnienia Prezesa UOKiK, pkt III.6 i V.1).
     *
     * @param int $introducedId rabat włączony właśnie w formularzu (wprowadzony teraz)
     */
    private function refreshDiscounts($db, $now, $introducedId = 0)
    {
        $time = Factory::getDate($now)->toUnix();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['discount_id', 'discount_start', 'discount_flat_amount', 'discount_percent_amount', 'discount_currency_id']))
            ->from($db->quoteName('#__hikashop_discount'))
            ->where($db->quoteName('discount_type') . ' = ' . $db->quote('discount'))
            ->where($db->quoteName('discount_published') . ' = 1')
            ->where('(' . $db->quoteName('discount_end') . ' = 0 OR ' . $db->quoteName('discount_end') . ' > ' . (int)$time . ')');
        $live = $db->setQuery($query)->loadObjectList('discount_id');

        $query = $db->getQuery(true)
            ->select($db->quoteName(['discount_id', 'active_since', 'conditions']))
            ->from($db->quoteName(self::DISCOUNT_TABLE));
        $known = $db->setQuery($query)->loadObjectList('discount_id');

        $gone = array_map('intval', array_diff(array_keys($known), array_keys($live)));
        if ($gone) {
            $query = $db->getQuery(true)
                ->delete($db->quoteName(self::DISCOUNT_TABLE))
                ->where($db->quoteName('discount_id') . ' IN (' . implode(',', $gone) . ')');
            $db->setQuery($query)->execute();
        }

        foreach ($live as $discountId => $discount) {
            $discountId = (int)$discountId;
            $conditions = $this->discountConditions($discount);
            $row = $known[$discountId] ?? null;

            if ($discountId === (int)$introducedId) {
                $since = $now;
            } elseif ($row === null) {
                // Rabat włączony bez formularza (przycisk na liście, akcja masowa) albo przed 1.3.0:
                // rabat z datą zaczął się w dacie rozpoczęcia, bez daty liczymy od teraz
                $start = (int)$discount->discount_start;
                $since = ($start > 0 && $start <= $time) ? Factory::getDate($start)->toSql() : $now;
            } elseif ((string)$row->conditions === '') {
                // Wpis sprzed 1.3.0: data zostaje, zapamiętujemy tylko warunki
                $since = $row->active_since;
            } elseif ($row->conditions !== $conditions) {
                $since = $now;
            } else {
                continue;
            }

            $db->setQuery(
                'REPLACE INTO ' . $db->quoteName(self::DISCOUNT_TABLE)
                . ' (' . $db->quoteName('discount_id') . ', ' . $db->quoteName('active_since') . ', ' . $db->quoteName('conditions') . ')'
                . ' VALUES (' . $discountId . ', ' . $db->quote($since) . ', ' . $db->quote($conditions) . ')'
            )->execute();
        }
    }

    /**
     * Warunki obniżki: wartość rabatu. Jej zmiana oznacza nową obniżkę.
     */
    private function discountConditions($discount)
    {
        return md5(sprintf(
            '%.5F|%.5F|%d',
            (float)$discount->discount_flat_amount,
            (float)$discount->discount_percent_amount,
            (int)$discount->discount_currency_id
        ));
    }

    /**
     * Dzień wprowadzenia obniżki (SQL datetime, UTC) albo null, gdy rabatu nie da się ustalić.
     * To późniejsza z dat: rozpoczęcia rabatu i zapamiętanego wprowadzenia (włączenie,
     * zmiana wartości).
     */
    private function getDiscountIntroduction($discount, $db, $now)
    {
        if (!is_object($discount)) {
            return null;
        }

        $start = (int)($discount->discount_start ?? 0);
        $startSql = $start > 0 ? Factory::getDate($start)->toSql() : null;
        $discountId = (int)($discount->discount_id ?? 0);
        $since = null;

        if ($discountId > 0) {
            $query = $db->getQuery(true)
                ->select($db->quoteName('active_since'))
                ->from($db->quoteName(self::DISCOUNT_TABLE))
                ->where($db->quoteName('discount_id') . ' = ' . $discountId);
            $since = $db->setQuery($query)->loadResult() ?: null;
        }

        if ($since === null && $startSql === null) {
            if ($discountId <= 0) {
                return null;
            }

            // Rabatu nie zna jeszcze żaden zapis: liczymy od teraz, żeby okno przestało się przesuwać
            $db->setQuery(
                'INSERT IGNORE INTO ' . $db->quoteName(self::DISCOUNT_TABLE)
                . ' (' . $db->quoteName('discount_id') . ', ' . $db->quoteName('active_since') . ')'
                . ' VALUES (' . $discountId . ', ' . $db->quote($now) . ')'
            )->execute();

            return $now;
        }

        if ($since === null) {
            return $startSql;
        }

        return ($startSql !== null && $startSql > $since) ? $startSql : $since;
    }

    /**
     * Najwcześniejsze wprowadzenie wśród trwających rabatów: od niego zależy, które
     * wpisy historii są jeszcze potrzebne. Null, gdy żaden rabat nie trwa.
     */
    private function getEarliestActiveIntroduction($db, $now)
    {
        $this->refreshDiscounts($db, $now);

        $time = Factory::getDate($now)->toUnix();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['discount_id', 'discount_start']))
            ->from($db->quoteName('#__hikashop_discount'))
            ->where($db->quoteName('discount_type') . ' = ' . $db->quote('discount'))
            ->where($db->quoteName('discount_published') . ' = 1')
            ->where($db->quoteName('discount_start') . ' <= ' . (int)$time)
            ->where('(' . $db->quoteName('discount_end') . ' = 0 OR ' . $db->quoteName('discount_end') . ' > ' . (int)$time . ')');
        $discounts = $db->setQuery($query)->loadObjectList();

        $earliest = null;
        foreach ($discounts as $discount) {
            $date = $this->getDiscountIntroduction($discount, $db, $now);
            if ($date !== null && ($earliest === null || $date < $earliest)) {
                $earliest = $date;
            }
        }

        return $earliest;
    }

    /**
     * Zapis produktu w HikaShopie: do historii trafia cena, po której jest teraz oferowany
     */
    public function onAfterProductUpdate(&$element)
    {
        $this->productSaved($element);
    }

    public function onAfterProductCreate(&$element)
    {
        $this->productSaved($element);
    }

    private function productSaved($element)
    {
        $this->guarded(function () use ($element) {
            $product = $element instanceof Event ? $element->getArgument(0) : $element;

            if (!empty($product->product_id)) {
                $this->syncProducts($this->getDb(), [(int)$product->product_id], Factory::getDate()->toSql());
            }
        });
    }

    /**
     * Cron HikaShopa: zapisuje zmiany cen, które ominęły zapis produktu (import CSV, akcja
     * masowa „Zaktualizuj wartości”, początek i koniec rabatu z datą).
     */
    public function onHikashopCronTrigger(&$messages)
    {
        $written = $this->guarded(function () {
            return $this->syncAllProducts($this->getDb(), Factory::getDate()->toSql());
        });

        if ($written) {
            $messages[] = Text::sprintf('PLG_HIKASHOP_OMNIBUS_CRON_REPORT', $written);
        }

        return true;
    }

    /**
     * Przelicza wszystkie produkty główne partiami. Zwraca liczbę nowych wpisów historii.
     */
    private function syncAllProducts($db, $now)
    {
        $this->refreshDiscounts($db, $now);

        $written = 0;
        $lastId = 0;

        do {
            $query = $db->getQuery(true)
                ->select($db->quoteName('product_id'))
                ->from($db->quoteName('#__hikashop_product'))
                ->where($db->quoteName('product_type') . ' = ' . $db->quote('main'))
                ->where($db->quoteName('product_id') . ' > ' . (int)$lastId)
                ->order($db->quoteName('product_id'));
            $ids = $db->setQuery($query, 0, self::CHUNK)->loadColumn();

            if ($ids) {
                $written += $this->syncProducts($db, $ids, $now, false);
                $lastId = (int)end($ids);
            }
        } while (count($ids) === self::CHUNK);

        return $written;
    }

    /**
     * Zapisuje do historii cenę, po której produkty są teraz oferowane wszystkim klientom:
     * cenę regularną albo obniżoną rabatem HikaShopa (także cenę z datą). Wyjaśnienia
     * Prezesa UOKiK (pkt V.2): cena z wcześniejszej promocji jest punktem odniesienia dla
     * kolejnych obniżek przez 30 dni, więc ceny promocyjne muszą być w historii.
     *
     * Cenę wylicza sam HikaShop (wybór rabatu zależy od kategorii, strefy, wykluczeń),
     * a onBeforeLoadProductPrice*() zawęża ją do oferty ogólnodostępnej.
     *
     * @return int liczba nowych wpisów
     */
    private function syncProducts($db, array $ids, $now, $refresh = true)
    {
        if ($refresh) {
            $this->refreshDiscounts($db, $now);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !$this->loadHikashop()) {
            return 0;
        }

        $regular = $this->getRegularPrices($db, $ids);
        if (!$regular) {
            return 0;
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName(['product_id', 'product_type', 'product_parent_id', 'product_tax_id']))
            ->from($db->quoteName('#__hikashop_product'))
            ->where($db->quoteName('product_id') . ' IN (' . implode(',', array_keys($regular)) . ')');
        $products = $db->setQuery($query)->loadObjectList();

        $currencies = [];
        foreach ($regular as $byCurrency) {
            foreach (array_keys($byCurrency) as $currencyId) {
                $currencies[$currencyId] = (int)$currencyId;
            }
        }

        // Strefa sklepu: ta sama, którą HikaShop przyjmuje dla gościa bez adresu
        $zones = explode(',', (string)hikashop_config()->get('main_tax_zone', 0));
        $zoneId = (int)reset($zones);
        $currencyClass = hikashop_get('class.currency');
        $written = 0;

        foreach ($currencies as $currencyId) {
            $rows = array_map(static function ($product) {
                return clone $product;
            }, $products);

            $this->offerSync = true;
            try {
                $currencyClass->getListingPrices($rows, $zoneId, $currencyId, 'unit');
            } finally {
                $this->offerSync = false;
            }

            foreach ($rows as $row) {
                $productId = (int)$row->product_id;
                if (!isset($regular[$productId][$currencyId]) || empty($row->prices)) {
                    continue;
                }

                $price = reset($row->prices);
                // Cena przeliczona z innej waluty to nie cena w tej walucie
                if (!is_object($price) || (int)($price->price_currency_id ?? 0) !== $currencyId
                    || (!empty($price->price_orig_currency_id) && (int)$price->price_orig_currency_id !== $currencyId)) {
                    continue;
                }

                $value = round((float)$price->price_value, 5);
                $discounted = !empty($row->discount) || $value < $regular[$productId][$currencyId] - 0.000005;
                $written += $this->addHistoryEntry($db, $productId, $currencyId, $value, $discounted, $now);
            }
        }

        return $written;
    }

    /**
     * Ceny regularne: ogólnodostępne, bez zakresu dat i bez progu ilościowego.
     * [product_id][currency_id] => najniższa taka cena
     */
    private function getRegularPrices($db, array $ids)
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName(['price_product_id', 'price_currency_id']))
            ->select('MIN(' . $db->quoteName('price_value') . ') AS ' . $db->quoteName('price_value'))
            ->from($db->quoteName('#__hikashop_price'))
            ->where($db->quoteName('price_product_id') . ' IN (' . implode(',', $ids) . ')')
            ->where($db->quoteName('price_min_quantity') . ' IN (0, 1)')
            ->where($db->quoteName('price_access') . ' = ' . $db->quote('all'))
            ->where($db->quoteName('price_users') . ' = ' . $db->quote(''))
            ->where($db->quoteName('price_start_date') . ' = 0')
            ->where($db->quoteName('price_end_date') . ' = 0')
            ->group($db->quoteName(['price_product_id', 'price_currency_id']));

        $regular = [];
        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $regular[(int)$row->price_product_id][(int)$row->price_currency_id] = (float)$row->price_value;
        }

        return $regular;
    }

    /**
     * Dopisuje wpis, gdy cena albo jej rodzaj (regularna/obniżona) różni się od ostatniego.
     *
     * @return int 1 gdy zapisano, 0 gdy bez zmian
     */
    private function addHistoryEntry($db, $productId, $currencyId, $value, $discounted, $now)
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName(['price', 'discounted']))
            ->from($db->quoteName(self::HISTORY_TABLE))
            ->where($db->quoteName('product_id') . ' = ' . (int)$productId)
            ->where($db->quoteName('currency_id') . ' = ' . (int)$currencyId)
            ->order($db->quoteName('date_added') . ' DESC, ' . $db->quoteName('id') . ' DESC');
        $last = $db->setQuery($query, 0, 1)->loadObject();

        if ($last && abs((float)$last->price - $value) < 0.000005 && (int)$last->discounted === (int)$discounted) {
            return 0;
        }

        $data = (object)[
            'product_id' => (int)$productId,
            'price' => $value,
            'currency_id' => (int)$currencyId,
            'discounted' => $discounted ? 1 : 0,
            'date_added' => $now,
        ];
        $db->insertObject(self::HISTORY_TABLE, $data);

        return 1;
    }

    /**
     * Przy zapisie historii liczy się oferta dla wszystkich klientów. HikaShop dobiera rabaty
     * zależnie od tego, kto wywołał zapis (gość, klient, administrator), więc odcinamy rabaty
     * dla grup i pojedynczych klientów. Bez ograniczeń dostępu (HikaShop Starter) rabat
     * działa dla wszystkich, więc zostaje.
     */
    public function onBeforeLoadProductPriceDiscount(&$filters, $rows = null, $options = null)
    {
        if (!$this->offerSync) {
            return;
        }

        if (hikashop_level(2)) {
            $filters['omnibus_access'] = "discount_access = 'all'";
        }
        $filters['omnibus_user'] = "(discount_user_id = '' OR discount_user_id IS NULL)";
    }

    /**
     * Tak samo ceny: bez cen dla grup i pojedynczych klientów
     */
    public function onBeforeLoadProductPrice(&$filters, $rows = null, $options = null)
    {
        if (!$this->offerSync) {
            return;
        }

        if (hikashop_level(2)) {
            $filters['omnibus_access'] = "a.price_access = 'all'";
        }
        $filters['omnibus_users'] = "a.price_users = ''";
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

        if (empty($view->element) || empty($view->element->product_id)) {
            return;
        }

        $product = $view->element;
        $lowestPriceHtml = $this->guarded(function () use ($product) {
            $db = $this->getDb();
            $now = Factory::getDate()->toSql();
            $user = $this->getApplication()->getIdentity();

            // Cena mogła się zmienić bez zapisu produktu (import, akcja masowa, początek rabatu
            // z datą). Zapisujemy tylko przy gościu: rabat HikaShopa może wykluczać grupy
            // zalogowanego klienta, a historia ma mówić o ofercie dla wszystkich.
            if (!$user || $user->guest) {
                $this->syncProducts($db, [(int)$product->product_id], $now);
            } else {
                $this->refreshDiscounts($db, $now);
            }

            return $this->getLowestPriceHtml($product, $db, $now);
        });

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
     * Najniższa cena z historii w oknie [$dateLimit, $dateEnd) (w tej samej walucie).
     *
     * Historia zapisuje tylko ZMIANY ceny (deduplikacja), więc cena niezmieniona od
     * dawna ma jeden wpis sprzed okna. Ten wpis nadal obowiązywał na początku okna,
     * dlatego doliczamy ostatni wpis sprzed $dateLimit - bez tego produkt ze stałą
     * ceną starszą niż okno nie dostawałby żadnej informacji. Zmiany po $dateEnd
     * (po wprowadzeniu obniżki) do okna nie należą.
     */
    private function getLowestHistoryPrice($productId, $currencyId, $dateLimit, $dateEnd, $db)
    {
        $query = $db->getQuery(true)
            ->select('MIN(' . $db->quoteName('price') . ')')
            ->from($db->quoteName(self::HISTORY_TABLE))
            ->where($db->quoteName('product_id') . ' = ' . (int)$productId)
            ->where($db->quoteName('currency_id') . ' = ' . (int)$currencyId)
            ->where($db->quoteName('date_added') . ' >= ' . $db->quote($dateLimit))
            ->where($db->quoteName('date_added') . ' < ' . $db->quote($dateEnd));
        $db->setQuery($query);
        $inWindow = $db->loadResult();

        $query = $db->getQuery(true)
            ->select($db->quoteName('price'))
            ->from($db->quoteName(self::HISTORY_TABLE))
            ->where($db->quoteName('product_id') . ' = ' . (int)$productId)
            ->where($db->quoteName('currency_id') . ' = ' . (int)$currencyId)
            ->where($db->quoteName('date_added') . ' < ' . $db->quote($dateLimit))
            ->order($db->quoteName('date_added') . ' DESC, ' . $db->quoteName('id') . ' DESC')
            ->setLimit(1);
        $db->setQuery($query);
        $before = $db->loadResult();

        $candidates = array_filter([$inWindow, $before], static function ($v) {
            return $v !== null;
        });

        if ($candidates) {
            return min(array_map('floatval', $candidates));
        }

        // Historia zaczyna się po wprowadzeniu obniżki (wtyczkę zainstalowano w trakcie promocji):
        // najstarszy wpis z ceną regularną to cena z chwili instalacji. Cena obniżona się nie
        // nadaje, bo to obecna promocja, a nie cena sprzed niej.
        $query = $db->getQuery(true)
            ->select($db->quoteName('price'))
            ->from($db->quoteName(self::HISTORY_TABLE))
            ->where($db->quoteName('product_id') . ' = ' . (int)$productId)
            ->where($db->quoteName('currency_id') . ' = ' . (int)$currencyId)
            ->where($db->quoteName('discounted') . ' = 0')
            ->order($db->quoteName('date_added') . ' ASC, ' . $db->quoteName('id') . ' ASC')
            ->setLimit(1);
        $first = $db->setQuery($query)->loadResult();

        return $first !== null ? (float)$first : null;
    }

    /**
     * Obsługa akcji czyszczenia historii z ustawień wtyczki (com_ajax, group=hikashop).
     *
     * task=product&product_id=N  - kasuje historię produktu i zapisuje jego cenę regularną
     *                              jako nowy punkt startowy (dla pomyłek w cenach)
     * task=old                   - kasuje wpisy starsze niż okno "Liczba dni", ale zostawia
     *                              dla każdego produktu i waluty ostatni wpis sprzed okna
     *                              (to cena, która obowiązywała na początku okna)
     */
    public function onAjaxOmnibus(AjaxEvent $event)
    {
        $app = $this->getApplication();
        $user = $app ? $app->getIdentity() : null;

        if (!$app || !$app->isClient('administrator') || !$user || !$user->authorise('core.manage', 'com_plugins')) {
            $event->updateEventResult(['ok' => false, 'message' => Text::_('JGLOBAL_AUTH_ACCESS_DENIED')]);
            return;
        }

        if (!Session::checkToken('get')) {
            $event->updateEventResult(['ok' => false, 'message' => Text::_('JINVALID_TOKEN_NOTICE')]);
            return;
        }

        $task = $app->getInput()->getCmd('task');
        $productId = $app->getInput()->getInt('product_id');

        $result = $this->guarded(function () use ($task, $productId) {
            if ($task === 'product') {
                return $this->resetProductHistory($productId);
            }
            if ($task === 'old') {
                return $this->deleteOldEntries();
            }

            return ['ok' => false, 'message' => Text::_('PLG_HIKASHOP_OMNIBUS_CLEAN_BAD_TASK')];
        });

        $event->updateEventResult($result ?: ['ok' => false, 'message' => Text::_('PLG_HIKASHOP_OMNIBUS_CLEAN_ERROR')]);
    }

    /**
     * Kasuje historię produktu. Nowy punkt startowy to cena regularna, a jeśli trwa obniżka,
     * zaraz po niej cena obniżona (jak przy instalacji).
     */
    private function resetProductHistory($productId)
    {
        if ($productId <= 0) {
            return ['ok' => false, 'message' => Text::_('PLG_HIKASHOP_OMNIBUS_CLEAN_BAD_ID')];
        }

        $db = $this->getDb();
        $now = Factory::getDate()->toSql();

        $query = $db->getQuery(true)
            ->delete($db->quoteName(self::HISTORY_TABLE))
            ->where($db->quoteName('product_id') . ' = ' . (int)$productId);
        $db->setQuery($query)->execute();
        $deleted = $db->getAffectedRows();

        foreach ($this->getRegularPrices($db, [(int)$productId]) as $byCurrency) {
            foreach ($byCurrency as $currencyId => $value) {
                $this->addHistoryEntry($db, (int)$productId, (int)$currencyId, round($value, 5), false, $now);
            }
        }
        $this->syncProducts($db, [(int)$productId], $now);

        return [
            'ok' => true,
            'message' => Text::sprintf('PLG_HIKASHOP_OMNIBUS_CLEAN_PRODUCT_DONE', $productId, $deleted),
        ];
    }

    private function deleteOldEntries()
    {
        $db = $this->getDb();
        $now = Factory::getDate()->toSql();
        $daysCount = max(1, (int)$this->params->get('days_count', 30));

        // Trwająca obniżka potrzebuje okna sprzed swojego wprowadzenia, więc granica
        // liczy się od najwcześniejszej z nich, nie od dziś
        $earliest = $this->getEarliestActiveIntroduction($db, $now);
        $base = ($earliest !== null && $earliest < $now) ? $earliest : $now;
        $dateLimit = Factory::getDate($base)->modify('-' . $daysCount . ' days')->toSql();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'product_id', 'currency_id']))
            ->from($db->quoteName(self::HISTORY_TABLE))
            ->where($db->quoteName('date_added') . ' < ' . $db->quote($dateLimit))
            ->order($db->quoteName(['product_id', 'currency_id', 'date_added', 'id']));
        $db->setQuery($query);
        $rows = $db->loadObjectList();

        // W każdej grupie (produkt + waluta) ostatni wpis sprzed okna zostaje
        $toDelete = [];
        $count = count($rows);
        for ($i = 0; $i < $count; $i++) {
            $next = $rows[$i + 1] ?? null;
            $isLastInGroup = !$next
                || $next->product_id !== $rows[$i]->product_id
                || $next->currency_id !== $rows[$i]->currency_id;

            if (!$isLastInGroup) {
                $toDelete[] = (int)$rows[$i]->id;
            }
        }

        foreach (array_chunk($toDelete, 500) as $chunk) {
            $query = $db->getQuery(true)
                ->delete($db->quoteName(self::HISTORY_TABLE))
                ->where($db->quoteName('id') . ' IN (' . implode(',', $chunk) . ')');
            $db->setQuery($query)->execute();
        }

        return [
            'ok' => true,
            'message' => Text::sprintf('PLG_HIKASHOP_OMNIBUS_CLEAN_OLD_DONE', count($toDelete), $daysCount),
        ];
    }

    /**
     * Pobiera sformatowany HTML z najniższą ceną
     */
    private function getLowestPriceHtml($product, $db, $now)
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

        // Pobierz liczbę dni z konfiguracji
        $daysCount = max(1, (int)$this->params->get('days_count', 30));

        // Okno kończy się w chwili wprowadzenia obniżki (art. 4 ust. 2 ustawy o informowaniu
        // o cenach), a nie dziś; bez danych o rabacie zostaje dzisiejsza data
        // HikaShop trzyma nałożony rabat przy produkcie, a przy cenie tylko dla cen wariantów
        $discount = $priceObj->discount ?? ($product->discount ?? null);
        $dateEnd = $this->getDiscountIntroduction($discount, $db, $now) ?? $now;
        $dateLimit = Factory::getDate($dateEnd)->modify('-' . $daysCount . ' days')->toSql();

        $lowestPrice = $this->getLowestHistoryPrice((int)$product->product_id, $currencyId, $dateLimit, $dateEnd, $db);

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

    private function getDb()
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }

    /**
     * Ładuje HikaShopa poza jego widokami (np. akcje com_ajax w ustawieniach wtyczki)
     */
    private function loadHikashop()
    {
        if (function_exists('hikashop_get')) {
            return true;
        }

        $helper = JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';

        return is_file($helper) && include_once $helper;
    }

    /**
     * Błąd wtyczki nie może zablokować strony produktu ani zapisu w panelu. Tabele sprzed
     * 1.3.0 (aktualizacja nie uruchamia SQL instalacji) dostają brakujące kolumny, a potem
     * operacja idzie jeszcze raz.
     */
    private function guarded(callable $operation)
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            try {
                $this->upgradeSchema($this->getDb());

                return $operation();
            } catch (\Throwable $retry) {
                Log::add('Omnibus: ' . $retry->getMessage(), Log::WARNING, 'plg_hikashop_omnibus');

                return null;
            }
        }
    }

    /**
     * Doprowadza tabele do układu z 1.3.0
     */
    private function upgradeSchema($db)
    {
        $columns = $db->getTableColumns(self::HISTORY_TABLE);
        if (!isset($columns['discounted'])) {
            $db->setQuery(
                'ALTER TABLE ' . $db->quoteName(self::HISTORY_TABLE)
                . ' ADD ' . $db->quoteName('discounted') . ' tinyint(1) NOT NULL DEFAULT 0 AFTER ' . $db->quoteName('currency_id')
            )->execute();
        }

        $db->setQuery(
            'CREATE TABLE IF NOT EXISTS ' . $db->quoteName(self::DISCOUNT_TABLE) . ' ('
            . $db->quoteName('discount_id') . ' int(11) NOT NULL, '
            . $db->quoteName('active_since') . ' datetime NOT NULL, '
            . $db->quoteName('conditions') . ' char(32) NOT NULL DEFAULT \'\', '
            . 'PRIMARY KEY (' . $db->quoteName('discount_id') . ')'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci'
        )->execute();

        $columns = $db->getTableColumns(self::DISCOUNT_TABLE);
        if (!isset($columns['conditions'])) {
            $db->setQuery(
                'ALTER TABLE ' . $db->quoteName(self::DISCOUNT_TABLE)
                . ' ADD ' . $db->quoteName('conditions') . ' char(32) NOT NULL DEFAULT \'\''
            )->execute();
        }
    }
}
