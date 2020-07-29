<?php

/**
 * @wordpress-plugin
 * Plugin Name:       BlackIce GF Submitter
 * Plugin URI:        http://www.blackicetrading.com/plugin-bit-gf-submitter
 * Description:       Automatically submit GF orders via the GF API.
 * Version:           0.3
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
        $options = get_option( 'bit-gf-submitter_settings' );
        $this->core_task_scheduler = isset($options['task_scheduler']) ? $options['task_scheduler'] : false;
        $this->core_full_debug = isset($options['full_debug']) ? $options['full_debug'] : false;

        $this->api_busines_name = isset($options['api_business_name']) ? $options['api_business_name'] : '';
        $this->api_business_phone_number = isset($options['api_business_phone_number']) ? $options['api_business_phone_number'] : '';
        $this->api_business_email = isset($options['api_business_email']) ? $options['api_business_email'] : '';
        $this->api_order_prefix = isset($options['api_order_prefix']) ? $options['api_order_prefix'] : '';
        $this->api_key = isset($options['api_key']) ? $options['api_key'] : '';
        $this->api_secret = isset($options['api_secret']) ? $options['api_secret'] : '';
        $this->api_id = isset($options['api_id']) ? $options['api_id'] : '';
        $this->api_endpoint = isset($options['api_endpoint']) ? $options['api_endpoint'] : 'https://653222e384157ae04ba8fd79bb11068d.m.pipedream.net';

        $this->printfiles_url = isset($options['printfiles_url']) ? $options['printfiles_url'] : 'https://www.blackicetrading.com/printfiles/';
        $this->printfiles_cache_timeout = isset($options['printfiles_cache_timeout']) ? $options['printfiles_cache_timeout'] : '6 hours';

        $this->sku_lookup_convert_raw = isset($options['sku_lookup_convert']) ? $options['sku_lookup_convert'] : '';
        $this->sku_lookup_convert = $this->convert_sku_lookup_data_to_array( $this->sku_lookup_convert_raw );
    }

    public function convert_sku_lookup_data_to_array( $data ) {
        $separator = "\r\n";
        $line = strtok( $data, $separator );
        $skus = [];
        $skuregex = "/(.*) (.*)/im";
        while ( $line !== false ) {
            # do somthing
            preg_match( $skuregex, $line, $matches );
            if ( $matches ) {
                $skus[$matches[1]] = $matches[2];
            }
            $line = strtok( $separator );
        }
        return $skus;
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

        // register out settings_init to the admin_init action hook.
        add_action( 'admin_menu', array( $this, 'register_submenu_page' ), 60 );
        add_action( 'admin_init', array( $this, 'settings_init' ) );
    }

    /**
     * Submenu
     */
    public function register_submenu_page() {
        add_submenu_page( 'woocommerce', 'GF Submitter', 'GF Submitter', 'manage_options', 'gf_submitter-submenu-page', array( $this, 'options_page_cb' ), 60 );
    }

    /**
     * Submenu callback page generation
     */
    public function options_page_cb() {
        $scheduled_task = as_next_scheduled_action( 'bit_gf_submitter_schedule_event', array(), 'GF Order Submitter' );
        if ( $this->core_task_scheduler && ! $scheduled_task ) {
            $result = as_schedule_recurring_action( time()+120, 120, 'bit_gf_submitter_schedule_event', array(), "GF Order Submitter" );
        } elseif ( ! $this->core_task_scheduler ) {
            $result = as_unschedule_all_actions( 'bit_gf_submitter_schedule_event', array(), 'GF Order Submitter' );
        }
        ?>
        <form action='options.php' method='post'>
            <h2>GF Submitter Settings</h2>

            <?php
            settings_fields( 'bit_gf_pluginPage' );
            do_settings_sections( 'bit_gf_pluginPage' );
            submit_button();
            ?>
        </form>
        <?php
    }

    /**
     * settings page init
     */
    public function settings_init() {

        register_setting( 'bit_gf_pluginPage', 'bit-gf-submitter_settings' );

        add_settings_section(
            'bit_gf_core_section',
            __( 'Core Settings', 'bit-gf-submitter' ),
            array( $this, 'settings_core_section_cb' ),
            'bit_gf_pluginPage'
        );

        add_settings_field(
            'checkbox_task_scheduler',
            __( 'Enable Auto Submit', 'bit-gf-submitter' ),
            array( $this, 'gf_settings_task_scheduler' ),
            'bit_gf_pluginPage',
            'bit_gf_core_section'
        );

        add_settings_field(
            'checkbox_full_debug',
            __( 'Enable Full Debug', 'bit-gf-submitter' ),
            array( $this, 'gf_settings_full_debug' ),
            'bit_gf_pluginPage',
            'bit_gf_core_section'
        );

        add_settings_section(
            'bit_gf_api_section',
            __( 'GF API settings', 'bit-gf-submitter' ),
            array( $this, 'gf_api_section_cb' ),
            'bit_gf_pluginPage'
        );

        add_settings_field(
            'text_field_business_name',
            __( 'Business Name', 'bit-gf-submitter' ),
            array( $this, 'text_field_business_name_render' ),
            'bit_gf_pluginPage',
            'bit_gf_api_section'
        );

        add_settings_field(
            'text_field_business_phone_number',
            __( 'Business Phone Number', 'bit-gf-submitter' ),
            array( $this, 'text_field_business_phone_number_render' ),
            'bit_gf_pluginPage',
            'bit_gf_api_section'
        );

        add_settings_field(
            'text_field_business_email',
            __( 'Business Email', 'bit-gf-submitter' ),
            array( $this, 'text_field_business_email_render' ),
            'bit_gf_pluginPage',
            'bit_gf_api_section'
        );

        add_settings_field(
            'text_field_order_prefix',
            __( 'Order Prefix', 'bit-gf-submitter' ),
            array( $this, 'text_field_order_prefix_render' ),
            'bit_gf_pluginPage',
            'bit_gf_api_section'
        );

        add_settings_field(
            'text_field_api_key',
            __( 'API Key', 'bit-gf-submitter' ),
            array( $this, 'text_field_api_key_render' ),
            'bit_gf_pluginPage',
            'bit_gf_api_section'
        );

        add_settings_field(
            'text_field_api_secret',
            __( 'API Secret', 'bit-gf-submitter' ),
            array( $this, 'text_field_api_secret_render' ),
            'bit_gf_pluginPage',
            'bit_gf_api_section'
        );

        add_settings_field(
            'text_field_api_id',
            __( 'API ID', 'bit-gf-submitter' ),
            array( $this, 'text_field_api_id_render' ),
            'bit_gf_pluginPage',
            'bit_gf_api_section'
        );

        add_settings_field(
            'text_field_api_endpoint',
            __( 'API EndPoint', 'bit-gf-submitter' ),
            array( $this, 'text_field_api_endpoint_render' ),
            'bit_gf_pluginPage',
            'bit_gf_api_section'
        );

        add_settings_section(
            'bit_gf_printfiles_section',
            __( 'Print File settings', 'bit-gf-submitter' ),
            array( $this, 'printfile_settings_section_cb' ),
            'bit_gf_pluginPage'
        );

        add_settings_field(
            'text_field_printfiles_url',
            __( 'PrintFiles URL', 'bit-gf-submitter' ),
            array( $this, 'text_field_printfiles_url_render' ),
            'bit_gf_pluginPage',
            'bit_gf_printfiles_section'
        );

        add_settings_field(
            'text_field_printfiles_cache_timeout',
            __( 'Printfiles Cache Timeout', 'bit-gf-submitter' ),
            array( $this, 'text_field_printfiles_cache_timeout_render' ),
            'bit_gf_pluginPage',
            'bit_gf_printfiles_section'
        );

        add_settings_section(
            'bit_gf_sku_conversion_section',
            __( 'SKU Lookup/Convert', 'bit-gf-submitter' ),
            array( $this, 'sku_lookup_convert_section_cb' ),
            'bit_gf_pluginPage'
        );

        add_settings_field(
            'textarea_field_sku_lookup_convert',
            __( 'SKU\'s', 'bit-gf-submitter' ),
            array( $this, 'textarea_field_sku_lookup_convert_render' ),
            'bit_gf_pluginPage',
            'bit_gf_sku_conversion_section'
        );

    }

    /**
     * checkbox_field_task_scheduler
     */
    public function gf_settings_task_scheduler() {
        $result = as_next_scheduled_action( 'bit_gf_submitter_schedule_event', array(), 'GF Order Submitter' );
        ?>
        <input type='checkbox' name='bit-gf-submitter_settings[task_scheduler]' <?php if($result){echo "checked";}; ?>>(Every 2 Min)</input>
        <?php
    }

    /**
     * checkbox_field_full_debug
     */
    public function gf_settings_full_debug() {
        ?>
        <input type='checkbox' name='bit-gf-submitter_settings[full_debug]' <?php if ($this->core_full_debug){echo "checked";}; ?>></input>
        <?php
    }

    /**
     * text_field_business_name_render
     */
    public function text_field_business_name_render() {
        ?>
        <input type='text' name='bit-gf-submitter_settings[api_business_name]' value='<?php echo $this->api_busines_name; ?>'>
        <?php
    }

    /**
     * text_field_business_phone_number_render
     */
    public function text_field_business_phone_number_render() {
        ?>
        <input type='tel' name='bit-gf-submitter_settings[api_business_phone_number]' value='<?php echo $this->api_business_phone_number; ?>'>
        <?php
    }

    /**
     * text_field_business_email_render
     */
    public function text_field_business_email_render() {
        ?>
        <input type='email' name='bit-gf-submitter_settings[api_business_email]' value='<?php echo $this->api_business_email; ?>'>
        <?php
    }

    /**
     * text_field_order_prefix_render
     */
    public function text_field_order_prefix_render() {
        ?>
        <input type='text' name='bit-gf-submitter_settings[api_order_prefix]' value='<?php echo $this->api_order_prefix; ?>'>
        <?php
    }

    /**
     * text_field_api_key_render
     */
    public function text_field_api_key_render() {
        ?>
        <input type='text' name='bit-gf-submitter_settings[api_key]' value='<?php echo $this->api_key; ?>'>
        <?php
    }

    /**
     * text_field_api_secret_render
     */
    public function text_field_api_secret_render() {
        ?>
        <input type='text' name='bit-gf-submitter_settings[api_secret]' value='<?php echo $this->api_secret; ?>'>
        <?php
    }

    /**
     * text_field_api_id_render
     */
    public function text_field_api_id_render() {
        ?>
        <input type='text' name='bit-gf-submitter_settings[api_id]' value='<?php echo $this->api_id; ?>'>
        <?php
    }

    /**
     * text_field_api_endpoint_render
     */
    public function text_field_api_endpoint_render() {
        ?>
        <input type='url' name='bit-gf-submitter_settings[api_endpoint]' value='<?php echo $this->api_endpoint; ?>'>
        <?php
    }

    /**
     * text_field_printfiles_url_render
     */
    public function text_field_printfiles_url_render() {
        ?>
        <input type='url' name='bit-gf-submitter_settings[printfiles_url]' value='<?php echo $this->printfiles_url; ?>'>
        <br \>
        <?php
    }

    /**
     * text_field_printfiles_cache_timeout_render
     */
    public function text_field_printfiles_cache_timeout_render() {
        ?>
        <input type='text' name='bit-gf-submitter_settings[printfiles_cache_timeout]' value='<?php echo $this->printfiles_cache_timeout; ?>'>
        <?php
    }

    /**
     * textarea_field_sku_lookup_convert_render
     */
    public function textarea_field_sku_lookup_convert_render() {
        echo count($this->sku_lookup_convert) . ' Lookup(s) currently in array.';
        ?>
        <br />
        <textarea name='bit-gf-submitter_settings[sku_lookup_convert]' rows="15" cols="80"><?php echo $this->sku_lookup_convert_raw; ?></textarea>
        <?php
    }

    /**
     * settings_section_callback
     */
    public function gf_api_section_cb() {
        echo __( 'Settings obtained from GF. Make sure to fill in the correct details', 'bit-gf-submitter' );
    }

    /**
     * printfile_settings_section_callback
     */
    public function printfile_settings_section_cb() {
        echo __( 'Used to verify the Print File are available during submission', 'bit-gf-submitter' );
    }

    /**
     * settings_core_section_callback
     */
    public function settings_core_section_cb() {

    }

    /**
     * sku_lookup_convert_section_callback
     */
    public function sku_lookup_convert_section_cb() {
        echo __( 'One line per lookup/coversion. separated by a {space}. Case insensitive.', 'bit-gf-submitter' );
        echo "<br />";
        echo __( 'Warning: Partial matches should NOT exist! eg a lookup for SPC1AP11 and SPC1.', 'bit-gf-submitter' );
        echo "<br />";
        echo __( 'testing required on partial variations. SPC1AP11 and SPC1AP11MAX. I think the array keys will be specific and should only match whole sku.', 'bit-gf-submitter' );
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
        $result = as_schedule_recurring_action( time()+120, 120, 'bit_gf_submitter_schedule_event', array(), "GF Order Submitter" );
    }

    /**
     * The code that runs during plugin deactivation.
     */
    public function plugin_deactivate() {
        $result = as_unschedule_all_actions( 'bit_gf_submitter_schedule_event', array(), 'GF Order Submitter' );
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

       ksort( $order_statuses );
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
           if ( $this->core_full_debug ) { $this->log_it( "info", "Performing Manual Bulk Action for act-man-gf-submit" ); };
           $bulk_success_counter = 0;
           $bulk_missing_counter = 0;
           $bulk_failed_counter = 0;

           foreach( $object_ids as $order_id ) {
               $this->log_it( "debug", "Performing Bulk Action on order " . $order_id . "." );
               $order = wc_get_order( $order_id );

               $status = $this->submit_order( $order );
               if ( ! $status ) {
                   $this->log_it( "debug", "Result: Failed" );
                   $bulk_failed_counter++;
               } elseif ( $status == "success") {
                   $this->log_it( "debug", "Result: Success" );
                   $bulk_success_counter++;
               } elseif ( $status == "missing" ) {
                   $this->log_it( "debug", "Result: Missing" );
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
          'limit' => 10,
          'orderby' => 'date',
          'order' => 'ASC',
          'return' => 'ids',
       );
       $orders = wc_get_orders( $args );
       foreach ( $orders as $order_id ) {
           $this->log_it( "info", "Performing GF Scheduled Action on order " . $order_id . "." );
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

       if ( $order->get_status() == "gf-aimp" ) {
           $this->log_it( "debug", "Order is ALREADY GF (Awaiting Import. Aborting!");
           return false;
       }

       $submitting_items = [];

       $order_items = $order->get_items();
       $not_gf_items = 0;
       $is_gf_items = 0;
       $gf_note_check_quantity = False;

       $missingprintfiles = [];
       $missingproductcode = [];
       $errorsubmitting = "";
       foreach ( $order_items as $item_id => $item ) {
           $parent_id = "";
           $product = $item->get_product();
           $quantity = $item->get_quantity();
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
           if ( $this->core_full_debug ) { $this->log_it( "debug", "Product Title: " . $product_name ); };
           if ( $this->core_full_debug ) { $this->log_it( "debug", "Product Type: " . $product_type ); };
           if ( $this->core_full_debug ) { $this->log_it( "debug", "Product ID: " . $product_id ); };
           if ( $this->core_full_debug && $parent_id ) { $this->log_it( "debug", "Parent ID: " . $parent_id ); };
           if ( $this->core_full_debug ) { $this->log_it( "debug", "Product SKU: " . $product_sku ); };

           if ( $this->core_full_debug ) { $this->log_it( "debug", "Product Suppliers: " . implode(", ", $productattrib) ); };
           // check GiftFlow is a valid supplier for this item.
           if ( in_array( "gf", $productattrib) ) {
               if ( $this->core_full_debug ) { $this->log_it( "debug", "Valid GF Item. Processing..." ); };
               $product_code = $this->convert_sku_to_gf_product_code( $product_sku );
               if ( $product_code ) {
                   $is_gf_items++;
                   $skip_printfile_check = False;
                   // check if a skip printfile product.
                   $no_printfile_codes = array( "P-FILTERL", "P-FILTERS" );
                   $qty_multiplier_item_codes = array( "P-FILTERL", "P-FILTERS" );
                   if ( in_array($product_code, $no_printfile_codes)) {
                       $skip_printfile_check = True;
                   } else {
                       // Check the Print File is Valid.
                       $printfileurl = $this->convert_sku_to_printfileurl( $product_sku  );
                       $printfilevalid = $this->check_print_file_valid( $product_id, $printfileurl);
                   }
                   if ( $printfilevalid || $skip_printfile_check ) {
                       $submitting_this_item = [];
                       $submitting_this_item['productSku'] = $product_code;
                       $submitting_this_item['customerProductReference'] = $product_sku;
                       $submitting_this_item['customerProductName'] = $product_name;
                       $submitting_this_item['customerProductDescription'] = "";

                       // multiple the quantity for qty_multiplier_products.
                       if ( in_array($product_code, $qty_multiplier_item_codes) ) {
                           $skuregex = "/-([0-9]+)$/i";
                           preg_match( $skuregex, $product_sku, $matches );
                           if ( $matches ) {
                               $multiplier = $matches[1];
                               if ( $this->core_full_debug ) { $this->log_it( "debug", "Quantity Multipler: " . $multiplier . "." ); };
                               $multipled_quantity = $multiplier * $quantity;
                               if ( $this->core_full_debug ) { $this->log_it( "debug", "Proper Quantity: " . $multipled_quantity . "." ); };
                               $submitting_this_item['quantity'] = $multipled_quantity;
                           } else {
                               if ( $this->core_full_debug ) { $this->log_it( "debug", "Failed to Find Multiplier. Quantity: " . $quantity . "." ); };
                               $submitting_this_item['quantity'] = $quantity;
                           }
                       } else {
                           $submitting_this_item['quantity'] = $quantity;
                       }
                       if ( $quantity > 1 ) {
                           $gf_note_check_quantity = True;
                       };
                       $submitting_this_item['partNumber'] = $is_gf_items;
                       if ( ! $skip_printfile_check ) {
                           $submitting_this_item['itemAssetDetails'] = [];
                           $submitting_this_item['itemAssetDetails'][]['itemAssetUrl'] = $printfileurl;
                       };

                       $submitting_items[] = $submitting_this_item;
                       if ( $this->core_full_debug ) { $this->log_it( "debug", "Adding Item No. " . $is_gf_items . " to Items list." ); };
                   } else {
                       $missingprintfiles[] = $printfileurl;
                   } // end $printfilevalid
               } else {
                   $missingproductcode[] = $product_sku;
                   if ( $this->core_full_debug ) { $this->log_it( "error", "Unable to link sku to product code." ); };
               } // end $product_code
           } else {
               if ( $this->core_full_debug ) { $this->log_it( "debug", "NOT a GF Item. Skipping." ); };
               $not_gf_items++;
           }
       } // end foreach ( $order_items as $item_id => $item )

       // loop again to add the totalParts count.
       $total_items = count( $submitting_items );
       foreach ( $submitting_items as $k => $v ) {
           $submitting_items[$k]['totalParts'] = $total_items;
       }

       if ( count( $missingproductcode ) > 0 ) {
           $this->log_it( "error", count($missingproductcode) . " Item(s) unable to link to GF Product(s). Abort submission!");
           $order->update_status ( "gf-errapi", count($missingproductcode) . " Item(s) unable to link to GF Product(s)." );
           return "failed";
       } elseif ( count( $missingprintfiles ) > 0 ) {
           $this->log_it( "error", count($missingprintfiles) . " Item(s) missing artwork. Abort submission!");
           $order->update_status ( "gf-errart", count($missingprintfiles) . " Item(s) missing artwork." );
           return "missing";
       } elseif ( count( $submitting_items ) > 0 ) {
           if ( $not_gf_items == 0 ) {
               $this->log_it( "debug", "Total GF Items Submitting: " . $total_items  . " ...");
           } else {
               $this->log_it( "debug", "Total GF Items Submitting: " . $total_items . ", NOT GF Items: " . $not_gf_items . " ...");
           }

           $the_submission = [];
           $the_submission['resellerName'] = $this->api_busines_name;
           $the_submission['resellerPhoneNumber'] = $this->api_business_phone_number;
           $the_submission['resellerEmailAddress'] = $this->api_business_email;
           $the_submission['utcResellerOrderTimestampDateTime'] = $order->get_date_completed();
           $the_submission['resellerOrderNumber'] = $this->api_order_prefix . $order->get_order_number();

           $the_submission['customerName'] = $order->get_billing_first_name() . " " . $order->get_billing_last_name();
           $the_submission['customerCompanyName'] = $order->get_billing_company();
           $the_submission['customerAddressLineOne'] = $order->get_billing_address_1();
           $the_submission['customerAddressLineTwo'] = $order->get_billing_address_2();
           $the_submission['customerAddressTown'] = $order->get_billing_city();
           $the_submission['customerAddressCounty'] = $order->get_billing_state();
           $the_submission['customerAddressPostcode'] = $order->get_billing_postcode();
           $the_submission['customerAddressCountry'] = $order->get_billing_country();
           $the_submission['shippingTo'] = $order->get_shipping_first_name() . " " . $order->get_shipping_last_name();
           $the_submission['shippingCompanyName '] = $order->get_shipping_company();
           $the_submission['shippingAddressLineOne'] = $order->get_shipping_address_1();
           $the_submission['shippingAddressLineTwo'] = $order->get_shipping_address_2();
           $the_submission['shippingAddressTown'] = $order->get_shipping_city();
           $the_submission['shippingAddressCounty'] = $order->get_shipping_state();
           $the_submission['shippingAddressPostcode'] = $order->get_shipping_postcode();
           $the_submission['shippingAddressCountry'] = $order->get_shipping_country();
           $the_submission['shippingAddressCountryCode'] = $order->get_shipping_country();
           $the_submission['shippingMethodAlias'] = 'Cheapest option ;)';
//           $the_submission['testOrder'] = True;
           if ( $gf_note_check_quantity ) {
               $the_submission['notes'] = "Check QTY when packing.";
           } else {
               $the_submission['notes'] = "";
           }

           $the_submission['items'] = $submitting_items;

           $curl = curl_init();
           $url_endpoint = str_replace("{ID}", $this->api_id, $this->api_endpoint);
           if ( $this->core_full_debug ) { $this->log_it( "debug", "API_Endpoint: " . $url_endpoint ); };

           curl_setopt($curl, CURLOPT_URL, $url_endpoint);
           curl_setopt($curl, CURLOPT_HTTPHEADER, array(
               'Authorization: Basic ' . base64_encode( $this->api_key . ":" . $this->api_secret ),
//               'APIKEY: ' . $this->api_key,
//               'APISECRET: ' . $this->api_secret,
               'Content-Type: application/json',
           ));
           curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
           curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
           curl_setopt($curl, CURLOPT_POST, 1);
           curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($the_submission));
           $response_json = curl_exec($curl);
           $response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
           curl_close($curl);

           if ( !$response_json) {
               $this->log_it( "error", "Submission failed. Response Code: " . $response_code . " Response: " . $response_json );
               $order->update_status ( "gf-errapi", "Submission to GF failed. Response Code: " . $response_code . " Response: " . $response_json );
               return "failed";
           } elseif ( $response_code == 201 ) {
               $response = json_decode($response_json, true);
               $responseorderNumber = $response['orderNumber'];
               $responseID = $response['id'];
               $this->log_it( "debug", "Response orderNumber: " . $responseorderNumber . " ID: " .  $responseID);
               $order->add_order_note( "GF: " . $responseID );
               $order->update_status ( "gf-adis", "Order submit to GF. Items count: " . $total_items . " Order Number: " . $responseorderNumber );
               return "success";
           } else {
               $this->log_it( "error", "Submission failed. Response Code: " . $response_code . " Response: " . $response_json );
               $order->update_status ( "gf-errapi", "Submission to GF failed. Response Code: " . $response_code . " Response: " . $response_json );
               return "failed";
           }
       } else {
           // There's no items to submit to GF.
           $this->log_it( "error", "Order does not contain GF items." );
           $order->update_status ( "gf-errapi", "Order does not contain GF items." );
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
          $url = $this->printfiles_url . strtoupper($matches[1]) . "/" . strtoupper($sku) . ".png";
          if ( $this->core_full_debug ) { $this->log_it( "debug", "SKU: " . $sku . " URL: " . $url ); };
          return $url;
      }
      return false;
   }

   /**
    * Convert the SKU to GF Product Code
    */
   public function convert_sku_to_gf_product_code ($sku ) {
       $skuregex = "/^([a-zA-Z]+[0-9]+)(.*)/i";
       preg_match( $skuregex, $sku, $matches );
       $product_code = $this->sku_lookup_convert[$matches[2]];
       if ( $product_code ) {
           if ( $this->core_full_debug ) { $this->log_it( "debug", "SKU: " . $sku . " Product Code: " . $product_code ); };
           return $product_code;
       } else {
           $this->log_it( "warning", "SKU: " . $sku . " Has no corresponding Product Code!" );
           return false;
       }
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
       $postmetaplustime = date_add($checkdate, date_interval_create_from_date_string ( $this->printfiles_cache_timeout ) );
       if ( $this->core_full_debug ) { $this->log_it( "debug", "Print File Meta: " . $printfilelastchecked . " Valid Until: " . $postmetaplustime->format('Y-m-d H:i:s') . " Now: " . $now->format('Y-m-d H:i:s') ); };
       if ( $now < $checkdate ) {
           $this->log_it( "debug", "Print File Checked in the last " . $this->printfiles_cache_timeout . ". Skipping Check." );
           return true;
       } else {
           $datetime = date("Y-m-d H:i:s");
           if ( $this->core_full_debug ) { $this->log_it( "debug", "Print File Expired. Checking it... " ); };
           $printfilevalid = $this->check_image_url( $printfileurl );
           if ( $printfilevalid ) {
               $this->log_it( "debug", "Print File Validated. Updating Meta Data Info. Valid for another " . $this->printfiles_cache_timeout . "." );
               update_post_meta( $product_id, 'printfilevalid', $datetime );
               return true;
           } else {
               $this->log_it( "debug", "Print File NOT Valid. URL: " . $printfileurl );
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
       if ( $this->core_full_debug ) { $this->log_it( "debug", "Validating Image URL: " . $url ); };
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
               if ( $this->core_full_debug ) { $this->log_it( "info", "Image URL Valid: " . $url ); };
               return true;
           } else {
               if ( $this->core_full_debug ) { $this->log_it( "warning", "Image URL INVALID: " . $url ); };
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
