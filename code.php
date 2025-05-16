<?php
/**
 * Plugin Name: WooCommerce PayPal.me Gateway
 * Plugin URI: https://yourwebsite.com/
 * Description: Adds PayPal.me as a payment gateway to WooCommerce with account rotation based on order amount.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * WC requires at least: 3.0
 * WC tested up to: 8.0 
 * Text Domain: wc-paypal-me-gateway
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// 检查 WooCommerce 是否已激活
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'wc_paypal_me_missing_wc_notice');
    return;
}

function wc_paypal_me_missing_wc_notice() {
    echo '<div class="error"><p><strong>WooCommerce PayPal.me Gateway</strong> ' .
        esc_html__('requires WooCommerce to be installed and active.', 'wc-paypal-me-gateway') .
        '</p></div>';
}

add_action('plugins_loaded', 'wc_paypal_me_gateway_init', 11);

function wc_paypal_me_gateway_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Paypal_Me extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                 = 'paypal_me';
            $this->icon               = apply_filters('woocommerce_paypal_me_icon', plugins_url('assets/paypalme_logo.png', __FILE__)); // 你需要准备一个logo图片
            $this->has_fields         = false; //  设置为false，因为我们不在结账时显示额外字段
            $this->method_title       = __('PayPal.me', 'wc-paypal-me-gateway');
            $this->method_description = __('Allows payments using PayPal.me links. Payment confirmation is manual.', 'wc-paypal-me-gateway');
            $this->order_button_text  = __('Proceed to PayPal.me', 'wc-paypal-me-gateway');

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables.
            $this->title        = $this->get_option('title');
            $this->description  = $this->get_option('description');
            $this->instructions = $this->get_option('instructions');
            $this->account_rules_raw = $this->get_option('account_rules');
            $this->fallback_account = $this->get_option('fallback_account');

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page_instructions'));

            // Customer Emails
            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        }

        /**
         * Initialize Gateway Settings Form Fields.
         */
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'wc-paypal-me-gateway'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable PayPal.me Payment', 'wc-paypal-me-gateway'),
                    'default' => 'no',
                ),
                'title' => array(
                    'title'       => __('Title', 'wc-paypal-me-gateway'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'wc-paypal-me-gateway'),
                    'default'     => __('PayPal.me', 'wc-paypal-me-gateway'),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __('Description', 'wc-paypal-me-gateway'),
                    'type'        => 'textarea',
                    'description' => __('Payment method description that the customer will see on your checkout.', 'wc-paypal-me-gateway'),
                    'default'     => __('Pay via PayPal.me. You will be redirected to PayPal.me to complete your purchase. Please ensure you pay the exact amount and include your Order ID in the payment note.', 'wc-paypal-me-gateway'),
                    'desc_tip'    => true,
                ),
                'instructions' => array(
                    'title'       => __('Instructions', 'wc-paypal-me-gateway'),
                    'type'        => 'textarea',
                    'description' => __('Instructions that will be added to the thank you page and emails.', 'wc-paypal-me-gateway'),
                    'default'     => __('Thank you for your order. Please click the link above to pay via PayPal.me. Remember to include your Order ID in the payment notes for faster processing. We will process your order once payment is confirmed.', 'wc-paypal-me-gateway'),
                    'desc_tip'    => true,
                ),
                'account_rules' => array(
                    'title'       => __('PayPal.me Account Rules', 'wc-paypal-me-gateway'),
                    'type'        => 'textarea',
                    'description' => __('Enter one rule per line. Format: `paypal_username:min_amount:max_amount`. Example: `myuser1:0:99.99` for orders up to 99.99, `myuser2:100:499.99` for orders from 100 to 499.99. Use `0` for max_amount if no upper limit for that rule. Ensure ranges do not overlap excessively or make logical sense.', 'wc-paypal-me-gateway'),
                    'default'     => "your_paypal_user1:0:100\nyour_paypal_user2:100.01:500\nyour_paypal_user3:500.01:0", // Example
                    'css'         => 'width:400px; height: 150px;',
                ),
                'fallback_account' => array(
                    'title'       => __('Fallback PayPal.me Username', 'wc-paypal-me-gateway'),
                    'type'        => 'text',
                    'description' => __('If no rules match, this PayPal.me username will be used. Leave empty to show an error if no rules match.', 'wc-paypal-me-gateway'),
                    'default'     => 'your_default_paypal_user',
                    'desc_tip'    => true,
                ),
            );
        }

        /**
         * Select PayPal.me account based on rules.
         */
        protected function select_paypal_account($amount, $currency) {
            $selected_account = null;
            $rules_raw = trim($this->get_option('account_rules'));
            
            if (empty($rules_raw) && !empty($this->get_option('fallback_account'))) {
                 return $this->get_option('fallback_account');
            }
            if (empty($rules_raw) && empty($this->get_option('fallback_account'))) {
                wc_get_logger()->error('PayPal.me: No account rules or fallback account configured.', array('source' => 'paypal_me'));
                return null;
            }

            $rules = explode("\n", $rules_raw);
            $parsed_rules = [];

            foreach ($rules as $rule_line) {
                $rule_line = trim($rule_line);
                if (empty($rule_line)) continue;

                $parts = explode(':', $rule_line);
                if (count($parts) === 3) {
                    $username = trim($parts[0]);
                    $min = floatval(trim($parts[1]));
                    $max = floatval(trim($parts[2])); // 0 means no upper limit

                    if (!empty($username)) {
                        $parsed_rules[] = [
                            'username' => $username,
                            'min' => $min,
                            'max' => ($max == 0) ? PHP_FLOAT_MAX : $max // Handle 0 as effectively infinite
                        ];
                    }
                }
            }
            
            // Sort rules by min_amount to ensure correct matching for overlapping ranges (though ideally they shouldn't overlap much)
            // This isn't strictly necessary if users define non-overlapping ranges, but can help.
            // usort($parsed_rules, function($a, $b) {
            //     return $a['min'] <=> $b['min'];
            // });

            foreach ($parsed_rules as $rule) {
                if ($amount >= $rule['min'] && $amount <= $rule['max']) {
                    $selected_account = $rule['username'];
                    break;
                }
            }

            if (!$selected_account && !empty($this->get_option('fallback_account'))) {
                $selected_account = $this->get_option('fallback_account');
            }
            
            if (!$selected_account) {
                 wc_get_logger()->warning(sprintf('PayPal.me: No matching account rule found for amount %s %s and no fallback account set.', $amount, $currency), array('source' => 'paypal_me'));
            }

            return $selected_account;
        }

        /**
         * Process the payment and return the result.
         */
        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice(__('Order not found.', 'wc-paypal-me-gateway'), 'error');
                return array(
                    'result'   => 'failure',
                    'redirect' => wc_get_checkout_url(),
                );
            }

            $amount   = $order->get_total();
            $currency = $order->get_currency();
            
            $paypal_username = $this->select_paypal_account($amount, $currency);

            if (!$paypal_username) {
                $error_message = __('Could not determine PayPal.me account for this order amount. Please contact support.', 'wc-paypal-me-gateway');
                $order->add_order_note($error_message . ' ' . __('Admin: Check PayPal.me gateway settings for account rules.', 'wc-paypal-me-gateway'));
                wc_add_notice($error_message, 'error');
                return array(
                    'result'   => 'failure',
                    'redirect' => wc_get_checkout_url(),
                );
            }

            $paypal_me_link = sprintf('https://paypal.me/%s/%s/%s', 
                urlencode($paypal_username), 
                urlencode(number_format($amount, 2, '.', '')), // Ensure correct decimal format
                urlencode($currency)
            );

            // Store the PayPal.me link and account in order meta for thank you page and emails
            $order->update_meta_data('_paypal_me_link', $paypal_me_link);
            $order->update_meta_data('_paypal_me_username', $paypal_username);
            $order->save_meta_data();

            // Mark as on-hold (we're awaiting the payment)
            $order->update_status('on-hold', __('Awaiting PayPal.me payment. Link: ', 'wc-paypal-me-gateway') . $paypal_me_link);

            // Reduce stock levels
            wc_reduce_stock_levels($order_id);

            // Remove cart
            WC()->cart->empty_cart();

            // Return thankyou redirect
            return array(
                'result'   => 'success',
                'redirect' => $paypal_me_link, // Direct redirect to PayPal.me
                // Alternatively, redirect to WC thank you page and display link there
                // 'redirect' => $this->get_return_url($order) 
            );
        }

        /**
         * Output for the order received page.
         */
        public function thankyou_page_instructions($order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_payment_method() === $this->id) {
                $paypal_me_link = $order->get_meta('_paypal_me_link');
                $instructions = $this->get_option('instructions');

                if (!empty($instructions)) {
                    echo wp_kses_post(wpautop(wptexturize($instructions)));
                }
                if ($paypal_me_link) {
                    echo '<h4>' . esc_html__('Payment Link:', 'wc-paypal-me-gateway') . '</h4>';
                    echo '<p><a href="' . esc_url($paypal_me_link) . '" target="_blank" class="button">' . 
                         esc_html__('Pay with PayPal.me', 'wc-paypal-me-gateway') . '</a></p>';
                    echo '<p>' . esc_html__('Please remember to include your Order ID', 'wc-paypal-me-gateway') .
                         ' (<strong>#' . esc_html($order->get_order_number()) . '</strong>) ' .
                         esc_html__('in the PayPal payment notes.', 'wc-paypal-me-gateway') . '</p>';
                }
            }
        }

        /**
         * Add content to the WC emails.
         *
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions($order, $sent_to_admin, $plain_text = false) {
            if (!$sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status('on-hold')) {
                $paypal_me_link = $order->get_meta('_paypal_me_link');
                $instructions = $this->get_option('instructions');

                if ($plain_text) {
                    echo esc_html(wp_strip_all_tags(wptexturize($instructions))) . "\n\n";
                    if ($paypal_me_link) {
                         echo esc_html__('Payment Link:', 'wc-paypal-me-gateway') . ' ' . esc_url($paypal_me_link) . "\n";
                         echo esc_html__('Please remember to include your Order ID', 'wc-paypal-me-gateway') .
                         ' (#' . esc_html($order->get_order_number()) . ') ' .
                         esc_html__('in the PayPal payment notes.', 'wc-paypal-me-gateway') . "\n\n";
                    }
                } else {
                    echo wp_kses_post(wpautop(wptexturize($instructions)));
                    if ($paypal_me_link) {
                        echo '<h4>' . esc_html__('Payment Link:', 'wc-paypal-me-gateway') . '</h4>';
                        echo '<p><a href="' . esc_url($paypal_me_link) . '" target="_blank" style="font-weight:bold; padding: 10px 15px; background-color: #0070ba; color: white; text-decoration: none; border-radius: 5px;">' . 
                             esc_html__('Pay with PayPal.me', 'wc-paypal-me-gateway') . '</a></p>';
                        echo '<p>' . esc_html__('Please remember to include your Order ID', 'wc-paypal-me-gateway') .
                             ' (<strong>#' . esc_html($order->get_order_number()) . '</strong>) ' .
                             esc_html__('in the PayPal payment notes.', 'wc-paypal-me-gateway') . '</p>';
                    }
                }
            }
        }
        
        /**
         * Check if the gateway is available for use.
         * Ensure currency is supported by PayPal (most major ones are).
         */
        public function is_available() {
            if ( ! parent::is_available() ) {
                return false;
            }

            // You might want to add currency checks if PayPal.me has limitations
            // $supported_currencies = array( 'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'JPY' ... );
            // if ( ! in_array( get_woocommerce_currency(), $supported_currencies ) ) {
            //    return false;
            // }

            // Check if any accounts are configured
            if (empty(trim($this->get_option('account_rules'))) && empty(trim($this->get_option('fallback_account')))) {
                return false;
            }

            return true;
        }
    } // End class
}

