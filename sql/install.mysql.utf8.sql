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

-- Wypełnij historię aktualnymi cenami produktów jako punkt odniesienia
INSERT INTO `#__hikashop_price_history` (`product_id`, `price`, `currency_id`, `date_added`)
SELECT 
  `price_product_id`,
  `price_value`,
  `price_currency_id`,
  NOW()
FROM `#__hikashop_price`
WHERE `price_min_quantity` = 0;
