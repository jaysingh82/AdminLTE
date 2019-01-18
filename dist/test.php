<?php
/**
 * Order Manager
 *
 * PD-1012 OK (register globals)
 * PD-1026 OK (magic quotes)
 */

$page = "order_manager";
require_once('include/workaround_for_magic_quotes.php');
require_once('include/workaround_for_register_globals.php');

require_once("include/mysql_connect.inc");
require_once("include/common.inc");
require_once("include/security.inc");
require_once("include/search_results_functions.inc");
require_once("modules/brand_access/functions.inc");
require_once('log/logger.php');

require_once('lib/memory_management.php');
MemoryManagement::setMemoryLimit('256M');

$can_view_locked_orders = $user_account->has_permission('Order Manager - View Only Locked Orders');
if ($can_view_locked_orders) {
	$show_locked_orders_only = !$user_account->has_permission('Order Manager');
}
$user_account->manager_by_filename_accessible();

$current_page = "order_manager.php3";
$tab = 'order_processing';
$order_per_page_options = array(20, 50, 75, 100, 200, 300, 500);

if(isset($new_offset))
	$offset = $new_offset;

$orders_per_page = CORESenseEntity::call_static_class_function_from_type('SystemSetting', 'get_system_setting_value', 'Default Orders Per Page');

if ($orders_per_page > 0) {
	$limit = $orders_per_page;
} else {
	$limit = $order_per_page_options[0];
}
if(isset($new_limit))
	$limit = $new_limit;

if($offset == '' || !isset($offset))
	$offset = 0;

if ($action=='search'){
	$variable = array();
	if (!$_REQUEST["order_search_category"]){
		$_REQUEST["order_search_category"]="";
		$order_search_category="";
		array_push($variable,"order_search_category");
	}
	//Clean up product search variables if not product_order_search_type
	if (!$product_order_search_type){
		$variable = array();
		foreach($GLOBALS as $key => $value){
			if (strstr($key,"product_type_order_search_")){
				$$key="";
				array_push($variable,$key);
			}
		}
	}
	
	if (!empty($_REQUEST['order_search_inv'])) {
		$_REQUEST['reset_vars'] = true;
		foreach($variable as $single_key => $single_var) {
			if(($single_var != 'order_search_inv') && ($single_var != 'action')) {
				${$variable[$single_key]} = '';
			}
        }
	}

	if (count($variable)){
		require("include/get_last_vars.inc");
	}
	if (!isset($_REQUEST['use_start_date'])) {
		$_REQUEST['use_start_date']="";
		$_REQUEST['start']="";
		array_push($variable,"use_start_date");
		array_push($variable,"start");
	}
	if (!isset($_REQUEST['use_end_date'])){
		$_REQUEST['use_end_date']="";
		$_REQUEST['finish']="";
		array_push($variable,"use_end_date");
		array_push($variable,"finish");
	}
	if (!isset($_REQUEST['use_delayed_delivery_start_date'])) {
		$_REQUEST['use_delayed_delivery_start_date']="";
		$_REQUEST['delayed_delivery_start']="";
		array_push($variable,"use_delayed_delivery_start_date");
		array_push($variable,"delayed_delivery_start");
	}
	if (!isset($_REQUEST['use_delayed_delivery_end_date'])){
		$_REQUEST['use_delayed_delivery_end_date']="";
		$_REQUEST['delayed_delivery_finish']="";
		array_push($variable,"use_delayed_delivery_end_date");
		array_push($variable,"delayed_delivery_finish");
	}
}

////////////////////////////////
// HISTORY FOR DISPLAY EMAILS //
////////////////////////////////
$assoc_entity='customer';
$assoc_entity_id=$assoc_entity_id?$assoc_entity_id:$client_id;
if ($assoc_entity){
	$history_assoc_entity=$assoc_entity;
}else{
	$assoc_entity=$history_assoc_entity;
}
if ($assoc_entity_id){
	$history_assoc_entity_id=$assoc_entity_id;
}else{
	$assoc_entity_id=$history_assoc_entity_id;
}
if ($client_id){
	$history_client_id=$assoc_entity_id;
}else{
	$client_id=$history_client_id;
}
$_SESSION["history_assoc_entity"] 		= $history_assoc_entity;
$_SESSION["history_assoc_entity_id"] 	= $history_assoc_entity_id;
$_SESSION["history_client_id"]			= $history_client_id;
////////////////////////////////

########################
# USE LAST VIEWED ITEM #
########################
$variable = array();
array_push($variable,"product_order_search_type");
array_push($variable,"use_start_date");
array_push($variable,"manager_search_hierarchy_types");
array_push($variable,"start");
array_push($variable,"use_end_date");
array_push($variable,"finish");
array_push($variable,"use_delayed_delivery_start_date");
array_push($variable,"delayed_delivery_start");
array_push($variable,"use_delayed_delivery_end_date");
array_push($variable,"delayed_delivery_finish");
require("include/get_last_vars.inc");

#########################

