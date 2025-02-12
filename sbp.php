<?php

/*
Plugin Name: Swiss Bitcoin Pay Payment Gateway
Plugin URI: https://github.com/SwissBitcoinPay/woocommerce-plugin
Description: Accept Bitcoin in a few minutes
Text Domain: swiss-bitcoin-pay
Domain Path: /languages
Version: 1.0.0
Author: Thomas Ballivet
Author URI: https://github.com/thomask7b
License: MIT
License URI: https://github.com/SwissBitcoinPay/woocommerce-plugin/blob/main/LICENSE
*/

add_action('plugins_loaded', 'swiss_bitcoin_pay_gateway_init');

if ( ! defined( 'ABSPATH' ) ) exit;

require_once(__DIR__ . '/init.php');

use SwissBitcoinPayPlugin\API;

// This is the entry point of the plugin, where everything is registered/hooked up into WordPress.
function swiss_bitcoin_pay_gateway_init()
{
    if ( ! class_exists('WC_Payment_Gateway')) {
        return;
    };

    // Register the gateway, essentially a controller that handles all requests.
    function add_sbp_gateway($methods)
    {
        $methods[] = 'Swiss_Bitcoin_Pay_Payment_Gateway';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_sbp_gateway');


    // Defined here, because it needs to be defined after WC_Payment_Gateway is already loaded.
    class Swiss_Bitcoin_Pay_Payment_Gateway extends WC_Payment_Gateway {
        public function __construct()
        {
            global $woocommerce;

            $this->id                 = 'sbp';
            $this->icon               = plugin_dir_url(__FILE__) . 'assets/sbp.png';
            $this->has_fields         = false;
            $this->method_title       = __('Swiss Bitcoin Pay', 'swiss-bitcoin-pay');
            $this->method_description = __('Accept Bitcoin payments through Swiss Bitcoin Pay', 'swiss-bitcoin-pay');
            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title');
            $this->description = $this->get_option('description');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ));
        }

        //Render admin options/settings
        public function admin_options()
        {
            ?>
            <h3><?php esc_html_e('Swiss Bitcoin Pay', 'swiss-bitcoin-pay'); ?></h3>
            <p><?php esc_html_e('Accept Bitcoin instantly through Swiss Bitcoin Pay', 'swiss-bitcoin-pay'); ?></p>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php

        }

        //Generate config form fields, shown in admin->WooCommerce->Settings
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled'                             => array(
                    'title'       => __('Swiss Bitcoin Pay payment gateway', 'swiss-bitcoin-pay'),
                    'label'       => __('Enable payments through Swiss Bitcoin Pay', 'swiss-bitcoin-pay'),
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no',
                ),
                'sbp_api_key'       => array(
                    'title'       => __('Swiss Bitcoin Pay API key', 'swiss-bitcoin-pay'),
                    'type'        => 'text',
                    'description' => __('Available from your Swiss Bitcoin Pay dashboard', 'swiss-bitcoin-pay'),
                ),
                'sbp_secret_key'       => array(
                    'title'       => __('Swiss Bitcoin Pay Secret key (HMAC)', 'swiss-bitcoin-pay'),
                    'type'        => 'password',
                    'description' => __('Available from your Swiss Bitcoin Pay dashboard', 'swiss-bitcoin-pay'),
                ),
                'title'                               => array(
                    'title'       => __('Title', 'swiss-bitcoin-pay'),
                    'type'        => 'text',
                    'description' => __('The payment method title which customers sees at the checkout of your store', 'swiss-bitcoin-pay'),
                    'default'     => __('Pay with Bitcoin', 'swiss-bitcoin-pay'),
                ),
                'description'                         => array(
                    'title'       => __('Description', 'swiss-bitcoin-pay'),
                    'type'        => 'textarea',
                    'description' => __('The payment method description which customers sees at the checkout of your store', 'swiss-bitcoin-pay'),
                    'default'     => __('Use any Bitcoin wallet to pay. Powered by Swiss Bitcoin Pay', 'swiss-bitcoin-pay'),
                ),
                'sbp_is_on_chain'                             => array(
                    'title'       => __('On chain payments', 'swiss-bitcoin-pay'),
                    'label'       => __('Enable on chain payments for customers', 'swiss-bitcoin-pay'),
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'yes',
                ),
            );
        }


        //Output for thank you page
        public function thankyou()
        {
            if ($description = $this->get_description()) {
                echo esc_html(wpautop(wptexturize($description)));
            }
        }


        /**
         * Register WooCommerce Blocks support.
         */
        public static function blocksSupport() {
            if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
                require_once dirname( __FILE__ ) . '/blocks/blocks.php';
                add_action(
                    'woocommerce_blocks_payment_method_type_registration',
                    function( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                        $payment_method_registry->register(new \SwissBitcoinPay\WC\Blocks\DefaultGatewayBlocks());
                    }
                );
            }
        }


        /**
         * Called from checkout page, when "Place order" hit, through AJAX.
         *
         * Call Swiss Bitcoin Pay API to create an invoice, and store the invoice in the order metadata.
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            // This will be stored in the invoice
            $memo = get_bloginfo('name') . " Order #" . $order->get_id() . " Total=" . $order->get_total() . get_woocommerce_currency();

            
            $is_on_chain = $this->get_option('sbp_is_on_chain') == 'yes' ? true : false;

            // Call Swiss Bitcoin Pay server to create invoice
            $r = API::create_charge($order->get_total(), get_woocommerce_currency(), $memo, $order_id, 
                                        $order->get_checkout_order_received_url(), $is_on_chain, $this->get_option('sbp_api_key'));

            if ($r['status'] === 201) {
                $response_body = $r['response'];

                $order->add_meta_data('sbp_payment_id', $response_body['id'], true);
                $order->save();

                return array(
                    "result"   => "success",
                    "redirect" => $response_body['checkoutUrl']
                );
            } else {
                error_log("Swiss Bitcoin Pay API failure. Status=" . $r['status']);
                error_log($r['response']);

                return array(
                    "result"   => "failure",
                    "messages" => array(__("Failed to create Swiss Bitcoin Pay invoice.", 'swiss-bitcoin-pay'))
                );
            }
        }

    }

    
    //Webhook called by Swiss Bitcoin Pay API
    function sbp_add_payment_complete_callback($data) {
        $headers = $data->get_headers();
        $body = $data->get_params();
        
        $sbp_sig = $headers["sbp_sig"][0];
        $signature = explode("=", $sbp_sig)[1];

        if (empty($data["wcOrderId"])) {
            error_log("sbp_add_payment_complete_callback should not be called without id param");
            return;
        }

        $order = wc_get_order($data["wcOrderId"]);

        if (!check_signature($data->get_body(), $signature, $order->get_meta('sbp_payment_id'))) {
            error_log("[Swiss Bitcoin Pay Plugin] Wrong HMAC signature key!");
            echo(wp_json_encode(array(
                'result'   => 'error',
                'reason'   => __('Wrong signature key', 'swiss-bitcoin-pay')
            )));
            return;
        }

        if ($body["isPaid"]) {
            $order->add_order_note(__('Payment done. Purchased goods/services can be securely delivered to the customer.', 'swiss-bitcoin-pay'));
            $order->payment_complete();
            $order->save();

            echo(wp_json_encode(array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_order_received_url()
            )));
                        
        } else if ($body["isExpired"]) {
            $order->update_status("cancelled");
            $order->add_order_note(__('Payment expired.', 'swiss-bitcoin-pay'));
            $order->save();
            
            echo(wp_json_encode(array(
                'result'   => 'success',
                'details' => __("Order expired.", 'swiss-bitcoin-pay')
            )));
        }
    }

    function check_signature($json_data, $signature, $sbp_payment_id) {
        $plugin = new Swiss_Bitcoin_Pay_Payment_Gateway();
        $secret_key = $plugin->get_option('sbp_secret_key');
        if ( !empty($secret_key) ) {
                return hash_equals(hash_hmac('sha256', $json_data, $secret_key), $signature);
        }
        return True;
    }

    //Register callback
    add_action("rest_api_init", function () {
        register_rest_route("swiss-bitcoin-pay/v1", "/payment_complete/(?P<wcOrderId>\d+)", array(
            "methods"  => WP_REST_Server::EDITABLE,
            "callback" => "sbp_add_payment_complete_callback",
            'permission_callback' => '__return_true'
        ));
    });

}

add_action( 'woocommerce_blocks_loaded', [ 'Swiss_Bitcoin_Pay_Payment_Gateway', 'blocksSupport' ] );

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );