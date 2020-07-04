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

    var $baseurl;
    var $printfilevalidduration;

    public function __construct() {
//        $this->baseurl = "https://printimages.blackicetrading.com/printfiles/";
        $this->baseurl = "https://www.blackicetrading.com/printfiles/";
        $this->printfilevalidduration = "6 hours";
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

        // Add the Bulk Action for Manually Submit GF Orders.
        add_filter( 'bulk_actions-edit-shop_order', array( $this, 'bulk_action_menu' ) );
        add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'bulk_action_gf_submit' ), 10, 3 );
        add_action( 'admin_notices', array( $this, 'bulk_action_admin_notices' ) );

        // Print File Check for Variation Meta Data
        add_action( 'woocommerce_variation_options_pricing', array( $this, 'add_custom_field_to_variations'), 10, 3 );
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_custom_field_to_variatons'), 10, 2 );
    }

    /**
     * Captains Log
     */
    public function log_it( $level, $message ) {
        $logger = wc_get_logger();
        $context = array( 'source' => 'blackice-gf-submitter' );
        $logger->$level( $message, $context);
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
      $args = array(
          'status' => array( 'gf-errart', 'gf-errapi' ),
          'limit' => 5,
          'orderby' => 'date',
          'order' => 'ASC',
          'return' => 'ids',
       );
       $orders = wc_get_orders( $args );

       if ( count( $orders ) >0 ) {
           $links[] = '<p>Re-assign existing orders before Deactivating.</p>';
       }
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
    * Register WooCommerce Statuses for GF (Missing Artwork) and (Failed Submission)
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
    * For Each Status Missing & Failed, show Order Status in Admin and in the Dropdown on Single Order
    */
   public function show_order_status_admin_dropdown( $order_statuses ) {
       $order_statuses['wc-gf-errart'] = _x( 'GF (Missing Artwork)', 'Order status', 'woocommerce' );
       $order_statuses['wc-gf-errapi'] = _x( 'GF (Failed Submission)', 'Order status', 'woocommerce' );

       return $order_statuses;
   }


   /**
    * Add new Bulk Action to drop-down menus.
    */
   public function bulk_action_menu( $bulk_array ) {
       $bulk_array['act-man-gf-submit'] = __('Submit GF Order(s)', 'bit-gf-submitter');
       return $bulk_array;
   }

   /**
    * Handler for the New GF Submit Bulk Action.
    */
   public function bulk_action_gf_submit( $redirect, $doaction, $object_ids ) {

       // let's remote query ars first
       $redirect = remove_query_arg( array( 'act-man-gf-submit','gf_sub_success', 'gf_sub_missing', 'gf_sub_failed', ), $redirect );

       if ( $doaction == 'act-man-gf-submit' ) {
           $this->log_it( "info", "Performing Bulk Action for act-man-gf-submit" );
           $bulk_success_counter = 0;
           $bulk_missing_counter = 0;
           $bulk_failed_counter = 0;

           foreach( $object_ids as $order_id ) {
               $this->log_it( "debug", "Performing Bulk Action on order " . $order_id . "." );
               $order = wc_get_order( $order_id );

               $status = $this->submit_order( $order );
               if ( $status == "success") {
               $this->log_it( "debug", "Result: success" );
                   $bulk_success_counter++;
               } elseif ( $status == "missing" ) {
               $this->log_it( "debug", "Result: missing" );
                   $bulk_missing_counter++;
               } else {
               $this->log_it( "debug", "Result: failed" );
                   $bulk_failed_counter++;
               }
           }
           if ( $bulk_success_counter > 0 ) { $redirect = add_query_arg( array( 'gf_sub_success' => $bulk_success_counter ), $redirect ); };
           if ( $bulk_missing_counter > 0 ) { $redirect = add_query_arg( array( 'gf_sub_missing' => $bulk_missing_counter ), $redirect ); };
           if ( $bulk_failed_counter > 0 ) { $redirect = add_query_arg( array( 'gf_sub_failed' => $bulk_failed_counter ), $redirect ); };
       } // end if

       return $redirect;

   } // end function

   /**
    * Bulk Admin Notices
    */
   public function bulk_action_admin_notices() {
       if ( ! empty( $_REQUEST['gf_sub_success'] ) || ! empty( $_REQUEST['gf_sub_missing'] ) || ! empty( $_REQUEST['gf_sub_failed'] ) ) {
           $bulk_success_counter = isset( $_REQUEST['gf_sub_success']) ? $_REQUEST['gf_sub_success'] : 0;
           $bulk_missing_counter = isset( $_REQUEST['gf_sub_missing']) ? $_REQUEST['gf_sub_missing'] : 0;
           $bulk_failed_counter = isset( $_REQUEST['gf_sub_failed']) ? $_REQUEST['gf_sub_failed'] : 0;

           if ( $bulk_success_counter > 0 ) { printf( '<div id="message" class="updated notice notice-success is-dismissible"><p>' . _n( '%s order has been Successfully Submit to GiftFlow.', '%s orders have been Successfully Submit to GiftFlow', intval( $bulk_success_counter ) ) . '</p></div>', intval( $bulk_success_counter ) ); };
           if ( $bulk_missing_counter > 0 ) { printf( '<div id="message" class="notice notice-warning is-dismissible"><p>' . _n( '%s order has Missing Artwork and NOT Submit to GiftFlow.', '%s orders have Missing Artwork and NOT Submit to GiftFlow', intval( $bulk_missing_counter ) ) . '</p></div>', intval( $bulk_missing_counter ) ); };
           if ( $bulk_failed_counter > 0 ) { printf( '<div id="message" class="notice notice-error is-dismissible"><p>' . _n( '%s order has Failed Submission to GiftFlow.', '%s orders have Failed Submission to GiftFlow', intval( $bulk_failed_counter ) ) . '</p></div>', intval( $bulk_failed_counter ) ); };
       }
   } // end function

   /**
    * Display Print File Last Confirmed meta data on Variations
    */
   public function add_custom_field_to_variations( $loop, $variation_data, $variation ) {
       printf( '<div class="form-field form-row form-row-full custom-field">' );
       woocommerce_wp_text_input( array(
           'id' => 'printfilevalid[' . $loop . ']',
           'class' => 'short',
           'label' => __( 'Print File Last Confirmed', 'woocommerce' ),
           'value' => get_post_meta( $variation->ID, 'printfilevalid', true )
       ) );
       printf( '</div">' );
   }

   /**
    * Save Print File Last Confirmed meta data for Variations
    */
   public function save_custom_field_to_variatons( $variation_id, $i ) {
       $custom_field = $_POST['printfilevalid'][$i];
       if ( isset( $custom_field ) ) update_post_meta( $variation_id, 'printfilevalid', esc_attr( $custom_field ) );
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
       foreach ( $orders as $order_id ) {
           $this->log_it( "debug", "Performing GF Scheduled Action on order " . $order_id . "." );
           $order = wc_get_order( $order_id );
           $status = $this->submit_order( $order );
       }
   }

   /**
    * Process each order.
    */
   public function submit_order( $order ) {
       if ( ! $order ) {
           return;
       }

       $order_items = $order->get_items();

       $missingprintfiles = [];
       $errorsubmitting = "";
       foreach ( $order_items as $item_id => $item ) {
           $parent_id = "";
           $product = $item->get_product();
           $product_type = $product->get_type();
           switch ( $product_type ) {
               case 'simple':
                   $product_id = $product->get_id();
                   $product_name = $product->get_name();
                   $product_sku = $product->get_sku();
                   $productattrib = wc_get_product_terms( $product_id, 'pa_supplier', array( 'fields' => 'slugs' ) );
                   // check the meta data on the variation or product to see if the image has been marked as available.
                   $printfilechecked = $product->get_meta_data();
                   break;
               case 'variation':
                   $parent_id = $product->get_parent_id();
                   $product_id = $product->get_id();
                   $product_name = $product->get_name();
                   $product_sku = $product->get_sku();
                   $productattrib = wc_get_product_terms( $parent_id, 'pa_supplier', array( 'fields' => 'slugs' ) );
                   break;
               default:
                   $product_id = $product->get_id();
                   $product_name = $product->get_name();
                   $product_sku = $product->get_sku();
                   $productattrib = wc_get_product_terms( $product_id, 'pa_supplier', array( 'fields' => 'slugs' ) );
                   break;
           } // end switch
//           $this->log_it( "debug", "Product Title: " . implode(",", $product_titles ) );
           $this->log_it( "debug", "Product Title: " . $product_name );
           $this->log_it( "debug", "Product Type: " . $product_type );
           $this->log_it( "debug", "Product ID: " . $product_id );
           if ( $parent_id ) { $this->log_it( "debug", "Parent ID: " . $parent_id ); };
           $this->log_it( "debug", "Product SKU: " . $product_sku );

           $this->log_it( "debug", "Product Suppliers: " . implode(", ", $productattrib) );
           // check GiftFlow is a valid supplier for this item.
           if ( in_array( "gf", $productattrib) ) {
               $this->log_it( "debug", "GiftFlow is a Valid Supplier. Processing Item." );
               // Check the Print File is Valid.
               $printfileurl = $this->convert_sku_to_printfileurl( $product_sku  );
               $printfilevalid = $this->check_print_file_valid( $product_id, $printfileurl);
               if ( ! $printfilevalid ) {
                   $missingprintfiles[] = $printfileurl;
               }
           } else {
               $this->log_it( "debug", "NOT a GiftFlow Item. Skipping." );
           }

       }
       if ( count($missingprintfiles) > 0 ) {
           $this->log_it( "debug", count($missingprintfiles) . " Item(s) missing artwork.");
           $order->update_status ( "gf-errart", count($missingprintfiles) . " Item(s) missing artwork." );
           return "missing";
       }
       if ( $order->status == "gf-rexp") {
           return "success";
       } else {
           return "failed";
       }

   }

   /**
    * Convert SKU to printfile URL
    */
   public function convert_sku_to_printfileurl( $sku ) {

      $skuregex = "/^([a-zA-z]+[0-9]+)/i";
      preg_match( $skuregex, $sku, $matches );
      if ( $matches ) {
          $url = $this->baseurl . strtoupper($matches[1]) . "/" . strtoupper($sku) . ".png";
          $this->log_it( "debug", "SKU: " . $sku . " URL: " . $url );
          return $url;
      }
      return false;
   }

   /**
    * Check if printfile cached/valid.
    */
   public function check_print_file_valid( $product_id, $printfileurl ) {

       $printfilelastchecked = get_post_meta( $product_id, 'printfilevalid', true );
       $now = new DateTime();
       $metadate = strtotime($printfilelastchecked);
       $checkdate = new DateTime();
       $checkdate->setTimeStamp($metadate);
       $postmetaplustime = date_add($checkdate, date_interval_create_from_date_string ( $this->printfilevalidduration ) );
       $this->log_it( "debug", "Print File Meta: " . $printfilelastchecked . " Valid Until: " . $postmetaplustime->format('Y-m-d H:i:s') . " Now: " . $now->format('Y-m-d H:i:s') );
       if ( $now < $checkdate ) {
           $this->log_it( "debug", "Print File Checked in the last " . $this->printfilevalidduration . ". Skipping Check." );
           return true;
       } else {
           $datetime = date("Y-m-d H:i:s");
           $this->log_it( "debug", "Print File Expired. Checking it... " );
           $printfilevalid = $this->check_image_url( $printfileurl );
           if ( $printfilevalid ) {
               $this->log_it( "debug", "Print File Validated. Updating Meta Data Info. Valid for another " . $this->printfilevalidduration . "." );
               update_post_meta( $product_id, 'printfilevalid', $datetime );
               return true;
           } else {
               $this->log_it( "debug", "Print File NOT Valid." );
               return false;
           }
       }
       return false;
   }

   /**
    * Check image url valid
    */
   public function check_image_url( $url ) {
       // Remove all illegal characters from a url
       $url = filter_var($url, FILTER_SANITIZE_URL);

       // Validate url
       $this->log_it( "debug", "Validating Image URL: " . $url );
       if (filter_var($url, FILTER_VALIDATE_URL)) {
           $ch = curl_init();
           curl_setopt($ch, CURLOPT_URL,$url);
           // don't download content
           curl_setopt($ch, CURLOPT_NOBODY, 1);
           curl_setopt($ch, CURLOPT_FAILONERROR, 1);
           curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
           curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1)');

           $result = curl_exec($ch);
           curl_close($ch);
           if($result !== FALSE) {
               $this->log_it( "info", "Image URL Valid: " . $url );
               return true;
           } else {
               $this->log_it( "warning", "Image URL INVALID: " . $url );
               return false;
           }
       } else {
               $this->log_it( "error", "Error when processing Image URL: " . $url );
           return false;
       }
   }

 }
 $GLOBALS['BIT_GF_Submitter'] = new BIT_GF_Submitter();
 $GLOBALS['BIT_GF_Submitter']->init();
}