if(is_string($manager_search_hierarchy_types) && preg_match('/^,(.*),$/',$manager_search_hierarchy_types,$regs)) {
	$manager_search_hierarchy_types = explode(',',$regs[1]);
	array_unshift($manager_search_hierarchy_types, 0);
	foreach ($manager_search_hierarchy_types as $key => $value ) {
			if($value != 0) {
				$manager_search_hierarchy_types_array[$key] = $value;
			}
	}
	array_shift($manager_search_hierarchy_types);
	$manager_search_hierarchy_types_values = implode(',',$manager_search_hierarchy_types);
} else {
	if (isset($manager_search_hierarchy_types[0])) {
		array_unshift($manager_search_hierarchy_types, 0);
		unset($manager_search_hierarchy_types[0]);
	}
	$manager_search_hierarchy_types_array = $manager_search_hierarchy_types;
	$manager_search_hierarchy_types_values = implode(',',$manager_search_hierarchy_types_array);
}
////////////////////////////////
// CONFIGURE INTERFACE ARRAYS //
////////////////////////////////

$order_fields=array(
	//This will also change the order and hide/not use
	 "order_num",
	 "client_id",
	 "deal_name",
	 "return_id",
	 "order_status",
	 "originating_channel_id",
	 "originating_brand_id",
	 "originating_club_membership_id",
	 "originating_club_delivery_id",
	 "originating_as_send_sale", 	
	 "originating_as_exchange",
	 "personalization",
	 'has_customer_comments',
	 "cancelled",
	 "payment_type",
	 "actual_payment_type",
	 "receivable_type_id",
	 "payment_invoice_number",
	 "due_date",
	 "balance_due",
);

// Determine To Include POS Order Num Field By Checking If any rows exist in pos_order_data //

$queryBuilder = new QueryBuilderSelect();
$queryBuilder->set_table('pos_order_data');
$queryBuilder->set_limit('1');
$result = $queryBuilder->execute();
if (!empty($result)) {
	array_push($order_fields,"pos_id_order_num");
}

//
// Determine whether or not to include eBay auction ID
//

$queryBuilder = new QueryBuilderSelect();
$queryBuilder->set_table('ebay_auction_data');
$queryBuilder->add_select_field('id');
$queryBuilder->set_limit('1');
$result = $queryBuilder->execute();
if (!empty($result)) {
	array_push($order_fields, 'ebay_auction_id');
}

if ($CONFIG['modules']['in_house_account_manager']['enabled']) {
	array_push($order_fields, 'in_house_account_id');
}

if ($CONFIG['channels']['amazon']['enabled']) {
	array_push($order_fields, 'amazon_order_id');
	array_push($order_fields, 'amazon_is_prime');
}

