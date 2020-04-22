<?php

/*
Plugin Name: WPU ACF Flexible Shopify
Plugin URI: https://github.com/WordPressUtilities/wpu_acf_flexible__shopify
Description: Helper for WPU ACF Flexible with Shopify
Version: 0.1.0
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

    public function get_product_list() {
        $endpoint_url = $this->get_api_url() . '/admin/api/' . $this->api_version . '/products.json?fields=id,title&limit=250';

        if (false === ($product_list = get_transient('wpu_acf_flexible__shopify__product_list'))) {
            $product_list = $this->get_paged_query($endpoint_url, array());
            set_transient('wpu_acf_flexible__shopify__product_list', $product_list, 24 * 60 * 60);
        }

        return $product_list;
    }

    public function get_paged_query($endpoint_url, $product_list = array(), $add_user = false) {
        $endpoint_url = str_replace($this->shop_url_my, $this->get_api_url(), $endpoint_url);
        $response = wp_remote_get($endpoint_url);
        if (is_array($response) && !is_wp_error($response)) {
            $product_list_tmp = json_decode($response['body']);
            if (is_array($product_list_tmp->products)) {
                foreach ($product_list_tmp->products as $product) {
                    $product_list[$product->id] = $product->title;
                }
                $link = wp_remote_retrieve_header($response, 'link');
                $next_url = $this->extract_next_url($link);
                if ($next_url) {
                    $product_list = $this->get_paged_query($next_url, $product_list, 1);
                }
            }
        }
        return $product_list;
    }

    public function extract_next_url($link) {
        preg_match('/<([^>]*)>; rel="next"/isU', $link, $next_url);
        if (isset($next_url[1])) {
            return $next_url[1];
        }
        return false;
    }

    public function get_product($product_id = false) {
        $shop_url = get_option('wpu_acfflexshopify__shop_url');
        $endpoint_url = $this->get_api_url() . '/admin/api/' . $this->api_version . '/products/' . $product_id . '.json';
        if (!$endpoint_url || !$shop_url) {
            return false;
        }

        $product_key = 'wpshopify_' . $product_id . '_' . md5($endpoint_url);

        if (false === ($product = get_transient($product_key))) {
            $response = wp_remote_get($endpoint_url);
            if (is_array($response) && !is_wp_error($response)) {
                $product = $response['body'];
                set_transient($product_key, $product, 12 * 60 * 60);
            }
        }

        $p = json_decode($product);

        if (!is_object($p)) {
            return false;
        }
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
        $html .= '</div>';
        return $html;
    }

}

$wpu_acf_flexible__shopify = new wpu_acf_flexible__shopify();
