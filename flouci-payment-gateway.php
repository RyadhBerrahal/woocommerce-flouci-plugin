<?php
/*
Plugin Name: Flouci Payment Gateway
Description: Integrate Flouci Payment Gateway with WooCommerce.
Version: 1.0
Author: Ryadh Berrahal
Author URI: https://www.linkedin.com/in/berrahalryadh/
*/
ob_start();

// Activate hooks for adding payment pages
register_activation_hook(__FILE__, 'wc_flouci_add_payment_pages');

// Add payment pages if they do not exist
function wc_flouci_add_payment_pages() {
    wc_flouci_add_payment_page('Flouci Check Payment', '[flouci_check_payment]');
    wc_flouci_add_payment_page('Failed Payment', 'Failed Payment');
}

// Function to add a payment page if it does not exist
function wc_flouci_add_payment_page($title, $content) {
    if (get_page_by_title($title) == null) {
        $my_post = array(
            'post_title'   => wp_strip_all_tags($title),
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_author'  => 1,
            'post_type'    => 'page',
        );

        wp_insert_post($my_post);
    }
}

// Register payment gateway class
add_filter('woocommerce_payment_gateways', 'wc_flouci_add_payment_gateway_class');
function wc_flouci_add_payment_gateway_class($gateways) {
    $gateways[] = 'WC_Flouci_Payment_Gateway';
    return $gateways;
}

// Initialize payment gateway class
add_action('plugins_loaded', 'wc_flouci_init_payment_gateway_class');
function wc_flouci_init_payment_gateway_class() {
    class WC_Flouci_Payment_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id                 = 'flouci'; // payment gateway plugin ID
            $this->icon               = ''; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields         = false; // in case you need a custom credit card form
            $this->method_title       = 'Flouci Payment Gateway';
            $this->method_description = 'Accept payments via Flouci'; // will be displayed on the options page

            // gateways can support subscriptions, refunds, saved payment methods,
            // but in this tutorial we begin with simple payments
            $this->supports = array(
                'products'
            );

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->title       = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled     = $this->get_option('enabled');
            $this->app_token   = $this->get_option('app_token');
            $this->app_secret  = $this->get_option('app_secret');

            // This action hook saves the settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        /**
         * Plugin options
         */
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Flouci Payment Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'Flouci Payment',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Pay with Flouci.',
                ),
                'testmode' => array(
                    'title'       => 'Test mode',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'checkbox',
                    'description' => 'Place the payment gateway in test mode.',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'app_token' => array(
                    'title'       => 'App Token',
                    'type'        => 'text',
                    'description' => 'Enter your Flouci App Token.',
                ),
                'app_secret' => array(
                    'title'       => 'App Secret',
                    'type'        => 'text',
                    'description' => 'Enter your Flouci App Secret.',
                ),
            );
        }

        /*
         * We're processing the payments here
         */
        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            $response = wp_remote_post('https://developers.flouci.com/api/generate_payment', array(
                'headers'     => array(
                    'Content-Type' => 'application/json',
                ),
                'body'        => json_encode(array(
                    'app_token'             => $this->app_token,
                    'app_secret'            => $this->app_secret,
                    'amount'                => $order->get_total() * 1000,
                    'accept_card'           => 'true',
                    'session_timeout_secs'  => 1200,
                    'success_link'          => get_site_url().'/flouci-check-payment',
                    'fail_link'             => get_site_url().'/failed-payment',
                    'developer_tracking_id' => 'ORDER' . $order_id,
                )),
                'method'      => 'POST',
                'data_format' => 'body',
            ));

            if (is_wp_error($response)) {
                wc_add_notice('Error generating Flouci payment page.', 'error');
                return;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (isset($data['result']['link'])) {
                return array(
                    'result'   => 'success',
                    'redirect' => $data['result']['link']
                );
            } else {
                wc_add_notice('Error generating Flouci payment page.', 'error');
                return;
            }
        }

        /*
         * Verify the status of the Flouci payment transaction
         */
        public function verify_flouci_payment_status($payment_id) {
            $url = 'https://developers.flouci.com/api/verify_payment/' . $payment_id;

            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'apppublic'     => $this->app_token,
                    'appsecret'     => $this->app_secret,
                ),
                'method'  => 'GET',
            ));

            if (is_wp_error($response)) {
                return false;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            return $data;
        }

        /*
         * Shortcode to handle Flouci payment verification
         */
        public function flouci_check_payment_shortcode() {
            if (isset($_GET['payment_id'])) {
                $payment_id = sanitize_text_field($_GET['payment_id']);

                // Verify the status of the Flouci transaction
                $transaction_status = $this->verify_flouci_payment_status($payment_id);
                if ($transaction_status && isset($transaction_status['success']) && $transaction_status['success'] === true) {
                    $order_id = $this->get_order_id_from_transaction($transaction_status);
                    $order = wc_get_order($order_id);

                    // Process payment completion
                    $this->complete_payment($order);
                } else {
                    // Handle payment failure
                    $this->handle_payment_failure($order_id);
                }
            }
            return;
        }

        // Helper function to extract order ID from transaction
        private function get_order_id_from_transaction($transaction_status) {
            $developer_tracking_id = $transaction_status['result']['developer_tracking_id'];
            return (int) substr($developer_tracking_id, strlen('ORDER'));
        }

        // Helper function to complete payment process
        private function complete_payment($order) {
            $order->payment_complete();
            $order->add_order_note(__('Payment completed successfully via Flouci.', 'flouci-payment-gateway'));
            wp_redirect($this->get_return_url($order));
            exit;
        }

        // Helper function to handle payment failure
        private function handle_payment_failure($order_id) {
            $order = wc_get_order($order_id);
            $order->update_status('failed', __('Payment failed via Flouci.', 'flouci-payment-gateway'));
            wp_redirect($order->get_cancel_order_url());
            exit;
        }
    }

    add_shortcode('flouci_check_payment', [new WC_Flouci_Payment_Gateway, 'flouci_check_payment_shortcode']);
}