// Define Additional Custom Sections //
$additional_custom_sections=array(
	array(
		"label"=>"Customer Billing Address",
		"info_field_section"=>"client_default_billing_address",
		"location"=>"customer data",
		"hide_in_criteria"=>1
	),
	array(
		"label"=>"Customer Shipping Address",
		"info_field_section"=>"client_default_shipping_address",
		"location"=>"customer data",
		"hide_in_criteria"=>1
	),
	array(
		//Hard Coded Section
		"hard_coded_section"=>1,
		"label"=>"Order General Attributes",
		"info_field_section"=>"order_general_attributes",
		"location"=>"order data",
		"dividers_before"=>array(
			"due_date",
			"cancelled",
			"pos_id_order_num",
			'ebay_auction_id',
			'in_house_account_id',
		),
		"fields"=>$order_fields,
		"labels"=>array(
			 "order_num"=>"Order Number:",
			 "client_id"=>"Customer Number:",
			 "deal_name"=>"Deal Name:",
			 "order_status"=>"Order Status:",
			 "originating_channel_id"=>"Originating Channel:",
			 "originating_brand_id"=>"Originating Brand:",
			 'originating_club_membership_id' => 'Originating Club Membership:',
			 'originating_club_delivery_id' => 'Originating Club Delivery:',
			 'originating_as_send_sale' => 'Originating as Send Sale:',
			 'originating_as_exchange' => 'Originating as Exchange:',
			 "personalization"=>"Show Orders With Personalizations:",

			 "receivable_type_id"=>"Receivable Type:",
			 "cancelled"=>"Voided:",
			 "checked_out"=>"Checked Out:",
			 "viewed"=>"Last Viewed By:",
			 "payment_type"=>"Intended Payment Type:",
			 "actual_payment_type"=>"<nobr>Actual Payment Type:</nobr>",
			 "due_date"=>"Payment Due:",
			 "balance_due"=>"Balance Due",

			 "salesman"=>"Salesman:",
			 "shipping_cost"=>"Shipping Cost:",
			 "total"=>"Order Total:",
			 "amt_paid"=>"Amount Paid:",
			 "cogs"=>"COGS:",
			 "paid_stamp"=>"Order Paid Date:",
			 "sales_tax"=>"Sales Tax:",
			 "return_id"=>"Return #:",
			 "pos_id_order_num"=>"<nobr>POS Order Number:</nobr>",
			 'ebay_auction_id'=>'<nobr>eBay Auction ID:</nobr>',
			 'in_house_account_id'=>'<nobr>In House Account:</nobr>',
			 'has_customer_comments'=> "Has Customer Comments",
			 "payment_invoice_number"=>"Payment Invoice #:",
			 "amazon_order_id"=>"Amazon Order Id:",
			 "amazon_is_prime"=>"Amazon Prime:",
		),
		"types"=>array(
			 "order_num"=>"text",
			 "client_id"=>"text",
			 "deal_name"=>"text",
			 "originating_channel_id"=>"multiple",
			 "originating_brand_id"=>"select",
			 'originating_club_membership_id' => 'select',
			 'originating_club_delivery_id' => 'select',
 			 'originating_as_send_sale' => 'boolean', 	
			 'originating_as_exchange' => 'boolean',
			 "personalization"=>"boolean",
			 "salesman"=>"text",
			 "shipping_cost"=>"text",
			 "total"=>"text",
			 "amt_paid"=>"text",
			 "payment_type"=>"select",
			 "actual_payment_type"=>"select",
			 "receivable_type_id"=>"select",
			 "cogs"=>"text",
			 "paid_stamp"=>"date",
			 "cancelled"=>"boolean",
			 "sales_tax"=>"text",
			 "order_status"=>"multiple",
			 "viewed"=>"select",
			 "due_date"=>"date",
			 "balance_due"=>"amount",
			 "checked_out"=>"boolean",
			 "return_id"=>"text",
			 "pos_id_order_num"=>"text",
			 'ebay_auction_id'=>'text',
			 'in_house_account_id'=>'select',
			 'has_customer_comments'=>'boolean',
 			 "payment_invoice_number"=>"text",
 			 "amazon_order_id"=>"text",
			 "amazon_is_prime"=>"boolean",
		),
		"select_queries"=>array(
			 "viewed"=> "SELECT user,CONCAT(first_name,' ',last_name) AS name FROM users",
			 "payment_type"=> "SELECT id,label FROM payment_types WHERE back_office_active='y'",
			 "actual_payment_type"=> "SELECT id, label FROM payment_types WHERE back_office_active='y'",
			 "receivable_type_id"=> "SELECT id, label FROM receivable_types", 
			 "originating_channel_id"=> preg_replace('/^[(](.*)[)]$/', '\1', $user_account->get_channel_query()->get_sql()),
			 "originating_brand_id"=> preg_replace('/^[(](.*)[)]$/', '\1', $user_account->get_brand_query()->get_sql()),
			 'originating_club_membership_id' => 'SELECT club_membership.id, models.name FROM club_membership JOIN models ON club_membership.model_id=models.id',
			 'originating_club_delivery_id' => 'SELECT id, delivery_date FROM club_delivery',
			 "order_status"=> "SELECT id,status FROM order_status",
			 'in_house_account_id' => 'SELECT in_house_accounts.id, contacts.company FROM in_house_accounts JOIN client_default_data ON in_house_accounts.parent_client_id=client_default_data.client_id JOIN contacts ON client_default_data.default_billing_contact_id = contacts.id',
		)
	),
	array(
		"label"=>"Order Billing Address",
		"info_field_section"=>"contact_information",
		"location"=>"billing contacts"
	),
	array(
		"label"=>"Order Shipping Address",
		"info_field_section"=>"contact_information",
		"location"=>"shipping contacts"
	),
	array(
		"label"=>"Order Item Information",
		"info_field_section"=>"order_items",
		"location"=>"order items",
	),
	array(
		//Hard Coded Section
		"hard_coded_section"=>1,
		"label"=>"Zone Shipping Information",
		"info_field_section"=>"zone_shipping_information",
		"location"=>"order items",
		"fields"=>array(
			//This will also change the order
			 "delivery_date"
		),
		"labels"=>array(
			 "delivery_date"=>"Zone Ship Date"
		),
		"types"=>array(
			 "delivery_date"=>"date"
		)
		//No info field section so hard code values
	),
	array(
		"label"=>"Order Customer Comments",
		"info_field_section"=>"customer_comments",
		"location"=>"order data",
		"hide_in_criteria"=>1
	),
	array(
		//Hard Coded Section
		"hard_coded_section"=>1,
		"label"=>"Shipping Information",
		"info_field_section"=>"shipping_information",
		"location"=>"order data",
		"fields"=>array(
			//This will also change the order
			 "shipping_method"
		),
		"labels"=>array(
			 "shipping_method"=>"Shipping Method:"
		),
		"types"=>array(
			 "shipping_method"=>"multiple"
		),
		"select_queries"=>array(
			 "shipping_method"=> "SELECT id,label FROM shipping_methods"
		)
		//No info field section so hard code values
	),
	array(
		"label"=>"",
		"info_field_section"=>"shipping_information",
		"location"=>"order data"
	),
	array(
		"label"=>"Salesman",
		"info_field_section"=>"salesman",
		"location"=>"order data"
	)
	
);

////////////////////////////////


////////////////////////////////
// SET UP CUSTOM FIELD ARRAYS //
////////////////////////////////

// Asked to pull Info Field Prefs in one SQL Call Report Builder //
$field_limit_array = array();
$queryBuilder = new QueryBuilderSelect();
$queryBuilder->set_table('info_field_prefs');
$queryBuilder->add_join('info_fields', 'JOIN', 'info_field_prefs.info_field_id', 'info_fields.id');
$queryBuilder->add_join('info_field_pref_areas', 'JOIN', 'info_field_prefs.area_id', 'info_field_pref_areas.id');
$queryBuilder->add_select_field('info_field_prefs.info_field_id');
$queryBuilder->add_select_field('info_fields.section');
$queryBuilder->add_select_field('info_fields.data_table');
$queryBuilder->add_select_field('info_fields.field');
$queryBuilder->add_select_field('info_field_pref_areas.area');
$queryBuilder->add_where('info_field_pref_areas.manager', 'Order Manager');
$queryBuilder->add_where('info_field_prefs.user', $user_info['name']);
$queryBuilder->add_where('info_field_prefs.active', 'n');
$result = $queryBuilder->execute();

