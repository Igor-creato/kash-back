<?php

declare(strict_types=1);

namespace KashBack;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Installer class
 */
class Installer
{
    /**
     * Run the installer.
     *
     * @return void
     */
    public function run(): void
    {
        $this->add_version();
        $this->create_tables();
    }

    /**
     * Add time and version on DB.
     *
     * @return void
     */
    public function add_version(): void
    {
        $installed = get_option('kash_back_installed');

        if (! $installed) {
            update_option('kash_back_installed', time());
        }

        update_option('kash_back_version', KASH_BACK_VERSION);
    }

    /**
     * Create necessary database tables.
     *
     * @return void
     */
    public function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_name      = $wpdb->prefix . 'affiliate_tracking';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Уникальный идентификатор записи',
            `user_id` bigint(20) COMMENT 'ID пользователя WordPress',
            `date_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время создания записи',
            `external_url` text NOT NULL COMMENT 'Внешняя партнерская ссылка',
            `internal_url` text NOT NULL COMMENT 'Внутренняя ссылка на сайте',
            `product_id` bigint(20) unsigned NULL COMMENT 'ID продукта WooCommerce (если применимо)',
            `referrer_url` text NULL COMMENT 'URL страницы с которой пришел пользователь',
            `user_agent` text NULL COMMENT 'User Agent браузера пользователя',
            `ip_address` varchar(45) NULL COMMENT 'IP адрес пользователя',
            `status` enum('на проверке', 'подтвержден') NOT NULL DEFAULT 'на проверке' COMMENT 'Статус перехода',
            `commission_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Сумма комиссии',
            `partner_id` varchar(255) NULL COMMENT 'Идентификатор партнера',
            `conversion_date` datetime NULL COMMENT 'Дата подтверждения конверсии',
            `notes` text NULL COMMENT 'Дополнительные заметки',
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_date_created` (`date_created`),
            KEY `idx_status` (`status`),
            KEY `idx_product_id` (`product_id`),
            KEY `idx_partner_id` (`partner_id`)
        ) {$charset_collate} COMMENT='Таблица для отслеживания переходов по партнерским ссылкам';";

        dbDelta($sql);
    }
}
