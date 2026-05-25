<?php
/*
Plugin Name: Intellcom WooCommerce Sync
Description: Visual WooCommerce sync for Intellcom products.
Version: 1.2
Author: You
*/

if (!defined('ABSPATH')) {
    exit;
}

class IntellcomWooSync {

    private $api_url = 'https://intellcom.net/ws/v1.4/products/ea34c847bb5e91de8e8a4e5ab7ec826159f0a0cc2a493f4518f8e1a792366e3c';

    public function __construct() {

        add_action('admin_menu', [$this, 'menu']);

        add_action('admin_post_intellcom_sync_now', [$this, 'sync_now']);
    }

    public function menu() {

        add_menu_page(
            'Intellcom Sync',
            'Intellcom Sync',
            'manage_options',
            'intellcom-sync',
            [$this, 'page'],
            'dashicons-update',
            56
        );
    }

    public function page() {

        if (!current_user_can('manage_options')) {
            return;
        }

        $updated = isset($_GET['updated']) ? intval($_GET['updated']) : -1;
        $error   = isset($_GET['error'])   ? sanitize_text_field(wp_unslash($_GET['error'])) : '';

        ?>

        <div class="wrap">

            <h1>Intellcom WooCommerce Sync</h1>

            <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;max-width:700px;">

                <h2>Manual Sync</h2>

                <p>
                    This will:
                </p>

                <ul>
                    <li>Fetch products from Intellcom API</li>
                    <li>Match WooCommerce products by <strong>SKU</strong> (only Intellcom-sourced products)</li>
                    <li>Update product price</li>
                    <li>Update stock quantity</li>
                </ul>

                <p>
                    Only products imported with <code>source = intelcom</code> will be updated.<br>
                    WooCommerce SKU must match API <code>code_id</code>.
                </p>

                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                    <input type="hidden" name="action" value="intellcom_sync_now">

                    <?php wp_nonce_field('intellcom_sync_nonce'); ?>

                    <button class="button button-primary button-large">
                        Start Sync
                    </button>

                </form>

                <?php if ($updated >= 0 && empty($error)): ?>

                    <div style="margin-top:20px;padding:15px;background:#d1ffd9;border-left:4px solid green;">
                        <strong>Success:</strong>
                        <?php echo intval($updated); ?> product(s) updated.
                        <?php if ($updated === 0): ?>
                            &nbsp;<span style="color:#555">(No matching Intelcom products found — make sure the <code>source</code> meta is set to <code>intelcom</code> and SKUs match.)</span>
                        <?php endif; ?>
                    </div>

                <?php elseif (!empty($error)): ?>

                    <div style="margin-top:20px;padding:15px;background:#ffd1d1;border-left:4px solid red;">
                        <strong>Error:</strong> <?php echo esc_html($error); ?>
                    </div>

                <?php endif; ?>

            </div>

        </div>

        <?php
    }

    public function sync_now() {

        if (!current_user_can('manage_options')) {
            wp_die('No permission');
        }

        check_admin_referer('intellcom_sync_nonce');

        $result = $this->sync_products();

        wp_redirect(admin_url('admin.php?page=intellcom-sync&updated=' . intval($result['updated']) . '&error=' . urlencode($result['error'])));
        exit;
    }

    private function sync_products() {

        $result = ['updated' => 0, 'error' => ''];

        $response = wp_remote_get($this->api_url, [
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            $result['error'] = 'API request failed: ' . $response->get_error_message();
            return $result;
        }

        $body = wp_remote_retrieve_body($response);

        $json = json_decode($body, true);

        if (
            !$json ||
            !isset($json['data']) ||
            !isset($json['data']['products'])
        ) {
            $result['error'] = 'Unexpected API response — could not find products data.';
            return $result;
        }

        $api_products = $json['data']['products'];

        // Build lookup by SKU (code_id) only — no name matching needed
        $lookup = [];

        foreach ($api_products as $item) {

            if (empty($item['code_id'])) {
                continue;
            }

            $sku = trim(strtolower($item['code_id']));

            $lookup[$sku] = $item;
        }

        // Only fetch products that were imported from Intellcom.
        // Meta key is "source" (saved by the CSV importer, no underscore prefix).
        $products = wc_get_products([
            'limit'      => -1,
            'status'     => ['publish', 'draft'],
            'meta_query' => [
                [
                    'key'     => 'source',
                    'value'   => 'intelcom',
                    'compare' => '=',
                ],
            ],
        ]);

        foreach ($products as $product) {

            $sku = trim(strtolower($product->get_sku()));

            if (empty($sku)) {
                continue;
            }

            if (!isset($lookup[$sku])) {
                continue;
            }

            $api_product = $lookup[$sku];

            $price = floatval($api_product['price'] ?? 0);

            // left_qty can be "5+", "10", null — extract digits only safely.
            $qty_raw = isset($api_product['left_qty']) ? (string) $api_product['left_qty'] : '0';
            $qty     = intval(preg_replace('/[^0-9]/', '', $qty_raw));

            $product->set_regular_price($price);
            $product->set_price($price);

            $product->set_manage_stock(true);
            $product->set_stock_quantity($qty);
            $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');

            $product->save();

            $result['updated']++;
        }

        return $result;
    }
}

new IntellcomWooSync();