foreach ($result as $row) {
	$field_limit_array[$row['area']][$row['section']][$row['data_table']][$row['field']]=$row['info_field_id'];
}

// Product General Attributes //
$variable = array();
$general_attribute_field_active_array = array();
$general_attribute_field_array = array();
$general_attribute_type_array = array();
$general_attribute_search_field_array = array();

$queryBuilder = new QueryBuilderSelect();
$queryBuilder->set_table('info_fields');
$queryBuilder->add_select_field('field');
$queryBuilder->add_select_field('active');
$queryBuilder->add_select_field('data_table');
$queryBuilder->add_where('section', 'product_general_attributes');
$queryBuilder->add_where('active', 'y');
$queryBuilder->set_order_by('position ASC');
$result = $queryBuilder->execute();
foreach ($result as $row) {
	$field = $row["field"];
	$active = $row["active"];
	$table = $row["data_table"];
	$skip = false;

	// Determine if active or not //
	$general_attribute_field_active_array[$field] = $active;


	// Hard Coded fields and labels //
	$info_field_section = "product_general_attributes";
	switch($field){
		case "product_name":
			$field="name";
			$label="Product Name";
			$search_field="order_search_label";
			$type="text";
			break;
		case "product_id":
			$field="id";
			$label="Product ID";
			$search_field="order_search_id";
			$type="text";
			break;
		case "part_num":
			$label="Style/Model Number";
			$search_field="order_search_part_num";
			$type="text";
			break;
		case "product_type":
			$field="type";
			$label="Product Type";
			$search_field="product_order_search_type";
			$type="text";
			break;
		case "inventory":
			$label="Required SKU ID";
			$search_field="order_search_inv";
			$type="text";
			break;
		case "group":
			$label="Group";
			$search_field="order_search_group";
			$type="multiple";
			break;
		case "category":
			$label="Category";
			$search_field="order_search_category";
			$type="multiple";
			break;
		case "manufacturer":
			$field="manufacturer_id";
			$label="Manufacturer";
			$search_field="order_search_manufacturer_id";
			$type="single";
			break;
		case "status":
			$field="void";
			$label="Status";
			$search_field="order_search_status";
			$type="text";
			break;
		default:
			$skip = true;
	}
	if($skip) {
		continue;
	}

	$general_attribute_type_array[$field]=$type;
	array_push($general_attribute_field_array,$field);

	// Create search fields //
	$general_attribute_search_field_array[$field] = $search_field;
	
	// Clean Up Product Search Variables //
	$search_custom = $search_field;		
	if ($action=='search' && !$_REQUEST[$search_custom]){
		$$search_custom = "";
	}
	array_push($variable,$search_custom);	
}

// Product Custom //
$model_information_field_array = array();
$queryBuilder = new QueryBuilderSelect();
$queryBuilder->set_table('info_fields');
$queryBuilder->add_select_field('field');
$queryBuilder->add_select_field('label');
$queryBuilder->add_select_field('type');
$queryBuilder->add_select_field('select_query');
$queryBuilder->add_select_field('data_table');
$queryBuilder->add_select_field('section');
$queryBuilder->add_select_field('size');
$queryBuilder->add_where('section', 'model_information');
$queryBuilder->add_where('active', 'y');
$queryBuilder->set_order_by('position ASC');
$result = $queryBuilder->execute();
foreach ($result as $row) {
	$field = $row["field"];
	$label = $row["label"];
	$type = $row["type"];
	$select_query = $row["select_query"];
	$table = $row["data_table"];
	$info_field_section = $row["section"];
	$size = $row["size"];

	// Set up arrays to remember field info for use later
	if (!$field_limit_array["Search Criteria"][$info_field_section][$table][$field]){
		array_push($model_information_field_array,$field);
		$model_information_type_array[$field] = $type;
		$model_information_label_array[$field] = $label;
		$model_information_select_query_array[$field] = $select_query;
		$model_information_size_array[$field] = $size;
	}

	// Clean Up Product Search Variables //
	$search_custom = 'product_order_search_' . $field;
	if ($action=='search' && !$_REQUEST[$search_custom]){
		$$search_custom = "";
	}
	array_push($variable,$search_custom);

}

