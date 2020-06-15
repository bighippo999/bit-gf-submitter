<?php

/**
 * @wordpress-plugin
 * Plugin Name:       BlackIce GF Submitter
 * Plugin URI:        http://www.blackicetrading.com/plugin-bit-gf-submitter
 * Description:       Automatically submit GF orders via the GF API.
 * Version:           0.1
 * Author:            Dan
 * Author URI:        http://www.blackicetrading.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       bit-gf-submitter
 * WC requires at least: 4.0.0
 * WC tested up to:   4.2.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
        die;
}

if ( ! class_exists( 'BIT_GF_Submitter' ) ) {
 class BIT_GF_Submitter {

    public function __construct() {

    }

    public function init() {
        register_activation_hook( __FILE__, array( $this, 'plugin_activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'plugin_deactivate' ) );
        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__),  array( $this, 'plugin_deactivate_warning' ) );

        add_filter( 'woocommerce_register_shop_order_post_statuses', array( $this, 'register_woocommerce_statuses' ) );
        add_filter( 'woocommerce_reports_order_statuses', array( $this, 'woocommerce_report_statuses' ) );
        add_filter( 'wc_order_statuses', array( $this, 'show_order_status_admin_dropdown' ) );

        add_action( 'admin_enqueue_scripts', array( $this, 'plugin_styles' ), 99 );

        // register our hook for the scheduler to check the processing queue.
        add_action( 'bit_gf_submitter_schedule_event', array($this, 'check_processing_queue') );
    }

    /**
     * The code that runs during plugin activation.
     */
    public function plugin_activate() {
        if ( !class_exists( 'WooCommerce' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( __( 'Please install and Activate WooCommerce.', 'woocommerce-addon-slug' ), 'Plugin dependency check', array( 'back_link' => true ) );
        }
        $result = as_schedule_recurring_action( time()+300, 300, 'bit_gf_submitter_schedule_event', array(), "GF Order Submitter" );
    }

    /**
     * The code that runs during plugin deactivation.
     */
    public function plugin_deactivate() {
        $result = as_unschedule_all_actions( 'bit_gf_submitter_schedule_event', array(), "GF Order Submitter" );
    }

   /**
    * Add Deactrivation warning to Plugins Page.
    */
   public function plugin_deactivate_warning( $links ) {
       $links[] = '<p>Re-assign existing orders before Deactivating.</p>';
       return $links;
   }

   /**
    * Add the CSS for order status colours.
    */
   public function plugin_styles() {
       wp_enqueue_style( 'wc_bit_order_statuses', plugins_url('style.css', __FILE__) );
   }

   /**
    * Add new Statuses to WooCommerce Reports.
    */
   public function woocommerce_report_statuses( $order_status ) {
       // get the new statuses that we're registering.
       $registered_wc_statuses = $this->register_woocommerce_statuses(array());
       // strip the wc- and add them to the order_status that reports will look through.
       foreach( $registered_wc_statuses as $registered=>$value ) {
           $order_status[] = str_replace('wc-', '', $registered);
       }

       return $order_status;
   }

   /**
    * Register WooCommerce Statuses for each Supplier in Attributes>Suppliers
    */
   public function register_woocommerce_statuses( $order_statuses ) {
       $order_statuses['wc-gf-errart'] = array(
           'label'                     => _x( 'GF (Missing Artwork)', 'Order Status', 'woocommerce' ),
           'public'                    => false,
           'exclude_from_search'       => false,
           'show_in_admin_all_list'    => true,
           'show_in_admin_status_list' => true,
           'label_count'               => _n_noop( 'GF (Missing Artwork) <span class="count">(%s)</span>', 'GF (Missing Artwork) <span class="count">(%s)</span>', 'woocommerce' ),
       );
       $order_statuses['wc-gf-errapi'] = array(
           'label'                     => _x( 'GF (Failed Submission)', 'Order Status', 'woocommerce' ),
           'public'                    => false,
           'exclude_from_search'       => false,
           'show_in_admin_all_list'    => true,
           'show_in_admin_status_list' => true,
           'label_count'               => _n_noop( 'GF (Failed Submission) <span class="count">(%s)</span>', 'GF (Failed Submission) <span class="count">(%s)</span>', 'woocommerce' ),
       );

       return $order_statuses;
   }

   /**
    * For Each Supplier in Attributes>Suppliers show Order Status in Admin and in the Dropdown on Single Order
    */
   public function show_order_status_admin_dropdown( $order_statuses ) {
       $order_statuses['wc-gf-errart'] = _x( 'GF (Missing Artwork)', 'Order status', 'woocommerce' );
       $order_statuses['wc-gf-errapi'] = _x( 'GF (Failed Submission)', 'Order status', 'woocommerce' );

       return $order_statuses;
   }

   /**
    * Check the GF (Ready to Export) queue for orders and schedule to process each one.
    * This cleans up any orders left in processing. Limit to 5 at a time.
    */
   public function check_processing_queue() {
      $args = array(
          'status' => 'gf-rexp',
          'limit' => 5,
          'orderby' => 'date',
          'order' => 'ASC',
          'return' => 'ids',
       );
       $orders = wc_get_orders( $args );
       foreach ( $orders as $order ) {
           $this->submit_order( $order );
       }
   }

   /**
    * Process each order.
    */
   public function submit_order( $order ) {
       if ( ! $order ) {
           return;
       }

   }

 }
 $GLOBALS['BIT_GF_Submitter'] = new BIT_GF_Submitter();
 $GLOBALS['BIT_GF_Submitter']->init();
}
