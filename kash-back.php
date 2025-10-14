<?php

/**
 * Plugin Name: Kash Back - Партнерские ссылки с ID пользователя
 * Description: Автоматически добавляет ID текущего пользователя к внешним партнерским ссылкам WooCommerce
 * Version: 1.0.0
 * Author: Kash Back
 * Text Domain: kash-back
 * Domain Path: /languages
 */

// Запрет прямого доступа
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Основной класс плагина
 */
class Kash_Back
{

    /**
     * Экземпляр класса
     */
    private static $instance = null;

    /**
     * Получение экземпляра класса (паттерн Singleton)
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Метод активации плагина
     */
    public static function activate()
    {
        global $wpdb;

        // Получаем префикс таблиц WordPress
        $table_name = $wpdb->prefix . 'affiliate_tracking';

        // SQL-запрос для создания таблицы
        $sql = "CREATE TABLE `{$table_name}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Уникальный идентификатор записи',
            `user_id` bigint(20) unsigned NULL COMMENT 'ID пользователя WordPress (NULL для анонимных)',
            `session_id` varchar(255) NULL COMMENT 'ID сессии для анонимных пользователей',
            `date_created` date NOT NULL DEFAULT (CURRENT_DATE) COMMENT 'Дата создания записи',
            `time_created` time NOT NULL DEFAULT (CURRENT_TIME) COMMENT 'Время создания записи',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Таблица для отслеживания переходов по партнерским ссылкам';";

        // Используем dbDelta для безопасного создания/обновления таблицы
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Устанавливаем флаг для обновления rewrite rules
        update_option('kash_back_flush_rewrite_rules', 1);

        // Прямое обновление rewrite rules при активации
        flush_rewrite_rules();

        // Регистрируем endpoint и обновляем правила перезаписи
        self::register_endpoints();
        flush_rewrite_rules();
    }

    /**
     * Метод деактивации плагина
     */
    public static function deactivate()
    {
        // Очистка крон-заданий плагина, если они есть
        wp_clear_scheduled_hook('kash_back_cleanup_sessions');

        // Сброс правил перезаписи при деактивации
        flush_rewrite_rules();

        // Удаляем флаг обновления правил перезаписи при деактивации
        delete_option('kash_back_flush_rewrite_rules');
    }

    /**
     * Конструктор класса
     */
    private function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Инициализация хуков
     */
    private function init_hooks()
    {
        // Инициализация при загрузке плагина
        add_action('plugins_loaded', array($this, 'init'));

        // Хуки для партнерских ссылок
        add_filter('woocommerce_product_add_to_cart_url', array($this, 'add_user_id_to_external_product_link'), 10, 2);
        add_filter('woocommerce_product_permalink', array($this, 'add_user_id_to_external_product_link'), 10, 2);

        // Хук для отслеживания переходов по внешним ссылкам
        add_action('template_redirect', array($this, 'track_affiliate_click'));

        // Хуки для добавления пункта меню в личный кабинет пользователя
        add_filter('woocommerce_account_menu_items', array($this, 'add_custom_menu_item'), 20);
        add_action('woocommerce_account_kash-back_endpoint', array($this, 'custom_endpoint_content'));

        // Регистрация endpoint'ов
        add_action('init', array('Kash_Back', 'register_endpoints'), 0);

        // Форсирование сброса правил перезаписи при активации плагина
        add_action('woocommerce_flush_rewrite_rules', array($this, 'flush_rewrite_rules'));

        // Обновление rewrite rules при инициализации
        add_action('wp', array($this, 'maybe_flush_rewrite_rules'));
    }

    /**
     * Проверка необходимости обновления rewrite rules
     */
    public function maybe_flush_rewrite_rules()
    {
        if (get_option('kash_back_flush_rewrite_rules') == 1) {
            flush_rewrite_rules();
            delete_option('kash_back_flush_rewrite_rules');
        }
    }

    /**
     * Регистрация endpoint'ов
     */
    public static function register_endpoints()
    {
        add_rewrite_endpoint('kash-back', EP_ROOT | EP_PAGES);
    }

    /**
     * Сброс правил перезаписи
     */
    public function flush_rewrite_rules()
    {
        flush_rewrite_rules();
    }

    /**
     * Добавление пункта меню "Мои покупки" в личный кабинет пользователя
     */
    public function add_custom_menu_item($menu_links)
    {
        $menu_links = array_slice($menu_links, 0, 1, true)
            + array('kash-back' => 'Мои покупки')
            + array_slice($menu_links, 1, null, true);

        return $menu_links;
    }