// Product Type Custom //
$product_type_field_array = array();
if($product_order_search_type) {
	$product_search_type_part = explode('|',$product_order_search_type,2);
	$product_search_type_id = $product_search_type_part[0];
	$product_search_type_data_table = $product_search_type_part[1];

	$queryBuilder = new QueryBuilderSelect();
	$queryBuilder->set_table('info_fields');
	$queryBuilder->add_select_field('field');
	$queryBuilder->add_select_field('label');
	$queryBuilder->add_select_field('type');
	$queryBuilder->add_select_field('select_query');
	$queryBuilder->add_select_field('data_table');
	$queryBuilder->add_select_field('section');
	$queryBuilder->add_where('section', 'product_info');
	$queryBuilder->add_where('data_table', $product_search_type_data_table);
	$queryBuilder->add_where('active', 'y');
	$queryBuilder->set_order_by('position ASC');
	$result = $queryBuilder->execute();
	foreach ($result as $row) {
		$field = $row["field"];
		$label = $row["label"];
		$type = $row["type"];
		$select_query = $row["select_query"];
		$table = $row["data_table"];
		$product_search_type_section = $info_field_section = $row["section"];

		// Set up arrays to remember field info for use later
		if (!$field_limit_array["Search Criteria"][$info_field_section][$table][$field]){
			array_push($product_type_field_array,$field);
			$product_type_type_array[$field] = $type;
			$product_type_label_array[$field] = $label;
			$product_type_select_query_array[$field] = $select_query;
		}
		
		// Clean Up Product Type Search Variables //
		$search_custom = 'product_type_order_search_' . $field;
		if ($action=='search' && !$_REQUEST[$search_custom]){
			$$search_custom = "";
		}
		array_push($variable,$search_custom);
		
	}

}

// Order Shipping Detail Custom
$order_shipping_details_field_array = array();
$queryBuilder = new QueryBuilderSelect();
$queryBuilder->set_table('info_fields');
$queryBuilder->add_select_field('field');
$queryBuilder->add_select_field('label');
$queryBuilder->add_select_field('type');
$queryBuilder->add_select_field('select_query');
$queryBuilder->add_select_field('data_table');
$queryBuilder->add_select_field('section');
$queryBuilder->add_select_field('size');
$queryBuilder->add_where('section', 'order_shipping_detail_information');
$queryBuilder->add_where('active', 'y');
$queryBuilder->set_order_by('position ASC');
$result = $queryBuilder->execute();
foreach ($result as $row) {
	$field = $row['field'];
	$label = $row['label'];
	$type = $row['type'];
	$select_query = $row['select_query'];
	$table = $row['data_table'];
	$info_field_section = $row['section'];
	$size = $row['size'];
	if (!$field_limit_array['Search Criteria'][$info_field_section][$table][$field]) {
		array_push($order_shipping_details_field_array,$field);
		$order_shipping_details_type_array[$field] = $type;
		$order_shipping_details_label_array[$field] = $label;
		$order_shipping_details_select_query_array[$field] = $select_query;
		$order_shipping_details_size_array[$field] = $size;
	}
	$search_custom = 'order_shipping_details_search_' . $field;
	if ($action == 'search' && !$_REQUEST[$search_custom]) {
		$$search_custom = '';
	}
	array_push($variable, $search_custom);
}

// Additional Custom Sections //
$custom_start_index=0;//Meaning additional custom sections begin at this number
$inc=$custom_start_index;
foreach ($additional_custom_sections as $custom_section_key=>$custom_section){
	$location = $custom_section['location'];
	$custom_info_field_section=$custom_section["info_field_section"];
	$custom_location=$custom_section["location"];
	$hard_coded_section=$custom_section["hard_coded_section"];
	$additional_custom_sections[$custom_section_key]['fields'] = array();
	$additional_custom_sections[$custom_section_key]['types'] = array();
	$additional_custom_sections[$custom_section_key]['labels'] = array();
	$additional_custom_sections[$custom_section_key]['select_queries'] = array();
	
	$info_field_data=array();
	if (!$hard_coded_section){
		$queryBuilder = new QueryBuilderSelect();
		$queryBuilder->set_table('info_fields');
		$queryBuilder->add_select_field('field');
		$queryBuilder->add_select_field('label');
		$queryBuilder->add_select_field('type');
		$queryBuilder->add_select_field('select_query');
		$queryBuilder->add_select_field('data_table');
		$queryBuilder->add_select_field('section');
		$queryBuilder->add_where('section', $custom_info_field_section);
		$queryBuilder->add_where('active', 'y');
		$queryBuilder->set_order_by('position ASC');
		$result = $queryBuilder->execute();
		foreach ($result as $row) {
			if ($location == 'shipping contacts') {
				$row['field'] = 'shipping_'.$row['field'];
			} else if ($location == 'billing contacts') {
				$row['field'] = 'billing_'.$row['field'];
			}

			array_push($info_field_data,$row);
		}
	}else{
		if (count($custom_section["fields"])){
			foreach($custom_section["fields"] as $field){
				$emulate_row=array(
					"field"=>$field,
					"label"=>$custom_section["labels"][$field],
					"type"=>$custom_section["types"][$field],
					"select_query"=>$custom_section["select_queries"][$field],
					"data_table"=>($custom_section["data_table"][$field]?$custom_section["data_tables"][$field]:"order_data"),
					"section"=>$custom_section["info_field_section"]
				);
				array_push($info_field_data,$emulate_row);		
				unset($emulate_row);
			}
		}
	}
	//print "<pre>";
	//print_r($info_field_data);
	
	if(count($info_field_data)){
		foreach($info_field_data as $row){
			$field = $row["field"];
			$label = $row["label"];
			$type = $row["type"];
			$select_query = $row["select_query"];
			$table = $data_table = $row["data_table"];
			$info_field_section = $row["section"];

			// Set up arrays to remember field info for use later
			if (!$field_limit_array["Search Criteria"][$info_field_section][$table][$field]){
				array_push($additional_custom_sections[$custom_section_key]['fields'],$field);
				$additional_custom_sections[$custom_section_key]['types'][$field] = $type;
				$additional_custom_sections[$custom_section_key]['labels'][$field] = $label;
				$additional_custom_sections[$custom_section_key]['select_queries'][$field] = $select_query;
			}

			// Clean Up or Load Search Variables //
			if ($type!="date" && $type!="amount"){
				$search_custom="order_search_${custom_info_field_section}_${field}";
				
				if ($action=='search' && !$_REQUEST[$search_custom]){
					$$search_custom = "";
				}
				array_push($variable,$search_custom);

			} elseif ($type == "amount") {
				$search_custom="order_search_${custom_info_field_section}_${field}_low_amount";
				if ($action=='search' && trim($_REQUEST[$search_custom])==""){
					$$search_custom = "";
				}
				array_push($variable,$search_custom);
				$search_custom="order_search_${custom_info_field_section}_${field}_high_amount";
				if ($action=='search' && trim($_REQUEST[$search_custom])==""){
					$$search_custom = "";
				}
				array_push($variable,$search_custom);

			}else{
				$pre_search_name="order_search_${custom_info_field_section}_";
				
				$date_variables=array(
					"use_${pre_search_name}${field}_start_date"=>"${pre_search_name}${field}_start_date",
					"use_${pre_search_name}${field}_end_date"=>"${pre_search_name}${field}_end_date"
					
				);

				// If Editing, Load Value //
				unset($date_array);
				$date_inc=0;
				foreach($date_variables as $use_date_variable => $date_variable){
					if ($action=='search' && !$_REQUEST[$use_date_variable]){
						$_REQUEST[$use_date_variable]= "";
						$_REQUEST[$date_variable]= "";
						$$use_date_variable = "";
						$$date_variable = "";
					}
					array_push($variable,$use_date_variable);
					array_push($variable,$date_variable);					
					$date_inc++;	
				}
			}
		}
	}
	$inc++;
}

