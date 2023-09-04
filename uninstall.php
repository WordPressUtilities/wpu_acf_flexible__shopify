<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

/* Delete options */
$options = array(
    'wpu_acfflexshopify__shop_url',
    'wpu_acfflexshopify__shop_url_myshopify',
    'wpu_acfflexshopify__api_key',
    'wpu_acfflexshopify__api_password'
);
foreach ($options as $opt) {
    delete_option($opt);
    delete_site_option($opt);
}