/**
 * Add the Gateway to WooCommerce
 **/
function add_wc_paypal_me_gateway($methods) {
    $methods[] = 'WC_Gateway_Paypal_Me';
    return $methods;
}
add_filter('woocommerce_payment_gateways', 'add_wc_paypal_me_gateway');


/**
 * Plugin activation hook to create a default logo if it doesn't exist
 */
function wc_paypal_me_activate() {
    $upload_dir = wp_upload_dir();
    $plugin_dir = plugin_dir_path(__FILE__);
    $logo_source = $plugin_dir . 'assets/paypalme_logo.png'; // Ensure you have this image
    $logo_dest_dir = $plugin_dir . 'assets/'; // Or a public dir if you prefer `plugins_url` to work correctly
    
    if (!file_exists($logo_dest_dir)) {
        wp_mkdir_p($logo_dest_dir);
    }

    // A simple way to ensure the logo is present for the plugin icon.
    // If you have a logo in your plugin's assets folder, `plugins_url('assets/paypalme_logo.png', __FILE__)` should work directly.
    // This activation step is often for more complex setup tasks.
}
register_activation_hook(__FILE__, 'wc_paypal_me_activate');

/**
 * Add custom links to plugin actions.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_paypal_me_plugin_action_links');
function wc_paypal_me_plugin_action_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=paypal_me') . '">' . __('Settings', 'wc-paypal-me-gateway') . '</a>',
    );
    return array_merge($plugin_links, $links);
}

/**
 * Load plugin textdomain.
 */
function wc_paypal_me_load_textdomain() {
    load_plugin_textdomain( 'wc-paypal-me-gateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' ); 
}
add_action( 'init', 'wc_paypal_me_load_textdomain' );