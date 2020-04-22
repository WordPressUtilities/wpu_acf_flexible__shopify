# WPU ACF Flexible Shopify

Helper for WPU ACF Flexible with Shopify.
Advanced Custom Field Pro is required, with wpu_acf_flexible.


## Add the layout model if the plugin is configured

```php
$wpu_acfflexshopify__api_key = get_option('wpu_acfflexshopify__api_key');
if ($wpu_acfflexshopify__api_key) {
    global $wpu_acf_flexible__shopify;
    $product_list = $wpu_acf_flexible__shopify->get_product_list();
    $layouts['posts-product'] = array(
        'label' => __('Product'),
        'sub_fields' => array(
            'product_id' => array(
                'label' => __('Product ID'),
                'type' => 'select',
                'choices' => $product_list
            )
        )
    );
}
```

## Set the layout content

```php
<?php
global $wpu_acf_flexible__shopify;
$product_id = get_sub_field('product_id');
if (!is_numeric($product_id)) {
    return false;
}
$product_html = $wpu_acf_flexible__shopify->get_product_html($product_id);
?>
<div class="centered-container cc-block--posts-product">
    <div class="block--posts-product">
        <div class="field-product_id"><?php echo $product_html; ?></div>
    </div>
</div>
```
