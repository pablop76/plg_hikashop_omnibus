CREATE TABLE IF NOT EXISTS `#__hikashop_price_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `price` decimal(15,5) NOT NULL,
  `price_with_tax` decimal(15,5) NULL DEFAULT NULL,
  `tax_rate` decimal(7,5) NULL DEFAULT NULL,
  `currency_id` int(11) NOT NULL,
  `discounted` tinyint(1) NOT NULL DEFAULT 0,
  `date_added` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `product_currency_date` (`product_id`, `currency_id`, `date_added`),
  KEY `date_added` (`date_added`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

-- Wypełnij historię aktualnymi cenami regularnymi jako punkt odniesienia: ogólnodostępne,
-- bez progu ilościowego i bez daty końca - to samo kryterium co Omnibus::getRegularPrices().
-- Cenę brutto (price_with_tax) te wpisy dostają przy pierwszym zapisie produktu przez wtyczkę.
-- Obniżki trwające w chwili instalacji dopisze pierwszy przebieg crona albo wyświetlenie produktu.
INSERT INTO `#__hikashop_price_history` (`product_id`, `price`, `currency_id`, `date_added`)
SELECT
  `price_product_id`,
  MIN(`price_value`),
  `price_currency_id`,
  UTC_TIMESTAMP()
FROM `#__hikashop_price`
WHERE `price_min_quantity` IN (0, 1)
  AND `price_access` = 'all'
  AND `price_users` = ''
  AND `price_start_date` <= UNIX_TIMESTAMP()
  AND `price_end_date` = 0
GROUP BY `price_product_id`, `price_currency_id`;

-- Chwila wprowadzenia obniżki dla trwających i zaplanowanych rabatów (od 1.2.0) oraz ich
-- wartość (od 1.3.0: zmiana wartości to nowa obniżka). Przy aktualizacji ze starszej wersji
-- brakujące kolumny dodaje sama wtyczka.
CREATE TABLE IF NOT EXISTS `#__hikashop_omnibus_discount` (
  `discount_id` int(11) NOT NULL,
  `active_since` datetime NOT NULL,
  `conditions` char(32) NOT NULL DEFAULT '',
  PRIMARY KEY (`discount_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
