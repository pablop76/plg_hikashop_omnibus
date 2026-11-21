CREATE TABLE IF NOT EXISTS `#__hikashop_price_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `price` decimal(15,5) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `date_added` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `date_added` (`date_added`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

-- Wypełnij historię aktualnymi cenami produktów jako punkt odniesienia.
-- Tylko ceny regularne: ogólnodostępne (price_access = 'all'), bez zaplanowanego
-- okna promocyjnego (price_start_date/end_date = 0) - to samo kryterium co przy
-- bieżącym zapisie historii w Omnibus::savePriceHistory().
INSERT INTO `#__hikashop_price_history` (`product_id`, `price`, `currency_id`, `date_added`)
SELECT
  `price_product_id`,
  `price_value`,
  `price_currency_id`,
  UTC_TIMESTAMP()
FROM `#__hikashop_price`
WHERE `price_min_quantity` = 0
  AND `price_access` = 'all'
  AND `price_start_date` = 0
  AND `price_end_date` = 0;

-- Chwila włączenia rabatów bez daty rozpoczęcia (od 1.2.0). Okno najniższej ceny kończy się
-- w dniu wprowadzenia obniżki; przy aktualizacji ze starszej wersji tabela powstaje sama.
CREATE TABLE IF NOT EXISTS `#__hikashop_omnibus_discount` (
  `discount_id` int(11) NOT NULL,
  `active_since` datetime NOT NULL,
  PRIMARY KEY (`discount_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
