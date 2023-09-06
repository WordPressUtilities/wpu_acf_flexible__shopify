<?php

/*
Plugin Name: WPU ACF Flexible Shopify
Plugin URI: https://github.com/WordPressUtilities/wpu_acf_flexible__shopify
Update URI: https://github.com/WordPressUtilities/wpu_acf_flexible__shopify
Description: Helper for WPU ACF Flexible with Shopify
Version: 0.10.0
Author: Darklg
Author URI: https://darklg.me/
Text Domain: wpu_acf_flexible__shopify
Requires at least: 6.2
Requires PHP: 8.0
License: MIT License
License URI: https://opensource.org/licenses/MIT
*/

class wpu_acf_flexible__shopify {
    private $api_version = '2023-07';
    private $shop_url_my = '';
    private $api_time_limit_usec = 500000;
    private $purge_cache_user_level = 'upload_files';
    private $wpubasefilecache;

    public function __construct() {
        $this->shop_url_my = get_option('wpu_acfflexshopify__shop_url_myshopify');

        /* Options */
        add_filter('wpu_options_tabs', array(&$this, 'options_tabs'), 10, 3);
        add_filter('wpu_options_boxes', array(&$this, 'options_boxes'), 10, 3);
        add_filter('wpu_options_fields', array(&$this, 'options_fields'), 10, 3);

        /* Menu item */
        add_action('admin_bar_menu', array(&$this, 'admin_bar_menu'), 999);

        /* Purge & redirect */
        add_action('admin_head', array(&$this, 'admin_head'));

        /* Cache */
        include dirname( __FILE__ ) . '/inc/WPUBaseFileCache/WPUBaseFileCache.php';
        $this->wpubasefilecache = new \wpu_acf_flexible__shopify\WPUBaseFileCache('wpu_acf_flexible__shopify');
    }

    public function get_api_url() {
        $api_key = get_option('wpu_acfflexshopify__api_key');
        $api_password = get_option('wpu_acfflexshopify__api_password');
        $shop_url_details = parse_url($this->shop_url_my);
        if (!$api_key || !isset($shop_url_details['host'])) {
            return false;
        }
        return 'https://' . $api_key . ':' . $api_password . '@' . $shop_url_details['host'];
    }

    public function get_collection_products($collection_id) {
        return $this->get_items_list('collections/' . $collection_id . '/products', array(
            'key_name' => 'products'
        ));
    }

    public function get_custom_collections_list() {
        return $this->get_items_list('custom_collections');
    }

    public function get_smart_collections_list() {
        return $this->get_items_list('smart_collections');
    }

    public function get_product_list() {
        return $this->get_items_list('products');
    }

    public function get_items_list($json_name, $args = array()) {
        if (!is_array($args)) {
            $args = array();
        }
        if (!isset($args['key_name'])) {
            $args['key_name'] = $json_name;
        }
        if (!isset($args['page_limit'])) {
            $args['page_limit'] = apply_filters('wpu_acf_flexible__shopify__page_limit', 250);
        }

        $fields = array(
            'id',
            'status',
            'title'
        );
        if ($json_name == 'products') {
            $fields[] = 'variants';
        }

        $endpoint_url = $this->get_api_url() . '/admin/api/' . $this->api_version . '/' . $json_name . '.json?fields=' . implode(',', $fields) . '&limit=' . $args['page_limit'];
        $cache_key = 'wpu_acf_flexible__shopify__' . sanitize_title($json_name) . '_list';
        if (false === ($item_list = $this->wpubasefilecache->get_cache($cache_key, 24 * 60 * 60))) {
            $item_list = $this->get_paged_query($endpoint_url, array(), $args['key_name']);
            $this->wpubasefilecache->set_cache($cache_key, $item_list);
        }
        return $item_list;
    }

