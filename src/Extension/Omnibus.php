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
     * Na stronie bywa kilka widoków produktów (karta, moduły): rabaty i ceny uzgadniamy raz
     */
    private $discountsRefreshed = false;

    private $syncedIds = [];

    private $cssLoaded = false;

    private $scriptLoaded = false;

    /**
     * Początek oferowania produktów (pamięć na czas żądania)
     */
    private $offeringStarts = [];

    /**
     * Przed zapisem rabatu (formularz, przełącznik publikacji na liście) zapamiętujemy, czy już
     * działał: edycja trwającego rabatu to nie nowa obniżka, a włączenie wyłączonego
     * (albo zakończonego) już tak.
     */
    public function onBeforeDiscountUpdate(&$discount, &$do = null)
    {
        $this->guarded(function () use ($discount) {
            $element = $discount instanceof Event ? $discount->getArgument(0) : $discount;
            $this->rememberLiveDiscounts([(int)($element->discount_id ?? 0)]);
        });
    }

    /**
     * Zapamiętuje, które z rabatów działają (opublikowane, niezakończone) przed zmianą
     */
    private function rememberLiveDiscounts(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return;
        }

        $db = $this->getDb();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['discount_id', 'discount_type', 'discount_published', 'discount_end']))
            ->from($db->quoteName('#__hikashop_discount'))
            ->where($db->quoteName('discount_id') . ' IN (' . implode(',', $ids) . ')');
        $rows = $db->setQuery($query)->loadObjectList('discount_id');

        foreach ($ids as $discountId) {
            $row = $rows[$discountId] ?? null;
            $this->liveBeforeSave[$discountId] = $row && $row->discount_type === 'discount'
                && (int)$row->discount_published === 1
                && ((int)$row->discount_end === 0 || (int)$row->discount_end > time());
        }
    }

    /**
     * Rabaty zapamiętane przed zmianą jako niedziałające: ich włączenie to wprowadzenie
     * obniżki teraz. Rabatu bez zapamiętanego stanu nie ruszamy (obsłużyło go już zdarzenie
     * pojedynczego zapisu, np. w trakcie akcji masowej).
     */
    private function takeIntroducedDiscounts(array $ids)
    {
        $introduced = [];
        foreach (array_unique(array_map('intval', $ids)) as $discountId) {
            if (!array_key_exists($discountId, $this->liveBeforeSave)) {
                continue;
            }
            if (!$this->liveBeforeSave[$discountId]) {
                $introduced[] = $discountId;
            }
            unset($this->liveBeforeSave[$discountId]);
        }

        return $introduced;
    }

    /**
     * Usunięty rabat nie potrzebuje daty wprowadzenia
     */
    public function onAfterDiscountDelete(&$ids)
    {
        $this->guarded(function () {
            $this->refreshDiscounts($this->getDb(), Factory::getDate()->toSql());
        });
    }

    public function onAfterDiscountCreate(&$discount)
    {
        $this->guarded(function () use ($discount) {
            $this->discountSaved($discount, true);
        });
    }

    public function onAfterDiscountUpdate(&$discount)
    {
        $this->guarded(function () use ($discount) {
            $this->discountSaved($discount, false);
        });
    }

    /**
     * Rabat zapisany w panelu (formularz, przełącznik publikacji na liście): nowy albo włączony
     * teraz dostaje dzisiejszą datę wprowadzenia, pozostałe zmiany (wartość, wyłączenie)
     * rozstrzyga refreshDiscounts().
     */
    private function discountSaved($discount, $created)
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

        $introduced = $created ? [$discountId] : $this->takeIntroducedDiscounts([$discountId]);
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
     * @param int[] $introducedIds rabaty włączone właśnie w panelu (wprowadzone teraz)
     */
    private function refreshDiscounts($db, $now, array $introducedIds = [])
    {
        $introducedIds = array_map('intval', $introducedIds);
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

            if (in_array($discountId, $introducedIds, true)) {
                $since = $now;
            } elseif ($row === null) {
                // Rabat włączony bez zdarzenia HikaShopa (np. zmiana wprost w bazie) albo przed 1.3.0:
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
     * Import CSV zapisuje ceny wprost w bazie, bez zdarzeń zapisu produktu. Po każdej partii
     * importu HikaShop podaje produkty z już nadanym product_id.
     */
    public function onAfterProductsImport(&$view, &$importProducts)
    {
        $this->guarded(function () use ($importProducts) {
            $ids = [];
            foreach ((array)$importProducts as $product) {
                if (is_object($product) && !empty($product->product_id)) {
                    $ids[] = (int)$product->product_id;
                }
            }

            $this->syncProductsInChunks($this->getDb(), $ids, Factory::getDate()->toSql());
        });
    }

    /**
     * Przed akcją masową na rabatach zapamiętujemy, które działały (jak przy formularzu)
     */
    public function onBeforeMassactionProcess(&$massaction, &$elements, $report = null, &$do = null)
    {
        $this->guarded(function () use ($massaction, $elements) {
            if ($this->massactionTable($massaction) === 'discount') {
                $this->rememberLiveDiscounts($this->massactionIds($elements, 'discount_id'));
            }
        });
    }

    /**
     * Akcja masowa (np. „Zaktualizuj wartości” cen) zapisuje wprost w bazie. Po niej
     * zapisujemy ceny produktów albo uzgadniamy rabaty.
     */
    public function onAfterMassactionProcess(&$massaction, &$elements, $report = null)
    {
        $this->guarded(function () use ($massaction, $elements) {
            $table = $this->massactionTable($massaction);
            $db = $this->getDb();
            $now = Factory::getDate()->toSql();

            if ($table === 'discount') {
                $ids = $this->massactionIds($elements, 'discount_id');
                $this->refreshDiscounts($db, $now, $this->takeIntroducedDiscounts($ids));
            } elseif ($table === 'product') {
                $this->syncProductsInChunks($db, $this->massactionIds($elements, 'product_id'), $now);
            }
        });
    }

    private function massactionTable($massaction)
    {
        return is_object($massaction) ? strtolower((string)($massaction->massaction_table ?? '')) : '';
    }

    /**
     * ID z elementów akcji masowej: obiekty wierszy tabeli albo same liczby
     */
    private function massactionIds($elements, $key)
    {
        $ids = [];
        foreach ((array)$elements as $element) {
            $id = is_object($element) ? ($element->$key ?? 0) : $element;
            if (is_numeric($id) && (int)$id > 0) {
                $ids[] = (int)$id;
            }
        }

        return $ids;
    }

    /**
     * syncProducts() partiami po CHUNK produktów (import i akcje masowe bywają duże)
     */
    private function syncProductsInChunks($db, array $ids, $now)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $written = 0;

        foreach (array_chunk($ids, self::CHUNK) as $i => $chunk) {
            $written += $this->syncProducts($db, $chunk, $now, $i === 0);
        }

        return $written;
    }

    /**
     * Cron HikaShopa (tylko wersje płatne): zapisuje zmiany cen, o których HikaShop nie
     * powiadamia wtyczek (początek i koniec rabatu z datą, cena zmieniona wprost w bazie
     * albo przez zewnętrzną integrację).
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
                // Warianty też: wariant z własną ceną ma własną historię (bez ceny syncProducts go pomija)
                ->where($db->quoteName('product_type') . ' IN (' . $db->quote('main') . ',' . $db->quote('variant') . ')')
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
                $regularValue = round($regular[$productId][$currencyId], 5);
                $discounted = !empty($row->discount) || $value < $regularValue - 0.000005;
                $written += $this->addHistoryEntry($db, $productId, $currencyId, $value, $discounted, $now, $regularValue);
            }
        }

        return $written;
    }

    /**
     * Ceny regularne: ogólnodostępne, bez progu ilościowego i bez daty końca. Data początku
     * z przeszłości nie przeszkadza (taka cena po prostu obowiązuje od tamtej pory).
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
            ->where($db->quoteName('price_start_date') . ' <= ' . time())
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
     * Produkt bez historii, który trafia do niej w trakcie obniżki, dostaje najpierw cenę
     * regularną jako punkt startowy (jak przy instalacji): inaczej nie byłoby czego pokazać.
     *
     * @return int liczba zapisanych wpisów
     */
    private function addHistoryEntry($db, $productId, $currencyId, $value, $discounted, $now, $regularValue = null)
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

        $written = 0;
        if (!$last && $discounted && $regularValue !== null) {
            $written += $this->addHistoryEntry($db, $productId, $currencyId, $regularValue, false, $now);
        }

        $data = (object)[
            'product_id' => (int)$productId,
            'price' => $value,
            'currency_id' => (int)$currencyId,
            'discounted' => $discounted ? 1 : 0,
            'date_added' => $now,
        ];
        $db->insertObject(self::HISTORY_TABLE, $data);

        return $written + 1;
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
     * Informacja o najniższej cenie na karcie produktu, na listach produktów (także w modułach
     * HikaShopa) i na stronie koszyka: UOKiK wymaga jej wszędzie, gdzie widać obniżkę
     * konkretnego produktu, w najbliższym sąsiedztwie ceny.
     */
    public function onHikashopBeforeDisplayView(&$view)
    {
        // Sprawdź czy to frontend
        if (hikashop_isClient('administrator')) {
            return;
        }

        $name = (string)$view->getName();

        // Strona koszyka (i listy życzeń): napis przy produkcie, skrypt przenosi go pod cenę
        if ($name === 'cart') {
            if (!empty($view->cart->products) && is_array($view->cart->products) && $this->showsPrices($view)) {
                $this->addListInfos($view->cart->products, 'cart', true);
            }
            return;
        }

        // Moduł koszyka nie ma miejsca na napis: przy dopasowaniu nie ogłasza obniżki wcale (CSS)
        if ($name === 'product' && $view->getLayout() === 'cart') {
            if ((int)$this->params->get('adjust_discount', 1)) {
                $this->loadCustomCSS();
            }
            return;
        }

        // Moduł albo lista bez cen nie ogłasza obniżki, więc i informacji nie potrzebuje
        if ($name !== 'product' || !$this->showsPrices($view)) {
            return;
        }

        // Karta produktu: miejsce tuż po cenie
        if (!empty($view->element) && !empty($view->element->product_id)) {
            $this->addProductPageInfo($view->element);
            return;
        }

        // Lista produktów (kategoria, wyszukiwanie, moduł). Szablon ceny listy nie ma miejsca
        // dla wtyczek, więc napis trafia do najbliższego (afterProductName), a skrypt przenosi
        // go tuż za cenę.
        if (!empty($view->rows) && is_array($view->rows)) {
            $this->addListInfos($view->rows, 'afterProductName', false);
        }
    }

    /**
     * Napisy dla listy produktów albo koszyka
     *
     * @param bool $firstPrice w wierszu są dwie ceny (koszyk: za sztukę i razem), napis idzie
     *                         pod pierwszą
     */
    private function addListInfos(array $products, $position, $firstPrice)
    {
        $this->addInfos($products, function ($product, $info) use ($position, $firstPrice) {
            $attrs = ['data-omnibus-move' => '1'];
            if ($firstPrice) {
                $attrs['data-omnibus-first'] = '1';
            }

            $this->appendExtraData($product, $position, $this->renderLowestPrice($info, 'hikashop-omnibus-lowest-price--list', $attrs));
            $this->loadScript();
        });
    }

    /**
     * Karta produktu. Przy wariantach HikaShop pokazuje wariant domyślny, a ceny pozostałych
     * trzyma w ukrytych blokach i kopiuje je pod cenę po wyborze. Napisy wariantów czekają
     * w ukrytym bloku, skrypt podmienia napis po zdarzeniu hkAfterUpdateVariant.
     */
    private function addProductPageInfo($element)
    {
        $products = [$element];
        if (!empty($element->variants) && is_array($element->variants)) {
            foreach ($element->variants as $variant) {
                if (is_object($variant) && !empty($variant->product_id)) {
                    $products[] = $variant;
                }
            }
        }

        $main = '';
        $variants = [];
        $this->addInfos($products, function ($product, $info) use ($element, &$main, &$variants) {
            if ($product === $element) {
                $main = $this->renderLowestPrice($info, '', ['data-omnibus-price' => 'hikashop_product_price_main']);
            } else {
                $variants[(int)$product->product_id] = $this->renderLowestPrice($info);
            }
        });

        if ($main === '' && !$variants) {
            return;
        }

        $html = '<div id="hikashop_omnibus_main">' . $main . '</div>';
        if ($variants) {
            $html .= '<div hidden>';
            foreach ($variants as $productId => $variantHtml) {
                $html .= '<div data-omnibus-variant-of="' . $productId . '">' . $variantHtml . '</div>';
            }
            $html .= '</div>';
        }

        $this->appendExtraData($element, 'rightMiddle', $html);
        $this->loadScript();
    }

    /**
     * Liczy informację dla każdego produktu z obniżką i przekazuje ją do $use.
     * Przy gościu najpierw uzupełnia historię (cena mogła się zmienić bez zapisu produktu).
     *
     * @param callable $use ($product, array $info)
     */
    private function addInfos(array $products, callable $use)
    {
        $discounted = array_filter($products, function ($product) {
            return is_object($product) && !empty($product->product_id) && $this->hasDiscount($product);
        });

        if (!$discounted) {
            return;
        }

        $this->loadCustomCSS();

        $this->guarded(function () use ($discounted, $use) {
            $db = $this->getDb();
            $now = Factory::getDate()->toSql();
            $user = $this->getApplication()->getIdentity();

            $ids = [];
            foreach ($discounted as $key => $product) {
                $ids[$key] = $this->historyProductId($db, $product);
            }

            // Cena mogła się zmienić bez zapisu produktu (import, akcja masowa, początek rabatu
            // z datą). Zapisujemy tylko przy gościu: rabat HikaShopa może wykluczać grupy
            // zalogowanego klienta, a historia ma mówić o ofercie dla wszystkich.
            // Na stronie bywa kilka list (moduły), więc każdy produkt i rabaty raz na żądanie.
            $toSync = array_diff(array_unique($ids), array_keys($this->syncedIds));
            if ((!$user || $user->guest) && $toSync) {
                $this->syncProducts($db, $toSync, $now, !$this->discountsRefreshed);
                $this->syncedIds += array_fill_keys($toSync, true);
                $this->discountsRefreshed = true;
            } elseif (!$this->discountsRefreshed) {
                $this->refreshDiscounts($db, $now);
                $this->discountsRefreshed = true;
            }

            foreach ($discounted as $key => $product) {
                $info = $this->getLowestPriceInfo($product, $ids[$key], $db, $now);
                if ($info !== null) {
                    $use($product, $info);
                }
            }
        });
    }

    private function appendExtraData($product, $position, $html)
    {
        if (!isset($product->extraData) || !is_object($product->extraData)) {
            $product->extraData = new \stdClass();
        }
        if (!isset($product->extraData->{$position}) || !is_array($product->extraData->{$position})) {
            $product->extraData->{$position} = [];
        }
        $product->extraData->{$position}[] = $html;
    }

    /**
     * Czy widok pokazuje ceny: to samo sprawdzenie co w szablonach HikaShopa
     * („-1” = ustawienie z konfiguracji sklepu)
     */
    private function showsPrices($view)
    {
        if (empty($view->params) || !is_object($view->params) || !method_exists($view->params, 'get')) {
            return true;
        }

        $show = $view->params->get('show_price', '-1');
        if ($show === null || (string)$show === '-1') {
            $show = hikashop_config()->get('show_price', 1);
        }

        return (bool)$show;
    }

    /**
     * Cena, którą widzi klient. W koszyku ceny są za całą ilość, a cena za sztukę siedzi
     * w unit_price.
     */
    private function displayedPrice($product)
    {
        if (empty($product->prices) || !is_array($product->prices)) {
            return null;
        }

        $price = reset($product->prices);
        if (is_object($price) && isset($price->unit_price) && is_object($price->unit_price)) {
            $price = $price->unit_price;
        }

        return is_object($price) ? $price : null;
    }

    /**
     * Czy na produkt działa rabat HikaShopa (wtedy HikaShop podaje cenę sprzed rabatu)
     */
    private function hasDiscount($product)
    {
        $price = $this->displayedPrice($product);

        return $price !== null && isset($price->price_value_without_discount)
            && $price->price_value_without_discount > $price->price_value;
    }

    /**
     * Czyja historia: wariant bez własnej ceny ma cenę (i historię) produktu głównego.
     * Na karcie produkt główny jest w ->main, w koszyku wariant zna product_parent_id.
     */
    private function historyProductId($db, $product)
    {
        $productId = (int)$product->product_id;
        $mainId = (int)($product->main->product_id ?? 0);
        if ($mainId <= 0 && ($product->product_type ?? '') === 'variant') {
            $mainId = (int)($product->product_parent_id ?? 0);
        }

        if ($mainId > 0 && $mainId !== $productId && !$this->getRegularPrices($db, [$productId])) {
            return $mainId;
        }

        return $productId;
    }

    /**
     * Ładuje niestandardowy CSS z parametrami z konfiguracji
     */
    private function loadCustomCSS()
    {
        if ($this->cssLoaded) {
            return;
        }
        $this->cssLoaded = true;

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
        .hikashop-omnibus-lowest-price--list {
            margin: 2px 0 4px;
            font-size: 0.75em;
            font-weight: normal;
            line-height: 1.35;
            letter-spacing: normal;
            text-transform: none;
            word-break: normal;
            overflow-wrap: normal;
        }
        .hikashop-omnibus-lowest-price--list .omnibus-price-value {
            white-space: nowrap;
        }
        ";

        if ((int)$this->params->get('adjust_discount', 1)) {
            $css .= "
        .hikashop_cart .hikashop_product_price_before_discount,
        .hikashop_cart .hikashop_product_discount {
            display: none;
        }
        ";
        }

        Factory::getApplication()->getDocument()->addStyleDeclaration($css);
    }

    /**
     * Skrypt napisów:
     * - listy, moduły i koszyk: napis z miejsca dla wtyczek trafia tuż za cenę produktu
     *   (szuka najmniejszego elementu nad napisem z ceną; kilka cen = napis bez własnej ceny,
     *   zostaje na miejscu, chyba że to koszyk z ceną za sztukę i razem),
     * - rabat HikaShopa przy cenie: procent albo kwota liczone od najniższej ceny
     *   (data-omnibus-saving), niepodpisana cena sprzed rabatu znika,
     * - warianty: ukryte ceny wariantów dostają poprawiony rabat od razu (HikaShop kopiuje je
     *   przy wyborze), a napis pod ceną zmienia się po zdarzeniu hkAfterUpdateVariant.
     * Obserwator obsługuje treści doładowane później (filtry, przewijanie, koszyk AJAX).
     */
    private function loadScript()
    {
        if ($this->scriptLoaded) {
            return;
        }
        $this->scriptLoaded = true;

        $js = <<<'JS'
(function () {
    function adjust(block, info) {
        if (!block || !info || !info.hasAttribute('data-omnibus-saving')) {
            return;
        }
        var saving = info.getAttribute('data-omnibus-saving');
        block.querySelectorAll('.hikashop_product_price_before_discount').forEach(function (el) {
            el.style.display = 'none';
        });
        block.querySelectorAll('.hikashop_product_discount').forEach(function (el) {
            var amount = el.querySelector('.hikashop_product_discount_amount');
            if (saving && amount) {
                amount.textContent = saving;
            } else {
                el.style.display = 'none';
            }
        });
    }

    // Wybór wariantu (np. "_23") -> ID produktu wariantu
    function variantIds() {
        var map = {};
        document.querySelectorAll('[id^="hikashop_product_id_"]').forEach(function (el) {
            var input = el.querySelector('input[name="product_id"]');
            var key = el.id.substring('hikashop_product_id'.length);
            if (input && key !== '_main') {
                map[key] = input.value;
            }
        });
        return map;
    }

    function place() {
        document.querySelectorAll('.hikashop-omnibus-lowest-price[data-omnibus-move]').forEach(function (info) {
            info.removeAttribute('data-omnibus-move');
            var first = info.hasAttribute('data-omnibus-first');
            for (var box = info.parentElement; box && box !== document.body; box = box.parentElement) {
                var prices = box.querySelectorAll('.hikashop_product_price_full');
                if (!prices.length) {
                    continue;
                }
                if (prices.length > 1 && !first) {
                    return;
                }
                prices[0].insertAdjacentElement('afterend', info);
                // Krój kwoty ceny, nie kontenera karty (w kartach bywa krój nagłówków)
                var amount = prices[0].querySelector('.hikashop_product_price');
                if (amount) {
                    info.style.fontFamily = getComputedStyle(amount).fontFamily;
                }
                prices.forEach(function (price) {
                    adjust(price, info);
                });
                return;
            }
        });

        document.querySelectorAll('.hikashop-omnibus-lowest-price[data-omnibus-price]').forEach(function (info) {
            adjust(document.getElementById(info.getAttribute('data-omnibus-price')), info);
            info.removeAttribute('data-omnibus-price');
        });

        var holders = document.querySelectorAll('[data-omnibus-variant-of]:not([data-omnibus-done])');
        if (holders.length) {
            var ids = variantIds();
            holders.forEach(function (holder) {
                holder.setAttribute('data-omnibus-done', '1');
                var info = holder.querySelector('.hikashop-omnibus-lowest-price');
                Object.keys(ids).forEach(function (key) {
                    if (ids[key] === holder.getAttribute('data-omnibus-variant-of')) {
                        adjust(document.getElementById('hikashop_product_price' + key), info);
                    }
                });
            });
        }
    }

    function selectVariant(params) {
        var main = document.getElementById('hikashop_omnibus_main');
        if (!main || !params || !params.selection) {
            return;
        }
        var id = variantIds()[params.selection];
        var holder = id ? document.querySelector('[data-omnibus-variant-of="' + id + '"]') : null;
        main.innerHTML = holder ? holder.innerHTML : '';
    }

    document.addEventListener('DOMContentLoaded', function () {
        place();
        new MutationObserver(place).observe(document.body, {childList: true, subtree: true});
        if (window.Oby && window.Oby.registerAjax) {
            window.Oby.registerAjax('hkAfterUpdateVariant', selectVariant);
        }
    });
})();
JS;

        Factory::getApplication()->getDocument()->addScriptDeclaration($js);
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
     * Informacja o najniższej cenie dla produktu z obniżką albo null:
     * ['label' => opis, 'value' => sformatowana najniższa cena,
     *  'saving' => wielkość obniżki od najniższej ceny (null, gdy wyłączone dopasowanie)]
     */
    private function getLowestPriceInfo($product, $historyId, $db, $now)
    {
        // KLUCZOWE: Zgodnie z dyrektywą Omnibus informacja ma się pokazywać
        // TYLKO przy ogłoszeniu obniżki (rabat HikaShopa)
        if (!$this->hasDiscount($product)) {
            return null;
        }

        $priceObj = $this->displayedPrice($product);

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
        $label = Text::sprintf('PLG_HIKASHOP_OMNIBUS_LOWEST_PRICE_LABEL', $daysCount);

        // Towar oferowany krócej niż okno (art. 4 ust. 3, wyjaśnienia UOKiK pkt III.3): najniższa
        // cena od wprowadzenia towaru. Rabat od pierwszego dnia sprzedaży nie jest obniżką
        // (UOKiK: cena nadchodząca), więc wtedy informacji nie ma z czego policzyć.
        $offered = $this->getOfferingStart($db, $historyId);
        if ($offered !== null) {
            if ($offered >= $dateEnd) {
                // Bez napisu, ale z dopasowaniem rabatu HikaShop nie pokaże tu oznak obniżki
                return (int)$this->params->get('adjust_discount', 1)
                    ? ['label' => '', 'value' => '', 'saving' => '']
                    : null;
            }
            if ($offered > $dateLimit) {
                $dateLimit = $offered;
                $label = Text::_('PLG_HIKASHOP_OMNIBUS_LOWEST_PRICE_NEW_LABEL');
            }
        }

        $lowestPrice = $this->getLowestHistoryPrice($historyId, $currencyId, $dateLimit, $dateEnd, $db);

        if (!$lowestPrice) {
            return null;
        }

        // Historia trzyma ceny netto: podatek doliczamy w tej samej proporcji co w bieżącej cenie
        $ratio = 1.0;
        if (isset($priceObj->price_value_with_tax, $priceObj->price_value)
            && $priceObj->price_value > 0
            && $priceObj->price_value_with_tax != $priceObj->price_value) {
            $ratio = $priceObj->price_value_with_tax / $priceObj->price_value;
        }
        $lowestShown = (float)$lowestPrice * $ratio;
        $currentShown = (float)$priceObj->price_value * $ratio;

        $currencyHelper = hikashop_get('class.currency');

        return [
            'label' => $label,
            'value' => $currencyHelper->format($lowestShown, $currencyId),
            'saving' => (int)$this->params->get('adjust_discount', 1)
                ? $this->savingText($lowestShown, $currentShown, $discount, $currencyId, $currencyHelper)
                : null,
        ];
    }

    /**
     * Wielkość obniżki liczona od najniższej ceny (UOKiK pkt V.2: nie od ceny regularnej),
     * w tej samej postaci co rabat HikaShopa: kwota przy rabacie kwotowym, inaczej procent
     * zaokrąglony w dół, żeby nie zawyżać. Pusty tekst: cena nie jest niższa od najniższej.
     */
    private function savingText($lowest, $current, $discount, $currencyId, $currencyHelper)
    {
        $diff = $lowest - $current;
        if ($lowest <= 0 || $diff < 0.005) {
            return '';
        }

        if (is_object($discount) && (float)($discount->discount_flat_amount ?? 0) != 0.0) {
            return $currencyHelper->format(-$diff, $currencyId);
        }

        $percent = (int)floor($diff / $lowest * 100 + 1e-9);

        return $percent > 0 ? '-' . $percent . '%' : '';
    }

    /**
     * Początek oferowania produktu (SQL datetime, UTC) albo null, gdy nieznany: data
     * utworzenia, a gdy później zaczyna się sprzedaż, jej początek. Import HikaShopa nadpisuje
     * datę utworzenia, więc wcześniejszy wpis historii ma pierwszeństwo.
     */
    private function getOfferingStart($db, $productId)
    {
        $productId = (int)$productId;
        if (array_key_exists($productId, $this->offeringStarts)) {
            return $this->offeringStarts[$productId];
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName(['product_created', 'product_sale_start']))
            ->from($db->quoteName('#__hikashop_product'))
            ->where($db->quoteName('product_id') . ' = ' . $productId);
        $row = $db->setQuery($query)->loadObject();

        $start = null;
        $created = $row ? (int)$row->product_created : 0;
        if ($created > 0) {
            $query = $db->getQuery(true)
                ->select('MIN(' . $db->quoteName('date_added') . ')')
                ->from($db->quoteName(self::HISTORY_TABLE))
                ->where($db->quoteName('product_id') . ' = ' . $productId);
            $first = $db->setQuery($query)->loadResult();
            if ($first) {
                $created = min($created, Factory::getDate($first)->toUnix());
            }

            $start = Factory::getDate(max($created, (int)$row->product_sale_start))->toSql();
        }

        return $this->offeringStarts[$productId] = $start;
    }

    /**
     * HTML napisu
     */
    private function renderLowestPrice(array $info, $class = '', array $attrs = [])
    {
        if ($info['saving'] !== null) {
            $attrs['data-omnibus-saving'] = $info['saving'];
        }

        $html = '<div class="hikashop-omnibus-lowest-price' . ($class !== '' ? ' ' . $class : '') . '"';
        foreach ($attrs as $name => $value) {
            $html .= ' ' . $name . '="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
        }

        // Sam znacznik dla skryptu (rabat od pierwszego dnia sprzedaży): bez tekstu
        if ($info['label'] === '') {
            return $html . ' hidden></div>';
        }

        return $html . '>
            <span class="omnibus-price-label">' . $info['label'] . ': </span>
            <span class="omnibus-price-value">' . $info['value'] . '</span>
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
