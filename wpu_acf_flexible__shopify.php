<?php

/*
Plugin Name: WPU ACF Flexible Shopify
Plugin URI: https://github.com/WordPressUtilities/wpu_acf_flexible__shopify
Description: Helper for WPU ACF Flexible with Shopify
Version: 0.5.0
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

/* ----------------------------------------------------------
  Options
---------------------------------------------------------- */

add_filter('wpu_options_tabs', 'wpu_acf_flexible__shopify_options_tabs', 10, 3);
function wpu_acf_flexible__shopify_options_tabs($tabs) {
    $tabs['wpu_acf_flexible__shopify__tab'] = array(
        'name' => 'ACF / Shopify',
        'sidebar' => true
    );
    return $tabs;
}

add_filter('wpu_options_boxes', 'wpu_acf_flexible__shopify_options_boxes', 10, 3);
function wpu_acf_flexible__shopify_options_boxes($boxes) {
    $boxes['wpu_acf_flexible__shopify__box'] = array(
        'name' => 'Access',
        'tab' => 'wpu_acf_flexible__shopify__tab'
    );
    return $boxes;
}

add_filter('wpu_options_fields', 'wpu_acf_flexible__shopify_options_fields', 10, 3);
function wpu_acf_flexible__shopify_options_fields($options) {
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

/* ----------------------------------------------------------
  Purge Cache
---------------------------------------------------------- */

/* Menu item */
add_action('admin_bar_menu', function ($wp_adminbar) {
    if (!current_user_can('upload_files')) {
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
}, 999);

/* Purge & redirect */
add_action('admin_head', function () {
    if (!current_user_can('upload_files')) {
        return;
    }
    if (!isset($_GET['purge_acf_shopify']) || $_GET['purge_acf_shopify'] != '1') {
        return;
    }

    global $wpdb;
    $transient_products = $wpdb->get_col("SELECT option_name FROM $wpdb->options WHERE option_name LIKE '_transient_wpshopify_product_%' OR option_name LIKE '_transient_wpu_acf_flexible__shopify__' ");
    foreach ($transient_products as $transient_name) {
        delete_transient(str_replace('_transient_', '', $transient_name));
    }
    echo '<script>window.location.href="' . admin_url() . '";</script>';
});

/* ----------------------------------------------------------
  Helpers
---------------------------------------------------------- */

class wpu_acf_flexible__shopify {
    private $api_version = '2020-04';
    private $shop_url_my = '';

    public function __construct() {
        $this->shop_url_my = get_option('wpu_acfflexshopify__shop_url_myshopify');
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

        $endpoint_url = $this->get_api_url() . '/admin/api/' . $this->api_version . '/' . $json_name . '.json?fields=id,title&limit=250';
        $cache_key = 'wpu_acf_flexible__shopify__' . sanitize_title($json_name) . '_list';
        if (false === ($item_list = get_transient($cache_key))) {
            $item_list = $this->get_paged_query($endpoint_url, array(), $args['key_name']);
            set_transient($cache_key, $item_list, 24 * 60 * 60);
        }
        return $item_list;
    }

    public function get_paged_query($endpoint_url, $item_list = array(), $key = 'products') {
        $endpoint_url = str_replace($this->shop_url_my, $this->get_api_url(), $endpoint_url);
        $response = wp_remote_get($endpoint_url);
        if (is_array($response) && !is_wp_error($response)) {
            $list_tmp = json_decode($response['body'], true);
            if (isset($list_tmp[$key]) && is_array($list_tmp[$key])) {
                foreach ($list_tmp[$key] as $product) {
                    $item_list[$product['id']] = $product['title'];
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

        /* Set cache keys */
        $item_transient_id = $item_option_id . '_' . md5($endpoint_url);

        /* Try to obtain cached value */
        $item = get_option($item_option_id);

        /* Outdated transient : get new cache */
        if (get_transient($item_transient_id) != '1') {
            $response = wp_remote_get($endpoint_url);
            /* If response is valid, find if it’s a correct item */
            if (is_array($response) && !is_wp_error($response)) {
                $p = json_decode($response['body']);

                /* Correct item : update cache */
                if (is_object($p)) {
                    $item = $response['body'];
                    update_option($item_option_id, $item);
                }
                set_transient($item_transient_id, '1', $cache_duration);
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

}

$wpu_acf_flexible__shopify = new wpu_acf_flexible__shopify();