// Determine To Save Cleared Search Variables //
if (count($variable)){
	require("include/get_last_vars.inc");	
}
////////////////////////////////


/////////////
// ACTIONS //
/////////////

if(!$action_type)
	$action_type = 'search';
if ($change_order_statuses && $user_account->has_permission('Order Manager - Status Change')) {
	if(is_array($order_num_array)) {
		foreach($order_num_array as $order_num) {
			$order_entity = CORESenseEntity::get_instantiated_class_from_type('Order', $order_num);
			if ($order_entity !== NULL && $order_entity->order_status != $new_order_status) {
				$order_entity->order_status = $new_order_status;
				$order_entity->save();
			}
		}
	}

	$action = 'order_search';
	$action_type = 'batch';
}

/////////////

$edit_link = "$current_page?action=view$search_url&show_all=$show_all";
$std_view_link = "$current_page?client_id=$client_id&action=view&section=$section&subaction=$subaction&$search_url&show_all=$show_all"; // sections can't have special chars
$order_id_link = "$current_page?order_num=$order_num&action_type=$action_type&$search_url&show_all=$show_all&manager_search_hierarchy_types=," . $manager_search_hierarchy_types_values . ",";

?>
<html>
<head>
<link href="css/select2.min.css" rel="stylesheet" type="text/css" />
<style> 
#customers1 {
    font-family: "Trebuchet MS", Arial, Helvetica, sans-serif;
    border-collapse: collapse;
    width: 100%;
}
#customers1 td, #customers th {
    border:1px solid #ffffff;
    padding: 0px;
	/*background: #ddd;*/
}

#searchresult {
    font-family: "Trebuchet MS", Arial, Helvetica, sans-serif;
    border-collapse: collapse;
    width: 100%;
}
#searchresult td, #searchresult th { 	
    border:1px solid #ffffff;
    padding:0px;
}
#paginationbuttons {
    font-family: "Trebuchet MS", Arial, Helvetica, sans-serif;
    border-collapse: collapse;
    width: 100%;
}
#paginationbuttons td, #paginationbuttons th { 	
    border:0px solid #ddd;
    padding:0px;
}


#tblresult {
    font-family: "Trebuchet MS", Arial, Helvetica, sans-serif;
    border-collapse: collapse;
    width: 100%;
	font-weight:normal;
}
#tblresult td, #tblresult th {
    border:1px solid  #ddd;
    padding: 0px;
	/*background: #ddd;*/

}

