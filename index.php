<?php
/*
Plugin Name: Box Product for WooCommerce
Description: Create customizable box products with multiple categories
Version: 4.2
*/

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', 'init_box_product_plugin');

function init_box_product_plugin() {
    if (!class_exists('WooCommerce')) {
        return;
    }

    class WC_Product_Box extends WC_Product {
        public function __construct($product) {
            $this->product_type = 'box_product';
            parent::__construct($product);
        }

        public function get_type() {
            return 'box_product';
        }

        public function add_to_cart_url() {
            return apply_filters('woocommerce_product_add_to_cart_url', get_permalink($this->get_id()), $this);
        }

        public function add_to_cart_text() {
            return apply_filters('woocommerce_product_add_to_cart_text', 'Select Options', $this);
        }

        public function is_sold_individually() {
            return true;
        }

        public function is_purchasable() {
            return true;
        }

        public function is_in_stock() {
            return true;
        }
    }

    class WC_Box_Product {

        public function __construct() {
            add_filter('product_type_selector', array($this, 'add_box_product_type'));
            add_filter('woocommerce_product_data_tabs', array($this, 'add_product_data_tab'));
            add_action('woocommerce_product_data_panels', array($this, 'add_product_data_panel'));
            add_action('woocommerce_process_product_meta', array($this, 'save_box_product_data'));
            add_action('woocommerce_single_product_summary', array($this, 'hide_product_image_and_display_builder'), 1);
            add_action('woocommerce_single_product_summary', array($this, 'display_short_description_after_title'), 6);
            add_action('woocommerce_single_product_summary', array($this, 'hide_stock_for_box_product'), 3);
            add_action('woocommerce_before_add_to_cart_button', array($this, 'display_box_selector'));
            add_filter('woocommerce_add_to_cart_validation', array($this, 'remove_old_box_before_add'), 10, 3);
            add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 3);
            add_filter('woocommerce_get_item_data', array($this, 'display_cart_item_data'), 10, 2);
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
            add_action('woocommerce_box_product_add_to_cart', array($this, 'add_to_cart_template'));
            add_filter('woocommerce_product_class', array($this, 'product_class'), 10, 2);
            add_action('woocommerce_before_calculate_totals', array($this, 'set_box_product_price_in_cart'), 99, 1);
            add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_box_selections_to_order_item'), 10, 4);
            add_action('woocommerce_order_item_meta_end', array($this, 'display_box_contents_in_order'), 10, 4);
            add_action('woocommerce_admin_order_item_headers', array($this, 'add_order_item_header'));
            add_action('woocommerce_admin_order_item_values', array($this, 'display_box_contents_in_admin'), 10, 3);
            add_action('wp_ajax_search_products_for_box', array($this, 'ajax_search_products'));
            add_action('wp_footer', array($this, 'add_mobile_sticky_bar'));
        }

        public function display_short_description_after_title() {
            global $product;
            if ($product && $product->get_type() === 'box_product') {
                // Remove default short description
                remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20);
                
                $short_description = $product->get_short_description();
                if (!empty($short_description)) {
                    // Extract only the first line/paragraph
                    $description_lines = explode("\n", strip_tags($short_description));
                    $first_line = trim($description_lines[0]);
                    
                    ?>
                    <div class="box-product-short-description">
                        <p><?php echo esc_html($first_line); ?></p>
                    </div>
                    
                    <style>
                        .box-product-short-description {
                            margin: 15px 0 20px 0;
                            padding: 0;
                            font-size: 16px;
                            line-height: 1.5;
                            color: #666;
                            font-family: inherit;
                        }
                        
                        .box-product-short-description p {
                            margin: 0;
                            color: #666;
                            font-size: 16px;
                            line-height: 1.5;
                        }
                        
                        /* RTL Support */
                        html[lang="ar"] .box-product-short-description,
                        html[dir="rtl"] .box-product-short-description,
                        body.rtl .box-product-short-description {
                            direction: rtl;
                            text-align: right;
                        }
                        
                        /* Mobile Responsive */
                        @media (max-width: 768px) {
                            .box-product-short-description {
                                margin: 12px 0 15px 0;
                                font-size: 15px;
                            }
                            
                            .box-product-short-description p {
                                font-size: 15px;
                            }
                        }
                        
                        @media (max-width: 480px) {
                            .box-product-short-description {
                                margin: 10px 0 12px 0;
                                font-size: 14px;
                            }
                            
                            .box-product-short-description p {
                                font-size: 14px;
                            }
                        }
                    </style>
                    <?php
                }
            }
        }

        public function hide_stock_for_box_product() {
            global $product;
            if ($product && $product->get_type() === 'box_product') {
                ?>
                <style>
                    .product-type-box_product .stock,
                    .product-type-box_product .in-stock,
                    .product-type-box_product .out-of-stock {
                        display: none !important;
                    }
                </style>
                <?php
            }
        }

        public function ajax_search_products() {
            $search = isset($_REQUEST['search']) ? sanitize_text_field($_REQUEST['search']) : '';
            $page   = isset($_REQUEST['page']) ? intval($_REQUEST['page']) : 1;

            if (empty($search) || strlen($search) < 3) {
                wp_send_json(array('results' => array()));
                return;
            }

            $args = array(
                'status'   => 'publish',
                'limit'    => 30,
                'page'     => $page,
                'paginate' => true,
                's'        => $search,
            );

            $query_results = wc_get_products($args);
            $products      = $query_results->products;
            $max_pages     = $query_results->max_num_pages;

            $results = array();
            foreach ($products as $product) {
                $results[] = array(
                    'id'   => $product->get_id(),
                    'text' => $product->get_name() . ' (ID: ' . $product->get_id() . ')'
                );
            }

            wp_send_json(array(
                'results'    => $results,
                'pagination' => array(
                    'more' => $page < $max_pages
                )
            ));
        }

        public function remove_old_box_before_add($passed, $product_id, $quantity) {
            $product = wc_get_product($product_id);
            
            if ($product && $product->get_type() === 'box_product') {
                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                    if (isset($cart_item['product_id']) && 
                        $cart_item['product_id'] == $product_id && 
                        isset($cart_item['box_selections'])) {
                        WC()->cart->remove_cart_item($cart_item_key);
                        wc_add_notice('Previous box configuration has been replaced with the new one.', 'info');
                    }
                }
            }
            
            return $passed;
        }

        public function hide_product_image_and_display_builder() {
            global $product;
            if ($product && $product->get_type() === 'box_product') {
                ?>
                <style>
                    .woocommerce-product-gallery,
                    .product .images,
                    .single-product div.product .woocommerce-product-gallery {
                        display: none !important;
                    }
                    .single-product div.product {
                        display: block !important;
                    }
                    .single-product div.product .summary {
                        width: 100% !important;
                        float: none !important;
                        margin: 0 !important;
                        max-width: 100% !important;
                    }
                </style>
                <?php
            }
        }

        public function product_class($classname, $product_type) {
            if ($product_type === 'box_product') {
                return 'WC_Product_Box';
            }
            return $classname;
        }

        public function add_box_product_type($types) {
            $types['box_product'] = 'Box Product';
            return $types;
        }

        public function add_product_data_tab($tabs) {
            $tabs['box_product'] = array(
                'label' => 'Box Settings',
                'target' => 'box_product_data',
                'class' => array('show_if_box_product'),
                'priority' => 21
            );
            return $tabs;
        }

        public function add_product_data_panel() {
            global $post;
            ?>
            <div id="box_product_data" class="panel woocommerce_options_panel hidden">
                <div class="options_group">
                    <?php
                    woocommerce_wp_text_input(array(
                        'id' => '_box_size',
                        'label' => 'Box Size',
                        'description' => 'Number of items in the box',
                        'type' => 'number',
                        'custom_attributes' => array('min' => '1'),
                        'value' => get_post_meta($post->ID, '_box_size', true) ?: '6'
                    ));
                    ?>
                    
                    <h3 style="padding-left: 12px;">Discount Settings</h3>
                    <?php
                    woocommerce_wp_checkbox(array(
                        'id' => '_enable_box_discount',
                        'label' => 'Enable Discount',
                        'description' => 'Apply discount when certain number of items are selected'
                    ));
                    
                    woocommerce_wp_text_input(array(
                        'id' => '_discount_min_items',
                        'label' => 'Minimum Items for Discount',
                        'type' => 'number',
                        'custom_attributes' => array('min' => '1'),
                        'value' => get_post_meta($post->ID, '_discount_min_items', true) ?: '3'
                    ));
                    
                    woocommerce_wp_select(array(
                        'id' => '_discount_type',
                        'label' => 'Discount Type',
                        'options' => array(
                            'percentage' => 'Percentage',
                            'fixed' => 'Fixed Amount'
                        ),
                        'value' => get_post_meta($post->ID, '_discount_type', true) ?: 'percentage'
                    ));
                    
                    woocommerce_wp_text_input(array(
                        'id' => '_discount_value',
                        'label' => 'Discount Value',
                        'type' => 'number',
                        'custom_attributes' => array('min' => '0', 'step' => '0.01'),
                        'value' => get_post_meta($post->ID, '_discount_value', true) ?: '10'
                    ));
                    ?>
                    
                    <p class="form-field">
                        <label style="font-weight: bold; margin-bottom: 10px; display: block;">Box Categories</label>
                        <div id="box_categories_container">
                            <?php
                            $categories = get_post_meta($post->ID, '_box_categories', true);
                            if (!empty($categories) && is_array($categories)) {
                                foreach ($categories as $index => $category) {
                                    $this->render_category_row($index, $category);
                                }
                            }
                            ?>
                        </div>
                        <button type="button" class="button button-primary" id="add_box_category" style="margin-top: 15px;">+ Add Category</button>
                    </p>
                </div>
            </div>
            
            <style>
                #box_categories_container {
                    margin-top: 10px;
                }
                
                .box-category-row {
                    border: 1px solid #c3c4c7;
                    padding: 15px;
                    margin-bottom: 15px;
                    background: #fff;
                    border-radius: 4px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                }
                
                .box-category-row:hover {
                    border-color: #2271b1;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                }
                
                .box-category-row .row-content {
                    display: flex;
                    gap: 12px;
                    align-items: flex-start;
                    flex-wrap: nowrap;
                }
                
                .box-category-row .category-name-input {
                    flex: 0 0 200px;
                    min-width: 200px;
                }
                
                .box-category-row .category-name-input input {
                    width: 100%;
                    padding: 8px 12px;
                    border: 1px solid #8c8f94;
                    border-radius: 4px;
                    font-size: 14px;
                }
                
                .box-category-row .products-select-wrapper {
                    flex: 1;
                    min-width: 0;
                }
                
                .box-category-row .select2-container {
                    width: 100% !important;
                }
                
                .box-category-row .select2-container .select2-selection--multiple {
                    min-height: 36px;
                    border: 1px solid #8c8f94;
                    border-radius: 4px;
                }
                
                .box-category-row .select2-container--default .select2-selection--multiple .select2-selection__rendered {
                    padding: 2px 8px;
                }
                
                .box-category-row .remove-category {
                    flex: 0 0 auto;
                    background: #dc3232;
                    color: #fff;
                    border: none;
                    padding: 8px 16px;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 14px;
                    transition: background 0.2s;
                    height: 36px;
                    white-space: nowrap;
                }
                
                .box-category-row .remove-category:hover {
                    background: #c92c2c;
                }
                
                #add_box_category {
                    background: #0073aa;
                    border-color: #0073aa;
                    color: #fff;
                    padding: 8px 20px;
                    font-size: 14px;
                    transition: all 0.2s;
                }
                
                #add_box_category:hover {
                    background: #005a87;
                    border-color: #005a87;
                }
                
                .select2-container--default .select2-selection--multiple .select2-selection__choice {
                    background-color: #2271b1;
                    border: 1px solid #2271b1;
                    color: #fff;
                    padding: 2px 8px;
                    margin: 3px 3px 3px 0;
                }
                
                .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
                    color: #fff;
                    margin-right: 5px;
                }
                
                .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
                    color: #ff6b6b;
                }
                
                @media (max-width: 1200px) {
                    .box-category-row .row-content {
                        flex-wrap: wrap;
                    }
                    
                    .box-category-row .category-name-input {
                        flex: 0 0 calc(50% - 6px);
                    }
                    
                    .box-category-row .products-select-wrapper {
                        flex: 0 0 100%;
                        margin-top: 10px;
                    }
                    
                    .box-category-row .remove-category {
                        flex: 0 0 calc(50% - 6px);
                    }
                }
            </style>
            
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var categoryIndex = <?php echo !empty($categories) && is_array($categories) ? count($categories) : 0; ?>;
                    
                function initSelect2(element) {
                        $(element).select2({
                            ajax: {
                                url: ajaxurl,
                                type: 'POST',
                                dataType: 'json',
                                delay: 600,
                                data: function (params) {
                                    return {
                                        action: 'search_products_for_box',
                                        search: params.term,
                                        page: params.page || 1
                                    };
                                },
                                processResults: function (data, params) {
                                    params.page = params.page || 1;
                                    return {
                                        results: data.results,
                                        pagination: {
                                            more: data.pagination ? data.pagination.more : false
                                        }
                                    };
                                },
                                cache: false
                            },
                            minimumInputLength: 3,
                            placeholder: 'Search product name...',
                            allowClear: true,
                            width: '100%',
                            language: {
                                inputTooShort: function(args) {
                                    return "Please type 3 chars to search";
                                },
                                noResults: function() {
                                    return "No products found";
                                },
                                searching: function() {
                                    return "Searching...";
                                }
                            }
                        });
                    }
                    
                    $('.product-select').each(function() {
                        initSelect2(this);
                    });
                    
                    $('#add_box_category').click(function() {
                        var html = `
                            <div class="box-category-row">
                                <div class="row-content">
                                    <div class="category-name-input">
                                        <input type="text" name="box_categories[${categoryIndex}][name]" placeholder="Category Name" required />
                                    </div>
                                    <div class="products-select-wrapper">
                                        <select name="box_categories[${categoryIndex}][products][]" multiple class="product-select">
                                        </select>
                                    </div>
                                    <button type="button" class="remove-category">Remove</button>
                                </div>
                            </div>
                        `;
                        $('#box_categories_container').append(html);
                        initSelect2($('#box_categories_container').find('.product-select').last());
                        categoryIndex++;
                    });
                    
                    $(document).on('click', '.remove-category', function() {
                        if (confirm('Are you sure you want to remove this category?')) {
                            $(this).closest('.box-category-row').fadeOut(300, function() {
                                $(this).remove();
                            });
                        }
                    });
                    
                    $('.product_data_tabs .general_tab').addClass('show_if_box_product');
                    $('#general_product_data .pricing').addClass('show_if_box_product');
                });
            </script>
            <?php
        }
        
        private function render_category_row($index, $category = array()) {
            $name = isset($category['name']) ? $category['name'] : '';
            $selected_products = isset($category['products']) ? $category['products'] : array();
            ?>
            <div class="box-category-row">
                <div class="row-content">
                    <div class="category-name-input">
                        <input type="text" name="box_categories[<?php echo $index; ?>][name]" value="<?php echo esc_attr($name); ?>" placeholder="Category Name" required />
                    </div>
                    <div class="products-select-wrapper">
                        <select name="box_categories[<?php echo $index; ?>][products][]" multiple class="product-select">
                            <?php
                            foreach ($selected_products as $product_id) {
                                $product = wc_get_product($product_id);
                                if ($product) {
                                    echo '<option value="' . $product_id . '" selected>' . esc_html($product->get_name()) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <button type="button" class="remove-category">Remove</button>
                </div>
            </div>
            <?php
        }
        
        public function save_box_product_data($post_id) {
            if (isset($_POST['_box_size'])) {
                update_post_meta($post_id, '_box_size', intval($_POST['_box_size']));
            }
            
            $checkbox_fields = array('_enable_box_discount');
            foreach ($checkbox_fields as $field) {
                update_post_meta($post_id, $field, isset($_POST[$field]) ? 'yes' : 'no');
            }
            
            $text_fields = array('_discount_min_items', '_discount_type', '_discount_value');
            foreach ($text_fields as $field) {
                if (isset($_POST[$field])) {
                    update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
                }
            }
            
            if (isset($_POST['box_categories'])) {
                $categories = array();
                foreach ($_POST['box_categories'] as $category) {
                    if (!empty($category['name'])) {
                        $categories[] = array(
                            'name' => sanitize_text_field($category['name']),
                            'products' => isset($category['products']) ? array_map('intval', $category['products']) : array()
                        );
                    }
                }
                update_post_meta($post_id, '_box_categories', $categories);
            }
        }
        
        public function add_to_cart_template() {
            global $product;
            if ($product->get_type() == 'box_product') {
                wc_get_template('single-product/add-to-cart/simple.php');
            }
        }
        
        public function display_box_selector() {
            global $product;
            
            if ($product->get_type() !== 'box_product') return;
            
            $categories = get_post_meta($product->get_id(), '_box_categories', true);
            $box_size = get_post_meta($product->get_id(), '_box_size', true) ?: 6;
            $enable_discount = get_post_meta($product->get_id(), '_enable_box_discount', true) === 'yes';
            $discount_min = get_post_meta($product->get_id(), '_discount_min_items', true) ?: 3;
            $discount_type = get_post_meta($product->get_id(), '_discount_type', true) ?: 'percentage';
            $discount_value = get_post_meta($product->get_id(), '_discount_value', true) ?: 10;
            
            if (empty($categories)) return;
            ?>
            <div id="box-builder-container">
                <div class="box-builder-wrapper">
                    <div class="products-column">
                        <h3>Available Products</h3>
                        <div class="categories-list">
                            <?php foreach ($categories as $index => $category): ?>
                                <div class="category-section">
                                    <h4 class="category-title"><?php echo esc_html($category['name']); ?></h4>
                                    <div class="category-products-grid">
                                        <?php foreach ($category['products'] as $product_id): 
                                            $item_product = wc_get_product($product_id);
                                            if (!$item_product || !$item_product->is_in_stock()) continue;
                                    
                                            $is_variable = $item_product->is_type('variable');
                                        ?>
                                            <div class="product-box-item" data-product-id="<?php echo $product_id; ?>" data-is-variable="<?php echo $is_variable ? 'true' : 'false'; ?>">
                                                <div class="product-box-image-wrapper">
                                                    <div class="product-box-image">
                                                        <?php echo $item_product->get_image('woocommerce_thumbnail'); ?>
                                                    </div>
                                                </div>
                                                <div class="product-box-content">
                                                    <h5 class="product-box-title"><?php echo esc_html($item_product->get_name()); ?></h5>
                                                    <p class="product-box-price"><?php echo $item_product->get_price_html(); ?></p>
                                                    <?php if ($is_variable): ?>
                                                        <div class="product-box-variations">
                                                            <?php
                                                            $attributes = $item_product->get_variation_attributes();
                                                            foreach ($attributes as $attribute_name => $options):
                                                            ?>
                                                                <select class="variation-select" data-attribute-name="attribute_<?php echo esc_attr(sanitize_title($attribute_name)); ?>">
                                                                    <option value=""><?php echo wc_attribute_label($attribute_name); ?></option>
                                                                    <?php foreach ($options as $option): ?>
                                                                        <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html(ucfirst($option)); ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <?php
                                                        $variations_data = [];
                                                        foreach ($item_product->get_available_variations() as $variation) {
                                                            $variations_data[] = [
                                                                'variation_id' => $variation['variation_id'],
                                                                'attributes' => $variation['attributes'],
                                                                'price_html' => $variation['price_html'],
                                                                'display_price' => $variation['display_price'],
                                                                'is_in_stock' => $variation['is_in_stock'],
                                                            ];
                                                        }
                                                        ?>
                                                        <script type="application/json" class="variations-json-data">
                                                            <?php echo json_encode($variations_data); ?>
                                                        </script>
                                                    <?php endif; ?>
                                                    <div class="product-box-actions">
                                                        <?php if ($is_variable): ?>
                                                            <button type="button" class="button add-to-box" disabled>Select Your Size</button>
                                                        <?php else: ?>
                                                            <button type="button" class="button add-to-box" data-price="<?php echo esc_attr($item_product->get_price()); ?>" data-name="<?php echo esc_attr($item_product->get_name()); ?>">Add to Box</button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="box-column">
                        <h3>Your Outfit <span class="box-counter">(0/<?php echo $box_size; ?>)</span></h3>
                        <?php if ($enable_discount): ?>
                            <div class="discount-notice">
                                <p>🎉 Add <?php echo $discount_min; ?> items to get <?php echo $discount_value; ?><?php echo $discount_type === 'percentage' ? '%' : ' ' . get_woocommerce_currency_symbol(); ?> discount!</p>
                            </div>
                        <?php endif; ?>
                        <div class="box-slots">
                            <?php for ($i = 1; $i <= $box_size; $i++): ?>
                                <div class="box-slot empty" data-slot="<?php echo $i; ?>">
                                    <div class="slot-number"><?php echo $i; ?></div>
                                    <div class="slot-content">
                                        <span class="empty-text">Empty</span>
                                    </div>
                                    <button class="remove-item" style="display:none;">×</button>
                                </div>
                            <?php endfor; ?>
                        </div>
                        <div class="box-total">
                            <div id="box-original-price" style="display:none;">
                                <span style="color: #999; text-decoration: line-through;">Original Price: <span id="original-price-value"><?php echo wc_price($product->get_price()); ?></span></span>
                            </div>
                            <div id="discount-applied" style="display:none; color: green; margin: 10px 0;"></div>
                            <h4>Total: <span id="box-total-price"><?php echo wc_price($product->get_price()); ?></span></h4>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="box_selections" id="box_selections" value="">
                <input type="hidden" name="box_discount_applied" id="box_discount_applied" value="">
            </div>
            
            <style>
                #box-builder-container {
                    position: relative;
                    margin: 30px 0;
                    padding: 0;
                    background: none;
                }
                
                .box-builder-wrapper { 
                    display: flex; 
                    gap: 30px; 
                    width: 100%; 
                    margin: 0 auto;
                    padding: 0 15px;
                }
                
                .products-column { 
                    flex: 3; 
                    border: 1px solid #ddd; 
                    padding: 25px; 
                    border-radius: 8px; 
                    background: #fff;
                }
                
                .box-column { 
                    flex: 2; 
                    border: 1px solid #ddd; 
                    padding: 25px; 
                    border-radius: 8px; 
                    background: #fff; 
                    position: sticky; 
                    top: 20px; 
                    height: fit-content; 
                    min-width: 320px;
                }
                
                .products-column h3, .box-column h3 { 
                    margin-top: 0; 
                    margin-bottom: 20px; 
                    font-size: 20px;
                }
                
                .categories-list { 
                    max-height: 400px; 
                    overflow-y: auto; 
                    padding-right: 10px;
                }
                
                .discount-notice {
                    background: #fff3cd;
                    border: 1px solid #ffc107;
                    border-radius: 4px;
                    padding: 10px;
                    margin-bottom: 15px;
                }
                
                .discount-notice p {
                    margin: 0;
                    font-size: 14px;
                    color: #856404;
                }
                
                .category-section { 
                    margin-bottom: 30px;
                }
                
                .category-title { 
                    margin: 0 0 15px 0; 
                    padding: 10px; 
                    background: #f5f5f5; 
                    border-radius: 4px; 
                    font-size: 16px;
                }
                
                .category-products-grid { 
                    display: grid; 
                    grid-template-columns: repeat(3, 1fr); 
                    gap: 20px;
                }
                
                .product-box-item { 
                    border: 1px solid #e0e0e0; 
                    border-radius: 8px; 
                    display: flex; 
                    flex-direction: column; 
                    overflow: hidden; 
                    transition: box-shadow 0.3s;
                }
                
                .product-box-item:hover { 
                    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                }
                
                .product-box-item.product-added { 
                    opacity: 0.5; 
                    pointer-events: none; 
                    position: relative;
                }
                
                .product-box-item.product-added::after { 
                    content: "✓ Added"; 
                    position: absolute; 
                    top: 50%; 
                    left: 50%; 
                    transform: translate(-50%, -50%); 
                    background: rgba(76, 175, 80, 0.95); 
                    color: white; 
                    padding: 10px 20px; 
                    border-radius: 5px; 
                    font-weight: bold; 
                    font-size: 16px; 
                    z-index: 10;
                }
                
                .product-box-image-wrapper {
                    width: 100%;
                    padding-bottom: 133.33%;
                    position: relative;
                    overflow: hidden;
                    background: #f9f9f9;
                }
                
                .product-box-image {
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    text-align: center;
                }
                
                .product-box-image img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    display: block;
                }
                
                .product-box-content { 
                    padding: 15px; 
                    display: flex; 
                    flex-direction: column; 
                    flex-grow: 1;
                }
                
                .product-box-title { 
                    margin: 0 0 10px 0; 
                    font-size: 14px;
                }
                
                .product-box-price { 
                    margin-bottom: 15px; 
                    font-weight: bold; 
                    color: #4CAF50;
                }
                
                .product-box-variations { 
                    margin-bottom: 15px; 
                    display: flex; 
                    flex-direction: column; 
                    gap: 10px;
                }
                
                .product-box-variations .variation-select { 
                    width: 100%; 
                    padding: 8px;
                }
                
                .product-box-actions { 
                    margin-top: auto; 
                    display: flex; 
                    gap: 10px;
                }
                
                .product-box-actions .button { 
                    flex: 1; 
                    text-align: center;
                }
                
                .box-slots { 
                    display: grid; 
                    grid-template-columns: repeat(3, 1fr); 
                    gap: 10px; 
                    margin-bottom: 20px;
                }
                
                .box-slot { 
                    border: 2px dashed #ddd; 
                    padding: 15px 10px; 
                    text-align: center; 
                    min-height: 100px; 
                    position: relative; 
                    border-radius: 4px; 
                    transition: all 0.3s; 
                    background: #fafafa;
                }
                
                .box-slot.filled { 
                    border-style: solid; 
                    border-color: #333; 
                    background: #fff;
                }
                .slot-number { 
                    position: absolute; 
                    top: 5px; 
                    left: 5px; 
                    background: #333; 
                    color: #fff; 
                    width: 20px; 
                    height: 20px; 
                    border-radius: 50%; 
                    font-size: 11px; 
                    line-height: 20px;
                }
                
                .slot-content img { 
                    width: 40px; 
                    height: 40px; 
                    object-fit: cover; 
                    border-radius: 4px; 
                    margin-bottom: 5px;
                }
                
                .slot-content h6 { 
                    margin: 3px 0; 
                    font-size: 11px; 
                    line-height: 1.2;
                }
                
                .slot-content p { 
                    margin: 0; 
                    font-size: 12px; 
                    font-weight: bold;
                }
                
                .empty-text { 
                    color: #999; 
                    font-size: 12px;
                }
                
                .remove-item { 
                    position: absolute; 
                    top: 5px; 
                    right: 5px; 
                    background: #ff4444; 
                    color: #fff; 
                    border: none; 
                    width: 20px; 
                    height: 20px; 
                    border-radius: 50%; 
                    cursor: pointer; 
                    font-size: 14px; 
                    line-height: 18px;
                }
                
                .box-total { 
                    padding: 15px; 
                    background: #f5f5f5; 
                    border-radius: 4px; 
                    text-align: center;
                }
                
                .box-total h4 { 
                    margin: 0; 
                    font-size: 18px;
                }
                
                html[lang="ar"] .box-builder-wrapper,
                html[lang="ar-SA"] .box-builder-wrapper,
                html[dir="rtl"] .box-builder-wrapper,
                body.rtl .box-builder-wrapper,
                body[dir="rtl"] .box-builder-wrapper {
                    flex-direction: row;
                }
                
                html[lang="ar"] .products-column h3,
                html[lang="ar"] .box-column h3,
                html[lang="ar"] .category-title,
                html[lang="ar"] .product-box-title,
                html[lang="ar"] .box-total,
                html[lang="ar"] .discount-notice,
                html[dir="rtl"] .products-column h3,
                html[dir="rtl"] .box-column h3,
                html[dir="rtl"] .category-title,
                html[dir="rtl"] .product-box-title,
                html[dir="rtl"] .box-total,
                html[dir="rtl"] .discount-notice,
                body.rtl .products-column h3,
                body.rtl .box-column h3,
                body.rtl .category-title,
                body.rtl .product-box-title,
                body.rtl .box-total,
                body.rtl .discount-notice {
                    text-align: right;
                    direction: rtl;
                }
                
                html[lang="ar"] .product-box-content,
                html[dir="rtl"] .product-box-content,
                body.rtl .product-box-content {
                    text-align: right;
                    direction: rtl;
                }
                
                html[lang="ar"] .slot-number,
                html[dir="rtl"] .slot-number,
                body.rtl .slot-number {
                    left: auto;
                    right: 5px;
                }
                
                html[lang="ar"] .remove-item,
                html[dir="rtl"] .remove-item,
                body.rtl .remove-item {
                    right: auto;
                    left: 5px;
                }
                
                html[lang="ar"] .categories-list,
                html[dir="rtl"] .categories-list,
                body.rtl .categories-list {
                    padding-right: 0;
                    padding-left: 10px;
                }
                
                html[lang="ar"] .category-products-grid,
                html[lang="ar"] .box-slots,
                html[dir="rtl"] .category-products-grid,
                html[dir="rtl"] .box-slots,
                body.rtl .category-products-grid,
                body.rtl .box-slots {
                    direction: rtl;
                }
                
                @media (max-width: 768px) {
                    .box-builder-wrapper { 
                        flex-direction: column; 
                        gap: 20px;
                        padding-bottom: 0;
                    }
                    
                    html[lang="ar"] .box-builder-wrapper,
                    html[lang="ar-SA"] .box-builder-wrapper,
                    html[dir="rtl"] .box-builder-wrapper,
                    body.rtl .box-builder-wrapper,
                    body[dir="rtl"] .box-builder-wrapper {
                        flex-direction: column;
                    }
                    
                    .box-column { 
                        position: static;
                    }
                    
                    .categories-list { 
                        max-height: 300px;
                    }
                    
                    .category-products-grid {
                        grid-template-columns: repeat(2, 1fr);
                        gap: 15px;
                    }
                    
                    .box-slots { 
                        grid-template-columns: repeat(2, 1fr);
                    }
                }
            </style>
            
            <script>
                jQuery(document).ready(function($) {
                    setTimeout(function() {
                        if ($('.summary').length && $('#box-builder-container').length) {
                            var windowWidth = $(window).width();
                            var contentArea = $('.summary').parent();
                            if (contentArea.length) {
                                var contentOffset = contentArea.offset().left;
                                
                                $('#box-builder-container').css({
                                    'position': 'relative',
                                    'left': -contentOffset + 'px',
                                    'width': windowWidth + 'px',
                                    'padding': '0 ' + contentOffset + 'px'
                                });
                                
                                $(window).resize(function() {
                                    var windowWidth = $(window).width();
                                    var contentArea = $('.summary').parent();
                                    if (contentArea.length) {
                                        var contentOffset = contentArea.offset().left;
                                        $('#box-builder-container').css({
                                            'left': -contentOffset + 'px',
                                            'width': windowWidth + 'px',
                                            'padding': '0 ' + contentOffset + 'px'
                                        });
                                    }
                                });
                            }
                        }
                        
                        var productHeight = $('.product-box-item').first().outerHeight(true);
                        if (productHeight) {
                            var twoRowsHeight = (productHeight * 2) + 40;
                            $('.categories-list').css({
                                'max-height': twoRowsHeight + 'px',
                                'overflow-y': 'auto',
                                'padding-right': '10px'
                            });
                        }
                    }, 100);
                    
                    var boxSlots = {};
                    var addedProducts = {};
                    var boxSize = <?php echo $box_size; ?>;
                    var currentCount = 0;
                    var basePrice = <?php echo $product->get_price() ? $product->get_price() : 0; ?>;
                    var enableDiscount = <?php echo $enable_discount ? 'true' : 'false'; ?>;
                    var discountMin = <?php echo $discount_min; ?>;
                    var discountType = '<?php echo $discount_type; ?>';
                    var discountValue = <?php echo $discount_value; ?>;
                    var discountApplied = false;
                    
                    function scrollToBoxesOnMobile() {
                        if ($(window).width() <= 768) {
                            var boxColumn = $('.box-column');
                            if (boxColumn.length) {
                                $('html, body').animate({
                                    scrollTop: boxColumn.offset().top - 20
                                }, 500);
                            }
                        }
                    }
                
                    function getProductKey(productId, variationId) {
                        return variationId ? productId + '_' + variationId : productId.toString();
                    }
                
                    function findMatchingVariation($productItem) {
                        var variationsData = JSON.parse($productItem.find('.variations-json-data').html());
                        var selectedOptions = {};
                        var allOptionsSelected = true;
                
                        $productItem.find('.variation-select').each(function() {
                            var attributeName = $(this).data('attribute-name');
                            var selectedValue = $(this).val();
                            if (selectedValue) {
                                selectedOptions[attributeName] = selectedValue;
                            } else {
                                allOptionsSelected = false;
                            }
                        });
                
                        if (!allOptionsSelected) return null;
                
                        return variationsData.find(function(variation) {
                            return Object.keys(selectedOptions).every(function(key) {
                                return variation.attributes[key] === '' || variation.attributes[key] === selectedOptions[key];
                            });
                        });
                    }
                
                    $('.products-column').on('change', '.variation-select', function() {
                        var $productItem = $(this).closest('.product-box-item');
                        var matchingVariation = findMatchingVariation($productItem);
                        var $priceDisplay = $productItem.find('.product-box-price');
                        var $addButton = $productItem.find('.add-to-box');
                        
                        if (!$productItem.data('original-price-html')) {
                            $productItem.data('original-price-html', $priceDisplay.html());
                        }
                
                        if (matchingVariation && matchingVariation.is_in_stock) {
                            var productId = $productItem.data('product-id');
                            var variationId = matchingVariation.variation_id;
                            var productKey = getProductKey(productId, variationId);
                            
                            if (addedProducts[productKey]) {
                                $addButton.prop('disabled', true).text('Already Added');
                            } else {
                                $priceDisplay.html(matchingVariation.price_html);
                                $addButton.prop('disabled', false).text('Add to Box');
                                
                                var variationName = $productItem.find('.product-box-title').text();
                                var attributes = [];
                                $productItem.find('.variation-select option:selected').each(function() {
                                    if ($(this).val()) {
                                        attributes.push($(this).text());
                                    }
                                });
                                
                                $addButton.data('variation-id', variationId);
                                $addButton.data('price', matchingVariation.display_price);
                                $addButton.data('name', variationName + ' - ' + attributes.join(', '));
                            }
                        } else {
                            $priceDisplay.html($productItem.data('original-price-html'));
                            $addButton.prop('disabled', true).text('Select Options');
                        }
                    });
                
                    $('.products-column').on('click', '.add-to-box', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var $button = $(this);
                        if ($button.prop('disabled')) return;
                        
                        if (currentCount >= boxSize) {
                            alert('Box is full! Remove an item first.');
                            return;
                        }
                
                        var $product = $button.closest('.product-box-item');
                        var isVariable = $product.data('is-variable') === true || $product.data('is-variable') === 'true';
                        
                        var productId = $product.data('product-id');
                        var variationId = isVariable ? $button.data('variation-id') : null;
                        var productKey = getProductKey(productId, variationId);
                        
                        if (addedProducts[productKey]) {
                            alert('This product is already in your box!');
                            return;
                        }
                        
                        var productName = $button.data('name');
                        var productPrice = parseFloat($button.data('price'));
                        var productImage = $product.find('img').first().clone();
                        
                        var $emptySlot = $('.box-slot.empty').first();
                        if ($emptySlot.length) {
                            var slotNumber = $emptySlot.data('slot');
                            
                            $emptySlot.removeClass('empty').addClass('filled');
                            $emptySlot.find('.slot-content').html('');
                            $emptySlot.find('.slot-content').append(productImage);
                            $emptySlot.find('.slot-content').append('<h6>' + productName + '</h6>');
                            $emptySlot.find('.slot-content').append('<p>' + '<?php echo get_woocommerce_currency_symbol(); ?>' + productPrice.toFixed(2) + '</p>');
                            $emptySlot.find('.remove-item').show();
                            $emptySlot.attr('data-product-key', productKey);
                            
                            boxSlots[slotNumber] = {
                                id: productId,
                                variation_id: variationId,
                                name: productName,
                                price: productPrice
                            };
                            
                            addedProducts[productKey] = true;
                            
                            $product.addClass('product-added');
                            $button.prop('disabled', true).text('Added');
                            
                            currentCount++;
                            updateCounter();
                            calculateTotal();
                            
                            scrollToBoxesOnMobile();
                        }
                    });
                    
                    $(document).on('click', '.remove-item', function() {
                        var $slot = $(this).closest('.box-slot');
                        var slotNumber = $slot.data('slot');
                        var productKey = $slot.attr('data-product-key');
                        
                        if (productKey) {
                            delete addedProducts[productKey];
                            
                            var parts = productKey.split('_');
                            var productId = parts[0];
                            
                            var $productItem = $('.product-box-item[data-product-id="' + productId + '"]');
                            $productItem.removeClass('product-added');
                            $productItem.find('.add-to-box').each(function() {
                                var $btn = $(this);
                                if (!$btn.data('variation-id') || $btn.data('variation-id') == parts[1]) {
                                    $btn.prop('disabled', false).text('Add to Box');
                                }
                            });
                        }
                        
                        delete boxSlots[slotNumber];
                        
                        $slot.removeClass('filled').addClass('empty');
                        $slot.find('.slot-content').html('<span class="empty-text">Empty</span>');
                        $slot.find('.remove-item').hide();
                        $slot.removeAttr('data-product-key');
                        
                        currentCount--;
                        updateCounter();
                        calculateTotal();
                    });
                    
                    function updateCounter() {
                        $('.box-counter').text('(' + currentCount + '/' + boxSize + ')');
                    }
                    
                    window.calculateTotal = function() {
                        var subtotal = basePrice;
                        var finalSelections = [];
                        
                        for (var slot in boxSlots) {
                            if (boxSlots.hasOwnProperty(slot)) {
                                var item = boxSlots[slot];
                                subtotal += item.price;
                                finalSelections.push({
                                    id: item.id,
                                    variation_id: item.variation_id,
                                    name: item.name,
                                    price: item.price
                                });
                            }
                        }
                        
                        var total = subtotal;
                        discountApplied = false;
                        
                        if (enableDiscount && currentCount >= discountMin) {
                            var discountAmount = 0;
                            if (discountType === 'percentage') {
                                discountAmount = subtotal * (discountValue / 100);
                            } else {
                                discountAmount = discountValue;
                            }
                            total = subtotal - discountAmount;
                            discountApplied = true;
                            
                            // Show original price
                            $('#box-original-price').show();
                            $('#original-price-value').html('<?php echo get_woocommerce_currency_symbol(); ?>' + subtotal.toFixed(2));
                            
                            // Show discount
                            $('#discount-applied').html('🎉 Discount: -' + '<?php echo get_woocommerce_currency_symbol(); ?>' + discountAmount.toFixed(2)).show();
                            $('#box_discount_applied').val(discountAmount);
                        } else {
                            $('#box-original-price').hide();
                            $('#discount-applied').hide();
                            $('#box_discount_applied').val(0);
                        }
                        
                        $('#box-total-price').html('<?php echo get_woocommerce_currency_symbol(); ?>' + total.toFixed(2));
                        $('#box_selections').val(JSON.stringify(finalSelections));
                    }
                    
                    $('form.cart').on('submit', function(e) {
                        if (currentCount === 0) {
                            alert('Please add at least one item to your box!');
                            e.preventDefault();
                            return false;
                        }
                        calculateTotal();
                        return true;
                    });
                });
            </script>
            <?php
        }

        public function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
            if (isset($_POST['box_selections']) && !empty($_POST['box_selections'])) {
                $selections_raw = stripslashes($_POST['box_selections']);
                $selections = json_decode($selections_raw, true);
        
                if (json_last_error() === JSON_ERROR_NONE && is_array($selections) && !empty($selections)) {
                    
                    $cart_item_data['box_product_id'] = $product_id;
                    $cart_item_data['box_selections'] = $selections;
                    
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $total_price = (float) $product->get_price();
                        
                        foreach ($selections as $item) {
                            $total_price += (float) $item['price'];
                        }
                        
                        if (isset($_POST['box_discount_applied']) && $_POST['box_discount_applied'] > 0) {
                            $discount = floatval($_POST['box_discount_applied']);
                            $total_price -= $discount;
                            $cart_item_data['box_discount'] = $discount;
                        }
                        
                        $cart_item_data['box_total_price'] = $total_price;
                    }
                }
            }
            return $cart_item_data;
        }

        public function set_box_product_price_in_cart($cart) {
            if (is_admin() && !defined('DOING_AJAX')) return;
        
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                if (isset($cart_item['box_total_price'])) {
                    $cart_item['data']->set_price($cart_item['box_total_price']);
                }
            }
        }
        
        public function display_cart_item_data($item_data, $cart_item) {
            if (isset($cart_item['box_selections']) && !empty($cart_item['box_selections']) && is_array($cart_item['box_selections'])) {
                
                $display_value = '<div style="margin-top: 8px; padding: 8px; background: #f9f9f9; border-radius: 4px;">';
                
                foreach ($cart_item['box_selections'] as $item) {
                    $product_name = esc_html($item['name']);
                    $product_id = isset($item['variation_id']) && $item['variation_id'] ? intval($item['variation_id']) : intval($item['id']);
                    
                    $display_value .= '<div style="margin: 4px 0; padding: 4px 0; border-bottom: 1px solid #e0e0e0;">';
                    $display_value .= '<strong>• ' . $product_name . '</strong>';
                    $display_value .= ' <span style="color: #666; font-size: 0.9em;">(ID: ' . $product_id . ')</span>';
                    $display_value .= '</div>';
                }
                
                if (isset($cart_item['box_discount']) && $cart_item['box_discount'] > 0) {
                    $display_value .= '<div style="margin-top: 8px; color: green;"><strong>Discount Applied: -' . wc_price($cart_item['box_discount']) . '</strong></div>';
                }
                
                $display_value .= '</div>';
                
                $item_data[] = array(
                    'key'     => '<strong style="color: #2c3e50;">Box Contents</strong>',
                    'value'   => $display_value,
                    'display' => ''
                );
            }
            return $item_data;
        }

        public function add_box_selections_to_order_item($item, $cart_item_key, $values, $order) {
            if (isset($values['box_selections']) && is_array($values['box_selections'])) {
                
                $box_contents = array();
                foreach ($values['box_selections'] as $selection) {
                    $product_name = esc_html($selection['name']);
                    $product_id = isset($selection['variation_id']) && $selection['variation_id'] ? intval($selection['variation_id']) : intval($selection['id']);
                    $box_contents[] = $product_name . ' (ID: ' . $product_id . ')';
                }
                
                $item->add_meta_data('_box_contents', $box_contents, true);
                
                if (isset($values['box_discount']) && $values['box_discount'] > 0) {
                    $item->add_meta_data('_box_discount', $values['box_discount'], true);
                }
                
                foreach ($box_contents as $content) {
                    $item->add_meta_data('Box Content', $content, false);
                }
            }
        }

        public function display_box_contents_in_order($item_id, $item, $order, $plain_text) {
            $box_contents = $item->get_meta('_box_contents');
            $box_discount = $item->get_meta('_box_discount');
            
            if (!empty($box_contents) && is_array($box_contents)) {
                echo '<div class="box-contents-display" style="margin-top: 12px; padding: 12px; background: #f9f9f9; border-left: 4px solid #2c3e50; border-radius: 4px;">';
                echo '<strong style="display: block; margin-bottom: 10px; color: #2c3e50; font-size: 14px;">📦 Box Contents:</strong>';
                echo '<ul style="margin: 0; padding-left: 20px; list-style: none;">';
                foreach ($box_contents as $content) {
                    echo '<li style="margin-bottom: 6px; padding: 4px 0; border-bottom: 1px solid #e0e0e0;">✓ ' . esc_html($content) . '</li>';
                }
                if ($box_discount > 0) {
                    echo '<li style="margin-top: 10px; color: green;"><strong>Discount Applied: -' . wc_price($box_discount) . '</strong></li>';
                }
                echo '</ul>';
                echo '</div>';
            }
        }

        public function add_order_item_header() {
            echo '<th class="box-contents-header" style="text-align: left;">Box Contents</th>';
        }

        public function display_box_contents_in_admin($product, $item, $item_id) {
            $box_contents = $item->get_meta('_box_contents');
            $box_discount = $item->get_meta('_box_discount');
            
            echo '<td class="box-contents-column">';
            if (!empty($box_contents) && is_array($box_contents)) {
                echo '<div style="padding: 10px; background: #f0f0f0; border-radius: 4px; border-left: 4px solid #2c3e50;">';
                echo '<strong style="display: block; margin-bottom: 8px; color: #2c3e50;">📦 Box Contents:</strong>';
                echo '<ul style="margin: 5px 0; padding-left: 20px; list-style: none;">';
                foreach ($box_contents as $content) {
                    echo '<li style="margin: 5px 0; padding: 3px 0; border-bottom: 1px solid #ddd;">✓ ' . esc_html($content) . '</li>';
                }
                if ($box_discount > 0) {
                    echo '<li style="margin-top: 8px; color: green;"><strong>Discount: -' . wc_price($box_discount) . '</strong></li>';
                }
                echo '</ul>';
                echo '</div>';
            } else {
                echo '<span style="color: #999;">-</span>';
            }
            echo '</td>';
        }

        public function add_mobile_sticky_bar() {
            if (!is_product()) return;
            
            global $product;
            if (!$product || $product->get_type() !== 'box_product') return;
            ?>
            <div id="mobile-sticky-cart-bar" class="mobile-sticky-bar hidden">
                <div class="sticky-bar-wrapper">
                    <div class="sticky-total-section">
                        <div id="sticky-original-price" style="display:none; font-size: 14px; color: #999; text-decoration: line-through; margin-bottom: 5px;">
                            <span id="sticky-original-price-value"></span>
                        </div>
                        <div id="sticky-discount-applied" style="display:none; font-size: 13px; margin-bottom: 5px;"></div>
                        <h4 class="sticky-total-label">Total: <span id="sticky-box-total-price"><?php echo wc_price($product->get_price()); ?></span></h4>
                    </div>
                    <button type="button" class="sticky-add-to-cart-button single_add_to_cart_button button alt">ADD TO CART</button>
                </div>
            </div>

            <style>
                .mobile-sticky-bar {
                    position: fixed;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    background: #fff;
                    box-shadow: 0 -2px 10px rgba(0,0,0,0.15);
                    z-index: 9998;
                    transform: translateY(100%);
                    transition: transform 0.3s ease-in-out;
                    display: none;
                    border-top: 1px solid #e0e0e0;
                }
                
                .mobile-sticky-bar.visible {
                    transform: translateY(0);
                }
                
                .mobile-sticky-bar.hidden {
                    transform: translateY(100%);
                }
                
                .sticky-bar-wrapper {
                    padding: 12px 15px;
                }
                
                .sticky-total-section {
                    background: #f5f5f5;
                    padding: 10px 12px;
                    border-radius: 4px;
                    margin-bottom: 10px;
                    text-align: center;
                }
                
                .sticky-total-label {
                    margin: 0;
                    font-size: 18px;
                    font-weight: 700;
                    color: #000;
                }
                
                #sticky-box-total-price {
                    color: #000;
                }
                
                #sticky-discount-applied {
                    color: #27ae60;
                    font-weight: 600;
                }
                
                .sticky-add-to-cart-button {
                    width: 100%;
                    margin: 0;
                }
                
                html[lang="ar"] .sticky-bar-wrapper,
                html[dir="rtl"] .sticky-bar-wrapper,
                body.rtl .sticky-bar-wrapper {
                    direction: rtl;
                }
                
                @media (min-width: 769px) {
                    .mobile-sticky-bar {
                        display: none !important;
                    }
                }
                
                @media (max-width: 768px) {
                    .mobile-sticky-bar {
                        display: block;
                    }
                }
                
                @media (max-width: 480px) {
                    .sticky-bar-wrapper {
                        padding: 10px 12px;
                    }
                    
                    .sticky-total-section {
                        padding: 8px 10px;
                        margin-bottom: 8px;
                    }
                    
                    .sticky-total-label {
                        font-size: 16px;
                    }
                }
            </style>

            <script>
            jQuery(document).ready(function($) {
                if ($('#box-builder-container').length === 0) return;
                
                var $stickyBar = $('#mobile-sticky-cart-bar');
                var $window = $(window);
                var lastScrollTop = 0;
                var scrollThreshold = 200;
                var isVisible = false;
                
                function updateStickyBar() {
                    var totalPrice = $('#box-total-price').html();
                    var originalPrice = $('#original-price-value').html();
                    var discountText = $('#discount-applied').html();
                    var isDiscountVisible = $('#discount-applied').is(':visible');
                    var isOriginalPriceVisible = $('#box-original-price').is(':visible');
                    
                    if (totalPrice) {
                        $('#sticky-box-total-price').html(totalPrice);
                    }
                    
                    if (isOriginalPriceVisible && originalPrice) {
                        $('#sticky-original-price-value').html('Original: ' + originalPrice);
                        $('#sticky-original-price').show();
                    } else {
                        $('#sticky-original-price').hide();
                    }
                    
                    if (isDiscountVisible && discountText) {
                        $('#sticky-discount-applied').html(discountText.replace('🎉 ', '')).show();
                    } else {
                        $('#sticky-discount-applied').hide();
                    }
                }
                
                var observer = new MutationObserver(function() {
                    updateStickyBar();
                });
                
                if ($('#box-total-price').length) {
                    observer.observe($('#box-total-price')[0], {
                        childList: true,
                        subtree: true,
                        characterData: true
                    });
                }
                
                if ($('#discount-applied').length) {
                    observer.observe($('#discount-applied')[0], {
                        attributes: true,
                        childList: true,
                        subtree: true
                    });
                }
                
                if ($('#box-original-price').length) {
                    observer.observe($('#box-original-price')[0], {
                        attributes: true,
                        childList: true,
                        subtree: true
                    });
                }
                
                $window.on('scroll', function() {
                    var scrollTop = $window.scrollTop();
                    var windowWidth = $window.width();
                    
                    if (windowWidth > 768) {
                        $stickyBar.removeClass('visible').addClass('hidden');
                        return;
                    }
                    
                    if (scrollTop > scrollThreshold) {
                        if (scrollTop > lastScrollTop) {
                            if (!isVisible) {
                                $stickyBar.removeClass('hidden').addClass('visible');
                                isVisible = true;
                            }
                        } else {
                            if (isVisible) {
                                $stickyBar.removeClass('visible').addClass('hidden');
                                isVisible = false;
                            }
                        }
                    } else {
                        if (isVisible) {
                            $stickyBar.removeClass('visible').addClass('hidden');
                            isVisible = false;
                        }
                    }
                    
                    lastScrollTop = scrollTop;
                });
                
                $('.sticky-add-to-cart-button').on('click', function(e) {
                    e.preventDefault();
                    
                    var currentCount = 0;
                    $('.box-slot.filled').each(function() {
                        currentCount++;
                    });
                    
                    if (currentCount === 0) {
                        alert('Please add at least one item to your box!');
                        
                        $('html, body').animate({
                            scrollTop: $('#box-builder-container').offset().top - 20
                        }, 500);
                        return;
                    }
                    
                    if (typeof calculateTotal === 'function') {
                        calculateTotal();
                    }
                    
                    $('form.cart').submit();
                });
                
                updateStickyBar();
                
                $(document).on('click', '.add-to-box, .remove-item', function() {
                    setTimeout(function() {
                        updateStickyBar();
                    }, 100);
                });
            });
            </script>
            <?php
        }
        
        public function enqueue_scripts() {
            if (is_product()) {
                wp_enqueue_script('jquery');
            }
            if (is_admin()) {
                wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);
                wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0');
            }
        }
    }
    
    new WC_Box_Product();
}
