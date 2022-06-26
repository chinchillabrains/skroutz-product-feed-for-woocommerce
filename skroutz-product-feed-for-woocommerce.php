<?php
/**
 * Plugin Name: Skroutz Product Feed for WooCommerce
 * Description: Wordpress plugin that generates product feeds for skroutz.gr
 * Version: 0.1.0
 * Author: chinchillabrains
 * Requires at least: 5.0
 * Author URI: #
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
    if ( file_exists( CSPF_PLUGIN_DIR . '/vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	    require_once( CSPF_PLUGIN_DIR . '/vendor/woocommerce/action-scheduler/action-scheduler.php' );	
	}
    // Require error log
	if ( file_exists( CSPF_PLUGIN_DIR . '/vendor/chinchillabrains/chilla-error-log.php' ) ) {
		require_once( CSPF_PLUGIN_DIR . '/vendor/chinchillabrains/chilla-error-log.php' );	
	}


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

            add_action( 'chilla_generate_skroutz_feed', array( $this, 'generate_skroutz_feed' ) );

            add_action( 'chilla_skroutz_process_batch', array( $this, 'process_product_batch' ) );

            add_action( 'chilla_skroutz_finalize_feed', array( $this, 'finalize_feed' ) );

            add_filter( 'chilla_skroutz_product_skip', array( $this, 'filter_products_skip' ), 10, 1 );

//             add_action( 'admin_head', function () {
//                 if ( current_user_can( 'administrator' ) && isset( $_GET['skroutz'] ) && $_GET['skroutz'] == 'generate' ) {
//                     as_unschedule_all_actions( 'chilla_generate_skroutz_feed' );
//                     as_schedule_recurring_action( time()+2100, 21600, 'chilla_generate_skroutz_feed' );
//                 }
//             } );

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
        

        public function schedule_feed_export () {

        }


        public function stop_feed_export () {
            
        }

        public function generate_skroutz_feed () {
            // Make product id list
            $result = self::generate_skroutz_product_list();

            if ( ! $result ) {
                error_log( 'Skroutz feed generation failed' );
                return false;
            }


            // Add action to pull product batch
            as_enqueue_async_action( 'chilla_skroutz_process_batch' );

        }

        public function finalize_feed () {
            // Rename last feed
            rename( __DIR__ . '/feeds/skroutz.xml', __DIR__ . '/feeds/last_feed.xml' );

            // Get product data
            $product_data_file = fopen( __DIR__ . '/feeds/product-data.xml', 'r' );
            $product_data = fread( $product_data_file, filesize( __DIR__ . '/feeds/product-data.xml' ) );
            
            fclose( $product_data_file );
            // Save new feed
            $new_feed_file = fopen( __DIR__ . '/feeds/skroutz.xml', 'w' );

            fwrite( $new_feed_file, self::get_xml_head() );


            fwrite( $new_feed_file, $product_data );

            fwrite( $new_feed_file, self::get_xml_foot() );

            fclose( $new_feed_file );

            // Delete product-data.xml
            if ( file_exists( __DIR__ . '/feeds/product-data.xml' ) ) {
                unlink( __DIR__ . '/feeds/product-data.xml' );
            }
            if ( file_exists( __DIR__ . '/feeds/product-ids-list.csv' ) ) {
                unlink( __DIR__ . '/feeds/product-ids-list.csv' );
            }

        }

        public function process_product_batch () {
            // Get first 100 (or remaining) products from id list
            $product_ids_file = __DIR__ . '/feeds/product-ids-list.csv';
            $products_to_get = 100;

            $prod_ids_arr = array();
            $file_contents = file( $product_ids_file );
            $remaining_products = count( $file_contents );

            // Check if there are remaining products & finalize feed (Rename current feed to last_feed.xml & make new one)
            if ( $remaining_products === 0 ) {
                as_enqueue_async_action( 'chilla_skroutz_finalize_feed' );
                return true;
            }
            for ( $i = 0; $i < min( $products_to_get, $remaining_products); $i++ ) {
                $prod_id = $file_contents[ $i ];
                $prod_id = (int) $prod_id;
                array_push( $prod_ids_arr, $prod_id );
                // Remove ids from file
                unset( $file_contents[ $i ] );
            }
            file_put_contents( $product_ids_file, $file_contents );


            $product_data_file = fopen( __DIR__ . '/feeds/product-data.xml', 'a+' );
            // Get product data
            foreach ( $prod_ids_arr as $id ) {
                $product_xml_data = self::get_product_xml_data( $id );
                // Save product data to product-data.xml
                fwrite( $product_data_file, $product_xml_data );
            }
            fclose( $product_data_file );            

            // Enqueue next action
            as_enqueue_async_action( 'chilla_skroutz_process_batch' );
        }

        private static function get_product_xml_data ( $prod_id ) {

            $product = wc_get_product( $prod_id );
			
			if ( empty( $product ) ) {
				return '';
			}

            $sku = $product->get_sku();
            $title = $product->get_title();
            $url = urldecode( $product->get_permalink() );
            $image_id = $product->get_image_id();
            $image_url = wp_get_attachment_image_src( $image_id, 'large' );
            $image_url = ( is_array( $image_url ) ? $image_url[0] : '' );

			$manufacturer = $product->get_attribute( 'pa_brand' ) ;
			$size 				= $product->get_attribute( 'pa_size' ) ;
			$color 				= $product->get_attribute( 'pa_color' );
            $dimensions			= $product->get_attribute( 'pa_dimensions' );
            

            // Get category path
            $catlist = get_the_terms( $prod_id, 'product_cat' );
			if ( $catlist ) {
				$last_category = end( $catlist );

				foreach ( $catlist as $v => $k   ) {
					if ( ! $k->parent == '0' ){
						$last_category = $k ;
						break ;
					}
				}

				$ancestors = get_ancestors( $last_category->term_id, 'product_cat', 'taxonomy' );
				$categories_list = array();

				foreach ( $ancestors as $parent ) {
					$term = get_term_by('id', $parent, 'product_cat');
					array_push($categories_list, $term->name);
				}

				array_push($categories_list, $last_category->name);
				$product_cat_path = implode(' > ', $categories_list );
				$categories_id = $last_category->term_id;
			}

            $availability_txt = 'Παράδοση σε 4 - 10 ημέρες';
			$delivery_days = $product->get_meta( 'skroutz_delivery_days', true );
			if ( ! empty( $delivery_days ) && $delivery_days != 'Default' ) {
				$availability_txt = $delivery_days;
			}
			
            $price = $product->get_price();

            $write_data = array(
                'uid'    => array(
                    'content'   => $prod_id,
                    'cdata'     => false
                ),
                'mpn'    => array(
                    'content'   => $sku,
                    'cdata'     => false
                ),
                'name'    => array(
                    'content'   => $title,
                    'cdata'     => true
                ),
                'link'    => array(
                    'content'   => $url,
                    'cdata'     => true
                ),
                'image'    => array(
                    'content'   => $image_url,
                    'cdata'     => true
                ),
                'category'    => array(
                    'content'   => $product_cat_path,
                    'cdata'     => true
                ),
                'price_with_vat'    => array(
                    'content'   => $price,
                    'cdata'     => false
                ),
                'availability'    => array(
                    'content'   => $availability_txt,
                    'cdata'     => true
                ),
                'manufacturer'    => array(
                    'content'   => $manufacturer,
                    'cdata'     => true
                ),
                'size'    => array(
                    'content'   => $size,
                    'cdata'     => true
                ),
                'dimensions'    => array(
                    'content'   => $dimensions,
                    'cdata'     => true
                ),
                'color'    => array(
                    'content'   => $color,
                    'cdata'     => true
                ),
                'instock'    => array(
                    'content'   => 'Y',
                    'cdata'     => false
                )
            );

            $skip_product = false;
            $skip_product =  apply_filters( 'chilla_skroutz_product_skip' , $write_data );
            if ( $skip_product ) {
                return '';
            }

            $ret_str = '';
            if ( $price > 0 ) {
                $ret_str .= "<product>\n";
                foreach ( $write_data as $tag => $field ) {
                    $ret_str .=  self::make_xml_line( $tag, $field['content'], $field['cdata'] );
                }
                $ret_str .=  "</product>\n";
            }

            return $ret_str;
        }

        private static function get_xml_head () {
            $website_name = sanitize_text_field ( $_SERVER['SERVER_NAME'] );
            $website_name = str_replace( '.gr', '', $website_name );
            $website_name = str_replace( '.com', '', $website_name );

            $ret_str = '';
            $ret_str .= '<?xml version = "1.0" encoding = "UTF-8"?>';
            $ret_str .= "\n<" . $website_name . ">\n";
            $ret_str .= "<created_at>" . date( 'Y-m-d H:i:s', time() ) . "</created_at>\n";
            $ret_str .= "<products>\n";

            return $ret_str;
        }


        private static function get_xml_foot () {

            $website_name = sanitize_text_field ( $_SERVER['SERVER_NAME'] );
            $website_name = str_replace( '.gr', '', $website_name );
            $website_name = str_replace( '.com', '', $website_name );

            $ret_str = '';
            $ret_str .= "</products>\n";
            $ret_str .= "</" . $website_name . ">";

            return $ret_str;
        }

        private static function generate_skroutz_product_list () {

            // Default options
            $export_options = array(
                'filename'                      => 'skroutz.xml',   // Generated xml file name
                'front_facing_url'              => '',              // Url that needs redirecting to the file
                'stock'                         => 'instock',          // 'instock'|'both'
                'update_interval'               => 6*60*60,        // Interval (in seconds) to update product feed. Default 6 hours
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
            
            $products_list = self::get_product_ids_list( $export_options );

            if ( empty( $products_list ) ) {
                return false;
            }

            $product_ids_file = fopen( __DIR__ . '/feeds/product-ids-list.csv', 'w' );
            
            foreach ( $products_list as $prod_id ) {
                fwrite( $product_ids_file, $prod_id . "\n" );
            }
            fclose( $product_ids_file );

            return true;

        }

        // Queries the DB to fetch the products we want to export
        private static function get_product_ids_list ( $export_options ) {

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
                        'key' => '_stock_status',
                        'value' => 'instock'
                    ),
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
                'post_status'       => 'publish',
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


            if ( $result->have_posts() ) {
                $product_ids = $result->get_posts();
                return $product_ids;
            } else {
                return false;
            }

        }

        private static function make_xml_line ( $tag = '', $content = '', $cdata = false ) {
            $ret_str = "<" . $tag . ">";
            $ret_str .= ( $cdata ? '<![CDATA[' : '' );
            $ret_str .= $content;
            $ret_str .= ( $cdata ? ']]>' : '' );
            $ret_str .= "</" . $tag . ">\n";
            return $ret_str;
        }

        public static function filter_products_skip ( $prod_arr ) {
			$price = (float) $prod_arr['price_with_vat']['content'];
            if ( $price < 10 ) {
                $skip = true;
            } else {
                $skip = false;
            }
			
			return $skip;

        }

    }

    add_action( 'plugins_loaded', array( 'Chilla_Skroutz_Product_Feed', 'get_instance' ), 0 );

}