    /**
     * Содержимое страницы "Мои покупки"
     */
    public function custom_endpoint_content()
    {
        echo '<h3>Мои покупки</h3>';

        $user_id = get_current_user_id();
        if ($user_id > 0) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'affiliate_tracking';

            // Проверим, существует ли таблица
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name;
            error_log('Kash Back: Таблица существует: ' . ($table_exists ? 'да' : 'нет'));

            if (!$table_exists) {
                echo '<p>Таблица отслеживания не найдена. Пожалуйста, убедитесь, что плагин активирован.</p>';
                return;
            }

            // Проверим, есть ли записи в таблице вообще
            $total_records = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            error_log('Kash Back: Всего записей в таблице: ' . $total_records);

            // Проверим, есть ли записи для текущего пользователя
            $user_records = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d", $user_id));
            error_log('Kash Back: Записей для пользователя ' . $user_id . ': ' . $user_records);

            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE user_id = %d ORDER BY date_created DESC, time_created DESC LIMIT 50",
                $user_id
            ));

            error_log('Kash Back: Результат запроса: ' . ($results ? count($results) : 'null'));

            if ($results && !empty($results)) {
                echo '<div class="kash-back-purchases-table">';
                echo '<table class="shop_table shop_table_responsive my_account_orders">';
                echo '<thead>';
                echo '<tr>';
                echo '<th>Дата</th>';
                echo '<th>Время</th>';
                echo '<th>Партнерская ссылка</th>';
                echo '<th>Ссылка на товар</th>';
                echo '<th>Статус</th>';
                echo '<th>Сумма</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';

                foreach ($results as $row) {
                    error_log('Kash Back: Обработка строки - ID: ' . $row->id . ', user_id: ' . $row->user_id . ', external_url: ' . $row->external_url);
                    echo '<tr>';
                    echo '<td data-title="Дата">' . esc_html($row->date_created) . '</td>';
                    echo '<td data-title="Время">' . esc_html($row->time_created) . '</td>';
                    echo '<td data-title="Партнерская ссылка"><a href="' . esc_url($row->external_url) . '" target="_blank">Перейти</a></td>';
                    // Получаем ID товара из внутреннего URL и отображаем название товара вместо "Перейти"
                    $product_id = $row->product_id;
                    $product_name = '';
                    if ($product_id) {
                        $product = wc_get_product($product_id);
                        if ($product) {
                            $product_name = $product->get_name();
                        }
                    }
                    if (!empty($product_name)) {
                        echo '<td data-title="Ссылка на товар"><a href="' . esc_url($row->internal_url) . '" target="_blank">' . esc_html($product_name) . '</a></td>';
                    } else {
                        echo '<td data-title="Ссылка на товар"><a href="' . esc_url($row->internal_url) . '" target="_blank">Перейти</a></td>';
                    }
                    echo '<td data-title="Статус">' . esc_html($row->status) . '</td>';
                    echo '<td data-title="Сумма">' . esc_html($row->commission_amount) . '</td>';
                    echo '</tr>';
                }

                echo '</tbody>';
                echo '</table>';
                echo '</div>';
            } else {
                echo '<p>У вас пока нет покупок.</p>';

                // Добавим отладочную информацию
                error_log('Kash Back: Нет записей для пользователя ID ' . $user_id);
                error_log('Kash Back: Запрос: SELECT * FROM ' . $table_name . ' WHERE user_id = ' . $user_id . ' ORDER BY date_created DESC, time_created DESC');
            }
        } else {
            echo '<p>Войдите в систему, чтобы увидеть свои покупки.</p>';
        }
    }

    /**
     * Инициализация плагина
     */
    public function init()
    {
        // Загрузка файла локализации
        load_plugin_textdomain('kash-back', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Добавление ID пользователя к внешней партнерской ссылке
     */
    public function add_user_id_to_external_product_link($url, $product)
    {
        // Проверяем, что это внешний партнерский товар
        if ($product && $product->is_type('external')) {
            // Получаем текущего пользователя
            $current_user_id = get_current_user_id();

            // Если пользователь авторизован, добавляем его ID к внешней ссылке
            if ($current_user_id > 0) {
                $url = $this->add_parameter_to_url($url, 'user_id', $current_user_id);
            }

            // Получаем прямую ссылку на страницу товара (внутреннюю ссылку)
            $product_url = $product ? get_permalink($product->get_id()) : $this->get_current_url();

            // Формируем URL для отслеживания, который будет перенаправлять на внешнюю ссылку
            $tracking_url = home_url('/');
            $tracking_url = $this->add_parameter_to_url($tracking_url, 'redirect', $url);
            $tracking_url = $this->add_parameter_to_url($tracking_url, 'product_id', $product->get_id());
            $tracking_url = $this->add_parameter_to_url($tracking_url, 'internal_url', $product_url);

            // Всегда используем URL отслеживания, чтобы обеспечить запись в базу данных
            return $tracking_url;
        }

        return $url;
    }

    /**
     * Добавление параметра к URL с проверкой безопасности
     */
    private function add_parameter_to_url($url, $key, $value)
    {
        // Проверяем, является ли URL действительным
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        // Проверяем, что URL содержит необходимые компоненты
        $parsed_url = wp_parse_url($url);

        if (!$parsed_url || !isset($parsed_url['scheme']) || !isset($parsed_url['host'])) {
            return $url;
        }

        $query = isset($parsed_url['query']) ? $parsed_url['query'] : '';

        // Парсим существующие параметры
        parse_str($query, $params);

        // Добавляем новый параметр с безопасным значением
        $params[sanitize_key($key)] = sanitize_text_field($value);

        // Формируем новую строку параметров
        $new_query = http_build_query($params);

        // Собираем URL заново
        $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

        $new_url = $scheme . $host . $port . $path;

        if (!empty($new_query)) {
            $new_url .= '?' . $new_query;
        }

        $new_url .= $fragment;

        return esc_url_raw($new_url); // Экранируем URL для безопасности
    }

    /**
     * Получение текущего URL страницы
     */
    private function get_current_url()
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = sanitize_text_field($_SERVER['HTTP_HOST'] ?? '');
        $uri = sanitize_text_field($_SERVER['REQUEST_URI'] ?? '');
        $current_url = $protocol . '://' . $host . $uri;
        return esc_url_raw($current_url);
    }

    /**
     * Отслеживание кликов по партнерским ссылкам
     */
    public function track_affiliate_click()
    {
        // Проверяем, является ли текущий запрос переходом по внешней ссылке
        $redirect_url = isset($_GET['redirect']) ? esc_url_raw($_GET['redirect']) : '';
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

        // Проверяем, есть ли внешний URL для редиректа
        if (!empty($redirect_url) && filter_var($redirect_url, FILTER_VALIDATE_URL)) {
            // Если в URL нет user_id, но пользователь авторизован, получаем его ID
            if ($user_id <= 0) {
                $current_user_id = get_current_user_id();
                if ($current_user_id > 0) {
                    $user_id = $current_user_id;
                }
            }

            // Получаем ID продукта, если он есть в URL
            $product_id = 0;
            if (isset($_GET['product_id'])) {
                $product_id = intval($_GET['product_id']);
            } elseif (isset($_GET['add-to-cart'])) {
                $product_id = intval($_GET['add-to-cart']);
            }

            // Получаем внутренний URL (страница, с которой был совершен переход)
            $internal_url = isset($_GET['internal_url']) ? esc_url_raw($_GET['internal_url']) : '';
            if (empty($internal_url)) {
                $internal_url = wp_get_referer();
                if (!$internal_url) {
                    $internal_url = $_SERVER['HTTP_REFERER'] ?? '';
                }
            }

            // Получаем IP-адрес пользователя
            $ip_address = $this->get_user_ip_address();

            // Получаем User Agent
            $user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');

            // Генерируем ID сессии для анонимных пользователей
            $session_id = $this->generate_session_id();

            // Записываем данные в таблицу отслеживания
            $this->log_affiliate_click([
                'user_id' => $user_id > 0 ? $user_id : null,
                'session_id' => $session_id,
                'external_url' => $redirect_url,
                'internal_url' => $internal_url,
                'product_id' => $product_id > 0 ? $product_id : null,
                'user_agent' => $user_agent,
                'ip_address' => $ip_address
            ]);

            // Редиректим пользователя по внешней ссылке
            wp_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Получение IP-адреса пользователя
     */
    private function get_user_ip_address()
    {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];

        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (array_map('trim', explode(',', $_SERVER[$key])) as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return sanitize_text_field($ip);
                    }
                }
            }
        }

        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /**
     * Генерация ID сессии для анонимных пользователей
     */
    private function generate_session_id()
    {
        if (session_id()) {
            return session_id();
        }

        $session_id = uniqid('kash_back_session_', true);
        return $session_id;
    }

    /**
     * Запись данных о переходе в таблицу отслеживания
     */
    private function log_affiliate_click($data)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'affiliate_tracking';

        // Подготовка данных для вставки
        $insert_data = array(
            'user_id' => $data['user_id'],
            'session_id' => $data['session_id'],
            'date_created' => current_time('Y-m-d'),
            'time_created' => current_time('H:i:s'),
            'external_url' => $data['external_url'],
            'internal_url' => $data['internal_url'],
            'product_id' => $data['product_id'],
            'user_agent' => $data['user_agent'],
            'ip_address' => $data['ip_address']
        );

        $insert_format = array(
            '%d', // user_id
            '%s', // session_id
            '%s', // date_created
            '%s', // time_created
            '%s', // external_url
            '%s', // internal_url
            '%d', // product_id
            '%s', // user_agent
            '%s'  // ip_address
        );

        // Вставка записи в таблицу
        $result = $wpdb->insert($table_name, $insert_data, $insert_format);
    }
}

/**
 * Инициализация плагина
 */
function kash_back_init()
{
    return Kash_Back::get_instance();
}

// Регистрация функции активации
register_activation_hook(__FILE__, array('Kash_Back', 'activate'));

// Регистрация функции деактивации
register_deactivation_hook(__FILE__, array('Kash_Back', 'deactivate'));

// Запуск плагина
kash_back_init();


/**
 * Добавление CSS стилей для таблицы покупок
 */
function kash_back_add_styles()
{
    // Проверяем, находимся ли мы на странице "Мои покупки"
    global $wp;
    if (isset($wp->query_vars['kash-back'])) {
        wp_enqueue_style('kash-back-styles', plugin_dir_url(__FILE__) . 'assets/css/kash-back-styles.css', array(), '1.0');
    }
}
add_action('wp_enqueue_scripts', 'kash_back_add_styles');
