<?php
/**
 * Plugin Name: Skroutz Product Feed for WooCommerce
 * Description: Wordpress plugin that generates product feeds for skroutz.gr
 * Version: 0.1.0
 * Author: chinchillabrains
 * Requires at least: 5.0
 * Author URI: https://chinchillabrains.com
 * Text Domain: skroutz-product-feed-for-woocommerce
 * Domain Path: /languages/
 * WC tested up to: 4.1
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


if ( ! class_exists( 'Chilla_Skroutz_Product_Feed' ) ) {

    define( 'CSPF_PLUGIN_DIR', __DIR__ );

    //Require action scheduler
    require_once( CSPF_PLUGIN_DIR . '/vendor/woocommerce/action-scheduler/action-scheduler.php' );
    // Require error log
    require_once( CSPF_PLUGIN_DIR . '/vendor/chinchillabrains/chilla-error-log.php' );


    class Chilla_Skroutz_Product_Feed {

        // Instance of this class.
        protected static $instance = null;

        public function __construct() {
            if ( ! class_exists( 'WooCommerce' ) ) {
                return;
            }


            // Load translation files
            add_action( 'init', array( $this, 'add_translation_files' ) );

            // Admin page
            // add_action('admin_menu', array( $this, 'setup_menu' ));


            // Add settings link to plugins page
            // add_filter( 'plugin_action_links_'.plugin_basename(__FILE__), array( $this, 'add_settings_link' ) );

            // Register plugin settings fields
            // register_setting( 'cspf_settings', 'cspf_email_message', array('sanitize_callback' => array( 'Chilla_Skroutz_Product_Feed', 'cspf_sanitize_code' ) ) );

        }


        public static function cspf_sanitize_code ( $input ) {        
            $sanitized = wp_kses_post( $input );
            if ( isset( $sanitized ) ) {
                return $sanitized;
            } else {
                return '';
            }
        }

        public function add_translation_files () {
            load_plugin_textdomain( 'skroutz-product-feed-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        }

        public function setup_menu () {
            add_management_page(
                __( 'Skroutz Product Feed', 'skroutz-product-feed-for-woocommerce' ),
                __( 'Skroutz Product Feed', 'skroutz-product-feed-for-woocommerce' ),
                'manage_options',
                'cspf_settings_page',
                array( $this, 'admin_panel_page' )
            );
        }

        public function admin_panel_page (){
            require_once( __DIR__ . '/skroutz-product-feed-for-woocommerce.admin.php' );
        }

        public function add_settings_link ( $links ) {
            $links[] = '<a href="' . admin_url( 'tools.php?page=cspf_settings_page' ) . '">' . __('Settings') . '</a>';
            return $links;
        }

        // Return an instance of this class.
		public static function get_instance () {
			// If the single instance hasn't been set, set it now.
			if ( self::$instance == null ) {
				self::$instance = new self;
			}

			return self::$instance;
        }
        

        public function generate_skroutz_feed () {

            $products_list = self::make_the_product_list();


            
        }

        public function schedule_feed_export () {

        }


        public function stop_feed_export () {
            
        }

        // Queries the DB to fetch the products we want to export
        private static function make_the_product_list () {

            // Default options
            $export_options = array(
                'filename'                      => 'skroutz.xml',   // Generated xml file name
                'front_facing_url'              => '',              // Url that needs redirecting to the file
                'stock'                         => 'both',          // 'instock'|'both'
                'update_interval'               => 12*60*60,        // Interval (in seconds) to update product feed. Default 12 hours
                // 'add_products_with_0_price'     => false,           // Whether to include products with 0 price
                'add_products_with_no_image'    => false,           // Whether to include products with missing images
                'exclude_categories'            => false,           // Whether to exclude some product categories
                'excluded_categories'           => array(),         // Product categories to exclude
                // 'exclude_attributes'            => false,           // Whether to exclude some products with certain attribute value
                // 'excluded_attribute_taxonomies' => array(),         // Product attribute taxonomies to exclude
                // 'excluded_attribute_terms'      => array(),         // Product attribute terms to exclude
                'export_selected_categories'    => false,           // Whether to export selected categories only
                'selected_categories'           => array()          // Product categories to export
            );
            // Get options from DB

            // $export_options = apply_filters( 'cspf_export_options', $export_options );

            // Build Query

            $tax_query = array();
            if ( $export_options['exclude_categories'] === true && ! empty( $export_options['excluded_categories'] ) ) {
                $cat_ids_to_exclude = array();
                foreach ( $export_options['excluded_categories'] as $cat_id ) {
                    array_push( $cat_ids_to_exclude, $cat_id );
                }
                $tax_query = array(
                    'relation'  => 'AND',
                    'taxonomy'  => 'product_cat',
                    'field'     => 'term_id',
                    'operator'  => 'NOT IN',
                    'terms'     => $cat_ids_to_exclude
                );
                foreach ( $export_options['excluded_categories'] as $cat_id ) {
                    array_push( $tax_query['terms'], $cat_id );
                }
            } elseif ( $export_options['export_selected_categories'] === true && ! empty( $export_options['selected_categories'] ) ) {
                $cat_ids_to_add = array();
                foreach ( $export_options['selected_categories'] as $cat_id ) {
                    array_push( $cat_ids_to_add, $cat_id );
                }
                $tax_query = array(
                    'relation'  => 'AND',
                    array(
                        'taxonomy'  => 'product_cat',
                        'field'     => 'term_id',
                        'operator'  => 'IN',
                        'terms'     => $cat_ids_to_add
                    )
                );
            }
            
            $meta_query = array();
            if ( $export_options['add_products_with_no_image'] === false ) {
                $meta_query = array(
                    array(
                        'key' => '_thumbnail_id',
                        'value' => '0',
                        'compare' => '>'
                    )
                );
            }
            
            $main_query = array(
                'posts_per_page'    => -1,
                'post_type'         => 'product',
                'fields'            => 'ids'
            );
            if ( ! empty( $tax_query ) ) {
                $main_query['tax_query'] = $tax_query;
            }
            if ( ! empty( $meta_query ) ) {
                $main_query['meta_query'] = $meta_query;
            }
            // Get product list
            wp_reset_query();
            $result = new WP_Query( $main_query );
            wp_reset_query();

            // Check performance with 1) Querying only IDs & getting the rest info
            //                        2) Querying all the info we can from the start

        }

    }

    add_action( 'plugins_loaded', array( 'Chilla_Skroutz_Product_Feed', 'get_instance' ), 0 );

}