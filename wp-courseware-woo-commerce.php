<?php
/*
 * Plugin Name: Woo Commmerce Addon for WP Courseware
 * Version: 1.0
 * Plugin URI: http://flyplugins.com
 * Description: The official extension for WP Courseware to add integration for WooCommmerce.
 * Author: Fly Plugins
 * Author URI: http://flyplugins.com
 */

// Main parent class
include_once 'class_members.inc.php';


// Hook to load the class
add_action('init', 'WPCW_Woo_init');

/**
 * Initialize the extension plugin, only loaded if WP Courseware 
 * exists and is loading correctly.
 */
function WPCW_Woo_init()
{
	$item = new WPCW_Woo();
	
	// Check for WP Courseware
	if (!$item->found_wpcourseware()) {
		$item->attach_showWPCWNotDetectedMessage();
		return;
	}
	
	// Not found Woo Commerce
	if (!$item->found_membershipTool()) {
		$item->attach_showToolNotDetectedMessage();
		return;
	}
	
	// Found the tool and WP Courseware, attach.
	$item->attachToTools();
}


/**
 * Class that handles the specifics of the WooCommerce plugin and
 * handling the data for products for that plugin.
 */
class WPCW_Woo extends WPCW_Members
{
	const GLUE_VERSION  = 1.00; 
	const EXTENSION_NAME = 'WooCommmerce';
	const EXTENSION_ID = 'WPCW_Woo';
	
	/**
	 * Main constructor for this class.
	 */
	function __construct()
	{
		// Initialize using the parent constructor 
		parent::__construct(WPCW_Woo::EXTENSION_NAME, WPCW_Woo::EXTENSION_ID, WPCW_Woo::GLUE_VERSION);
	}
	
	
	/**
	 * Get a list of WooCommerce produts.
	 */
	protected function getMembershipLevels()
	{
	
	$args=array(
  		'post_type' => 'product',
  		'post_status' => 'publish',
  		'numberposts' => -1
	);
	$levelData = get_posts($args);
	
		if ($levelData && count($levelData) > 0)
		{
			$levelDataStructured = array();
			
			// Format the data in a way that we expect and can process
			foreach ($levelData as $levelDatum)
			{
				
				$levelItem = array();
				$levelItem['name'] 	= $levelDatum->post_title;
				$levelItem['id'] 	= $levelDatum->ID;
				$levelItem['raw'] 	= $levelDatum;
				
					
				$levelDataStructured[$levelItem['id']] = $levelItem;
				
			}
			
			return $levelDataStructured;
		}
		
		return false;
	}
	
	
	/**
	 * Function called to attach hooks for handling when a user is updated or created.
	 */	
	protected function attach_updateUserCourseAccess()
	{
		// Events called whenever the user products are changed, which updates the user access.
		add_action('woocommerce_order_status_processing', 	array($this, 'handle_updateUserCourseAccess'),10,1);
		add_action('woocommerce_order_status_completed', 	array($this, 'handle_updateUserCourseAccess'),10,1);
	}

	/**
	 * Assign selected courses to members of a paticular product.
	 * @param Level ID in which members will get courses enrollment adjusted.
	 */
	protected function retroactive_assignment($level_ID)
    {
		global $wpdb;
		
		//Get all orders from WooCommerce with product ID ($Level_ID)
			$SQL = "SELECT order_id
			FROM {$wpdb->prefix}woocommerce_order_items AS o
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS i ON o.order_item_id = i.order_item_id
			WHERE meta_key = '_product_id' AND meta_value = $level_ID";

		$orders = $wpdb->get_results($SQL,ARRAY_A);

		$cust_ids = array();
		$products = array();

		if( count( $orders ) > 0 ) {
			
			foreach($orders as $key => $order){
				$cust_order = new WC_Order($order['order_id']);
				$cust_ids[] = $cust_order->customer_user;
			}
		}
		//clean up duplicate IDs
		$customer_id_per_product = array_unique($cust_ids);

		$page = new PageBuilder(false);

		//Enroll members of product
		if( count( $customer_id_per_product ) > 0 ) {
			foreach ($customer_id_per_product as $customer_id){

					$customer_orders = get_posts( array(
						'numberposts' => -1,
						'post_type'   => 'shop_order',
						'post_status' => array('wc-processing','wc-completed'),
						'fields'      => 'ids',
						'meta_query' => array(
							array(
								'key'     => '_customer_user',
								'value'   => $customer_id,
								'compare' => 'IN'
							)
						)
					) );
					// Fetch order ID's for customer
					foreach( $customer_orders as $customer_order ) {
						$cust_orders = wc_get_order( $customer_order );
						// Get product items from order and insert into array
						foreach( $cust_orders->get_items() as $item_id => $item ) {
							$product = $cust_orders->get_product_from_item( $item );
							$products[] = $product->id;
						}
					}
			//clean up duplicate IDs		
			$unique_products = array_unique($products);	
			// Over to the parent class to handle the sync of data.				
			parent::handle_courseSync($customer_id, $unique_products); 			    
			}
			$page->showMessage(__('All existing customers have been updated.', 'wp_courseware'));
			return;
		}else {
            $page->showMessage(__('No existing customers found for the specified product.', 'wp_courseware'));
        }

    }

	/**
	 * Function just for handling course enrollment.
	 *
	 */
	public function handle_updateUserCourseAccess($order_id)
	{
	 // Get order data
	 $order = new WC_Order($order_id);
	 // Get customer ID
	 $user = $order->customer_user;
	 $products = array();
	 //Query orders by customer ID
		$customer_orders = get_posts( array(
						'numberposts' => -1,
						'post_type'   => 'shop_order',
						'post_status' => array( 'wc-processing','wc-completed' ),
						'fields'      => 'ids',
						'meta_query' => array(
							array(
								'key'     => '_customer_user',
								'value'   => $user,
								'compare' => 'IN'
							)
						)
					) );
		// Fetch order ID's for customer
		foreach( $customer_orders as $customer_order ) {
			$cust_orders = wc_get_order( $customer_order );
			// Get product items from order and insert into array
			foreach( $cust_orders->get_items() as $item_id => $item ) {
				$product = $cust_orders->get_product_from_item( $item );
				$products[] = $product->id;
			}
		}
		//clean up duplicate IDs
		$unique_products = array_unique($products);	
		// Over to the parent class to handle the sync of data.	
		parent::handle_courseSync($user, $unique_products);

	}
		
	
	/**
	 * Detect presence of WooCommerce plugin.
	 */
	public function found_membershipTool()
	{
	     return class_exists( 'WooCommerce' );
	}
}
?>