#tblresult1 {
    font-family: "Trebuchet MS", Arial, Helvetica, sans-serif;
    border-collapse: collapse;
    width: 100%;
}
#tblresult1 td, #tblresult1 th {
    border:1px solid #ddd;
    padding: 0px;

}
.scrollmenu {
    background-color: #333;
    overflow: auto;
    white-space: nowrap;
}
input[type=button], input[type=submit], input[type=reset] {
   FONT-SIZE: 10px;
  COLOR: #003366;
  FONT-FAMILY: Arial, Helvetica, sans-serif;
  BACKGROUND-COLOR: #EFEFEF;
}
</style>
<script>
function clearSearchTerms(form) {
	for(var i = 0; i < form.elements.length; i++) {
		if(form.elements[i].type == 'select-one') {
			form.elements[i].selectedIndex = 0;
		}
		else if(form.elements[i].type == 'select-multiple') {
			for(var e=0; e < form.elements[i].options.length; e++) {
				form.elements[i].options[e].selected = false;
			}
		}
		else if(form.elements[i].type == 'text') {
			form.elements[i].value = '';
		}
		else if(form.elements[i].type == 'checkbox') {
			form.elements[i].checked = false;
		}
		else if(form.elements[i].type == 'password') {
			form.elements[i].value = '';
		}
		else if(form.elements[i].type == 'radio') {
			form.elements[i].checked = false;
		}
		else if(form.elements[i].type == 'textarea') {
			form.elements[i].value = '';
		}
	}
}
</script>
<script language="javascript" src="js/jquery-1.8.3.min.js"></script>
<script language="javascript" src="js/select2.min.js"></script>
<script language="javascript" src="js/select-multiple.js"></script>
<script language="JavaScript">
<?php include("include/message_console_popup.inc"); ?>
<?php include("include/double_click_protection_js.inc"); ?> 
<?php include("include/inventory_javascript_common.inc"); ?> 
</script>
</head>
<title>Order Manager</title>
<?php include("include/link_styles.inc"); ?>
<body bgcolor="white" leftmargin="2" topmargin="2">
<?php include("main/header.php"); ?>
<?php include("main/sidebar.php"); ?>
<div class="content-wrapper" >
<div style="padding-left:10px;" id="ordermanager" >
	<div style="font-size: 0.80em;font-family: "Times New Roman", Times, serif;"><strong> Filter Orders</strong>
	<img id="downret" src="images/down.png"  height="16" width="16" class="rinfo"> 
	<img id="rightret" src="images/right.png"  height="16" width="16" class="rinfo" style="display:none;"></div>		 
