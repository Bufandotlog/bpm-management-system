CREATE TABLE IF NOT EXISTS `gallery_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `periode_id` int(11) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `image_path` varchar(500) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subtitle` varchar(500) NOT NULL DEFAULT '',
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gallery_cards_period_order` (`periode_id`, `sort_order`, `id`),
  KEY `gallery_cards_created_by` (`created_by`),
  KEY `gallery_cards_updated_by` (`updated_by`),
  CONSTRAINT `fk_gallery_cards_period` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gallery_cards_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gallery_cards_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