    public function get_paged_query($endpoint_url, $item_list = array(), $key = 'products') {
        $exclude_drafts = apply_filters('wpu_acf_flexible__shopify__exclude_drafts', true);
        $endpoint_url = str_replace($this->shop_url_my, $this->get_api_url(), $endpoint_url);
        $response = wp_remote_get($endpoint_url);
        usleep($this->api_time_limit_usec);
        if (is_array($response) && !is_wp_error($response)) {
            $list_tmp = json_decode($response['body'], true);
            if (isset($list_tmp[$key]) && is_array($list_tmp[$key])) {
                foreach ($list_tmp[$key] as $item) {
                    $item_title = $item['title'];
                    if (isset($item['variants'], $item['variants'][0], $item['variants'][0]['sku']) && !empty($item['variants'][0]['sku'])) {
                        $item_title .= ' - ' . $item['variants'][0]['sku'];
                    }
                    if (isset($item['status']) && $item['status'] == 'draft' && $exclude_drafts) {
                        continue;
                    }
                    $item_list[$item['id']] = $item_title;
                }
            }
            $link = wp_remote_retrieve_header($response, 'link');
            $next_url = $this->extract_next_url($link);
            if ($next_url) {
                $item_list = $this->get_paged_query($next_url, $item_list, $key);
            }
        }
        return $item_list;
    }

    public function extract_next_url($link) {
        preg_match('/<([^>]*)>; rel="next"/isU', $link, $next_url);
        if (isset($next_url[1])) {
            return $next_url[1];
        }
        return false;
    }

    /* ----------------------------------------------------------
      Product
    ---------------------------------------------------------- */

    public function get_product($product_id = false) {
        $shop_url = get_option('wpu_acfflexshopify__shop_url');
        $endpoint_url = $this->get_api_url() . '/admin/api/' . $this->api_version . '/products/' . $product_id . '.json';
        if (!$endpoint_url || !$shop_url) {
            return false;
        }

        $p = $this->get_endpoint_value($endpoint_url, 'wpshopify_product_' . $product_id, 12 * 60 * 60);

        $p->product_url = $shop_url . '/products/' . $p->product->handle;

        return $p;
    }

    public function get_product_html($product_id = false) {
        $p = $this->get_product($product_id);
        $product_name = $p->product->title;
        $html = '';
        $html .= '<div class="product-html">';
        $html .= '<h2 class="product-name"><a href="' . $p->product_url . '">' . $product_name . '</a></h2>';
        $html .= '<p class="product-vendor">' . $p->product->vendor . '</p>';
        $html .= '<p class="product-price"><strong>' . $p->product->variants[0]->price . '&euro;</strong></p>';
        $html .= '<p class="product-image"><a href="' . $p->product_url . '"><img src="' . $p->product->image->src . '" alt="' . esc_attr($product_name) . '" /></a></p>';
        $html .= '<p class="product-cta"><a href="' . $p->product_url . '">' . __('View product') . '</a></p>';
        $html .= '</div>';
        return $html;
    }

    /* ----------------------------------------------------------
      Get endpoint value
    ---------------------------------------------------------- */

    public function get_endpoint_value($endpoint_url, $item_option_id, $cache_duration) {
        $p = false;

        /* Try to obtain cached value */
        $item = $this->wpubasefilecache->get_cache($item_option_id, $cache_duration);

        /* Outdated transient : get new cache */
        if (!$item) {
            $response = wp_remote_get($endpoint_url);
            usleep($this->api_time_limit_usec);
            /* If response is valid, find if it’s a correct item */
            if (is_array($response) && !is_wp_error($response)) {
                $p = json_decode($response['body']);

                /* Correct item : update cache */
                if (is_object($p)) {
                    $item = $response['body'];
                    $this->wpubasefilecache->set_cache($item_option_id, $item);
                }
            }
        }

        if (!is_object($p)) {
            $p = json_decode($item);
        }

        if (!is_object($p)) {
            return false;
        }

        return $p;
    }

    /* ----------------------------------------------------------
      Cache
    ---------------------------------------------------------- */

    public function admin_bar_menu($wp_adminbar) {
        if (!current_user_can($this->purge_cache_user_level)) {
            return;
        }
        $wp_adminbar->add_node(array(
            'id' => 'wpu_acfflexshopify',
            'title' => 'Shopify',
            'href' => admin_url('admin.php?page=wpuoptions-settings&tab=wpu_acf_flexible__shopify__tab')
        ));
        $wp_adminbar->add_node(array(
            'id' => 'wpu_acfflexshopify__purge',
            'title' => __('Purge Cache', 'wpu_acfflexshopify'),
            'parent' => 'wpu_acfflexshopify',
            'href' => admin_url('?purge_acf_shopify=1')
        ));
    }