</div>
<div class="box-success" style=" border-bottom: 1px solid #00a65a; height:3px;  width:100%;"></div>
<!--<div style=" border-bottom: height:3px;  width:100%;"></div>-->
<table width="100%" border="0" cellspacing="0" cellpadding="0"  id="customers1">
  <tr>
    <td width="90%" valign="top"><table width="100%" border="0" cellspacing="1" cellpadding="0" align="center">
        <tr>
          <td width="100%" valign="top">
		  	<table width="100%" border="0" cellspacing="0" cellpadding="0" align="right">
              <?php if ($CONFIG['modules']['sales_order_interface']['enabled'] == true && $user_account->check_access('sales_order_interface', NULL, false)) { ?>
             <!-- <tr>
                <td align="right" style="padding:5px; background-color:#FFFFFF;">
				<input  class="btn-primary" type="button"  onClick="window.location.href='sales_order_interface.php3';" value="Launch Sales Order Interface">
                </td>
              </tr>-->
              <?php } ?>
              <tr valign="top">
                <td width="10%" align=left></td>
              </tr>
            </table>
		  	<?php 	//echo start_box('Filter Orders');?>
				
				<table width="100%" border="0" cellspacing="1" cellpadding="0" align="center"  id="customers11" style="display:none;" class="table"  >				
                    <form name="customer_query_form" method="POST" action="<?php echo $current_page ?>">
                      <input type='hidden' name='goto_target' value=''>
					  <tr>
						<td align=center nowrap colspan=8  style="font-size: 0.70em;font-family: "Times New Roman", Times, serif;">
						<input type="hidden" name="edit_custom_report_id" value="<?php echo $edit_custom_report_id; ?>"/>
						<input type="hidden" name="set_product_type" value="0">
						<input type="hidden" name="show_all" value="0">
						<input type="hidden" name="action" value="search">
						<input type="submit" name="submitButton" value="Search" id="search1" class="field_label" onClick="SearchTerms();">
						<input type="button" name="clear" value="Clear" onClick="clearSearchTerms(document.customer_query_form);" class="field_label">
						<input type="reset" value="Restore" class="field_label">
						</td>
					</tr>
                      <tr valign="top">
                        <td><table width="100%" border="0" cellspacing="0" cellpadding="0">
                            <tr>
                              <td align=right class="field_label" style="font-size: 0.70em;font-family: "Times New Roman", Times, serif;"> Start Date &nbsp;:&nbsp; </td>
                              <td class="field_label"><nobr>
                                <input type=checkbox name="use_start_date" value="1"<?php echo ($use_start_date ? ' CHECKED' : '') ?> 
								style="background-color:#edeae8;">
                                <input type="text" value="<?php echo ($start ? $start : date("n/j/Y",time() - (3600*24*365))) ?>" name="start" size="10" 
								style=" width: 50%;padding: 5px 5px;margin: 0px 0px 0px 3px ; box-sizing: border-box;border: 1px solid #4568a0; border-radius: 4px;">
                                <input type=button name=today value="Today" 
								onClick="this.form.use_start_date.checked=true;this.form.start.value='<?php echo date("n/j/Y",time()) ?>';">
                                </nobr> </td>       
                              <td align=right class="field_label" style="font-size: 0.70em;font-family: "Times New Roman", Times, serif;"> End Date &nbsp;:&nbsp;</td>
                              <td class="field_label"><nobr>
                                <input type=checkbox name="use_end_date" value="1"<?php echo ($use_end_date ? ' CHECKED' : '') ?> style="background-color:#edeae8;">
                                <input type="text" value="<?php echo ($finish ? $finish : date("n/j/Y")) ?>" name="finish" size="10" 
								style=" width: 50%;padding: 5px 5px;margin: 0px 0px 0px 3px ; box-sizing: border-box;border: 1px solid #4568a0; border-radius: 4px;">
                                <input type=button name=today value="Today" 
								onClick="this.form.use_end_date.checked=true;this.form.finish.value='<?php print(date("n/j/Y", time())); ?>';">
                                </nobr> </td>
								 <td align=right class="field_label" style="font-size: 0.70em;font-family: "Times New Roman", Times, serif;"> Batch Options &nbsp;:&nbsp; </td>
                              <td  align='left' class="field_label"><nobr>
                                <input type=checkbox name="action_type" value="batch"<?php echo ($action_type == 'batch' ? ' CHECKED' : ''); ?> style="background-color:#edeae8;">
                                </nobr> </td>
							  <td align=right class="field_label" style="font-size: 0.70em;font-family: "Times New Roman", Times, serif;"> Orders Per Page &nbsp;:&nbsp; </td>
                              <td align=left class="field_label">
							  	<select name="new_limit">
                                  <?php foreach($order_per_page_options as $numrow_options) {?>
                                  		<option value="<?php echo $numrow_options?>" <?php echo ($limit==$numrow_options)?'selected="selected"':''; ?>>
                                  		<?php echo $numrow_options?>
										</option>
                                  <?php } ?>
                                </select>                              
								</td>
                            </tr>                           
                            <tr>
                              <td align=right class="field_label" style="font-size: 0.70em;font-family: "Times New Roman", Times, serif;"  nowrap="nowrap"> 
							  Delayed Delivery Start Date &nbsp;:&nbsp; </td>
                              <td class="field_label"><nobr>
                                <input type=checkbox name="use_delayed_delivery_start_date" value="1"<?php echo ($use_delayed_delivery_start_date ? ' CHECKED' : '') ?> 
								style="background-color:#edeae8;">
                                <input type="text" value="<?php echo ($delayed_delivery_start ? $delayed_delivery_start : date("n/j/Y",time() - (3600*24*365))) ?>" 
								name="delayed_delivery_start" size="10" style=" width: 50%;padding: 5px 5px;margin: 0px 0px 0px 3px ; box-sizing: border-box;border: 1px solid #4568a0; border-radius: 4px;">
                                <input type=button name=today value="Today" 
								onClick="this.form.use_delayed_delivery_start_date.checked=true;this.form.delayed_delivery_start.value='<?php echo date("n/j/Y",time()) ?>';">
                                </nobr> </td>
                            
                              <td align=right class="field_label" style="font-size: 0.70em;font-family: "Times New Roman", Times, serif;" nowrap="nowrap">
							   Delayed Delivery End Date &nbsp;:&nbsp;</td>
                              <td class="field_label"><nobr>
                                <input type=checkbox name="use_delayed_delivery_end_date" value="1"<?php echo ($use_delayed_delivery_end_date ? ' CHECKED' : '') ?> 
								style="background-color:#edeae8;">
                                <input type="text" value="<?php echo ($delayed_delivery_finish ? $delayed_delivery_finish : date("n/j/Y")) ?>" name="delayed_delivery_finish" 
								size="10" style=" width: 50%;padding: 5px 5px;margin: 0px 0px 0px 3px ; box-sizing: border-box;border: 1px solid #4568a0; border-radius: 4px;">
                                <input type=button name=today value="Today" onClick="this.form.use_delayed_delivery_end_date.checked=true;this.form.delayed_delivery_finish.value='<?php print(date("n/j/Y", time())); ?>';">
                                </nobr> </td>
                            </tr>
                           <!-- <tr>
                              <td colspan='6' align="center" rowspan="2" ></br>
							  	<input type="submit"  name="submit_button" value="Search" style="font-family: "Times New Roman", Times, serif;" class="field_label">
                              	<input type="button"  name="clear" value="Clear" onClick="clearSearchTerms(document.customer_query_form);" style=";font-family: "Times New Roman", Times, serif;" class="field_label">
                              	<input type="reset" style="font-family: "Times New Roman", Times, serif;" class="field_label">  
								</br>                            
							</td>
                            </tr>-->							
                            <?php
								require "./modules/order_manager/criteria.php";
							?>
                          </table></td>
                      </tr>
                    </form>
                  </table>                  
                  <?php //echo end_box(); ?>				
					<table width="100%"  border="0" cellspacing="0" cellpadding="0"   class="table">
						<tr>
							<td width="100%" valign="top"  align="center" >
								<?php  include "./modules/order_manager/search_results.inc";?>
						</td>
					  </tr>
				  </table>
			 
        </tr>
      </table>
	  </td>
  </tr>
</table>
</div>
<?php include("main/footer.php"); ?>
<?php
if ($goto_target){
	echo "<script name='javascript'>window.location=window.location+'#$goto_target';</script>";
}
?>
</body>
</html>
<script type="text/javascript">
$(function(){
jQuery("#ordermanager").click(function(){
       jQuery("#customers11").toggle();
	   	jQuery(".rinfo").toggle();   
    });
jQuery("#search1").click(function(){
       	jQuery("#customers11").hide();
 		jQuery("#downret").hide();
 		jQuery("#rightret").show();
 		jQuery("#search").show();
    });
});

</script>