    public function admin_head() {
        if (!current_user_can($this->purge_cache_user_level)) {
            return;
        }
        if (!isset($_GET['purge_acf_shopify']) || $_GET['purge_acf_shopify'] != '1') {
            return;
        }

        $this->wpubasefilecache->purge_cache_dir();
        echo '<script>window.location.href="' . admin_url() . '";</script>';
    }

    /* ----------------------------------------------------------
      Options
    ---------------------------------------------------------- */

    public function options_tabs($tabs) {
        $tabs['wpu_acf_flexible__shopify__tab'] = array(
            'name' => 'ACF / Shopify',
            'sidebar' => true
        );
        return $tabs;
    }

    public function options_boxes($boxes) {
        $boxes['wpu_acf_flexible__shopify__box'] = array(
            'name' => 'Access',
            'tab' => 'wpu_acf_flexible__shopify__tab'
        );
        return $boxes;
    }

    public function options_fields($options) {
        $options['wpu_acfflexshopify__api_key'] = array(
            'label' => __('API Key', 'wpu_acfflexshopify'),
            'box' => 'wpu_acf_flexible__shopify__box'
        );
        $options['wpu_acfflexshopify__api_password'] = array(
            'label' => __('API password', 'wpu_acfflexshopify'),
            'box' => 'wpu_acf_flexible__shopify__box'
        );
        $options['wpu_acfflexshopify__shop_url'] = array(
            'label' => __('Shop URL', 'wpu_acfflexshopify'),
            'box' => 'wpu_acf_flexible__shopify__box',
            'type' => 'url',
            'help' => 'https://YOURSHOP.com'
        );
        $options['wpu_acfflexshopify__shop_url_myshopify'] = array(
            'label' => __('Shop URL MyShopify', 'wpu_acfflexshopify'),
            'box' => 'wpu_acf_flexible__shopify__box',
            'type' => 'url',
            'help' => 'https://YOURSHOPNAME.myshopify.com'
        );
        return $options;
    }

}

$wpu_acf_flexible__shopify = new wpu_acf_flexible__shopify();

/* ----------------------------------------------------------
  WP CLI
---------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('wpu-acf-flex-shopify-purge-cache', function ($args) {
        wpu_acf_flexible__shopify__purge_cache();
        WP_CLI::success('WPU ACF Flex Shopify: Cache purged');
    });

    WP_CLI::add_command('wpu-acf-flex-shopify-cache-warm', function ($args) {
        $wpu_acf_flexible__shopify = new wpu_acf_flexible__shopify();
        $wpu_acf_flexible__shopify->get_custom_collections_list();
        WP_CLI::success('WPU ACF Flex Shopify: Cache Warmed for Custom collections');
        $wpu_acf_flexible__shopify->get_smart_collections_list();
        WP_CLI::success('WPU ACF Flex Shopify: Cache Warmed for Smart collections');
        $wpu_acf_flexible__shopify->get_product_list();
        WP_CLI::success('WPU ACF Flex Shopify: Cache Warmed for Product list');
    });
}

/* ----------------------------------------------------------
  Purge Cache Action
---------------------------------------------------------- */

function wpu_acf_flexible__shopify__purge_cache() {
    /* Purge cache dir */
    $wpu_acf_flexible__shopify = new wpu_acf_flexible__shopify();
    $wpu_acf_flexible__shopify->wpubasefilecache->purge_cache_dir();

    /* Delete old transients */
    global $wpdb;
    $transient_products = $wpdb->get_col("SELECT option_name FROM $wpdb->options WHERE option_name LIKE '_transient_wpshopify_product_%' OR option_name LIKE '_transient_wpu_acf_flexible__shopify%'");
    foreach ($transient_products as $transient_name) {
        delete_transient(str_replace('_transient_', '', $transient_name));
    }
}
