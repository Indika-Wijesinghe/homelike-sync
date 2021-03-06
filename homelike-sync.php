<?php


/**
 * Plugin Name: Homelike Sync
 * Plugin URI: https://github.com/Indika-Wijesinghe/homelike-sync.git
 * Description: This plugin consumes Homelike xml feed and sync thier listings with rentalexpats.com
 * Version: 1.0.0
 * Author: Indika Wijesinghe
 * Author URI: https://indikawijesinghe.com/
 * License: GPLv2 or later
 */


if (!defined('ABSPATH')) {
    die;
}


function hlsync_admin_menu()
{
    add_menu_page('Homelike Sync', 'Homelike Sync', 'manage_options', 'hlsync-menu', 'hlsync_script', '', 210);
}

add_action('admin_menu', 'hlsync_admin_menu');

function create_hl_rx_post_table($table_name)
{
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ex_id varchar(128) NOT NULL,
            rx_id int(16) NOT NULL,
            ex_url varchar(512),
            clicks varchar(256),
            PRIMARY KEY  (id)
            ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function drop_hl_rx_post_table($table_name)
{
    global $wpdb;
    $query = $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name));
    if ($wpdb->get_var($query) == $table_name) {
        $sql = "DROP TABLE IF EXISTS $table_name";
        $wpdb->query($sql);
    }
}

function get_hl_listings_xml()
{

    $url = plugin_dir_path(__FILE__) . "homelike.xml";
    $xml = simplexml_load_file($url);
    $property_array = $xml[0][0][0]->anbieter->property;
    return $property_array;
}

function insert_into_hlsync_custom_table($table_name, $ex_id, $rx_id, $ex_url)
{
    global $wpdb;
    $clicks = array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0);

    $clicks = json_encode($clicks);

    $wpdb->insert($table_name, array('ex_id' => $ex_id, 'rx_id' => $rx_id, 'ex_url' => $ex_url, 'clicks' => $clicks));
}

function sync_reservation_dates_hl_listings($listing, $table_name)
{
    global $wpdb;
    $ex_id = $listing->administration_technical->openimmo_obid;

    $query = $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name));


    if ($wpdb->get_var($query) == $table_name) {

        $rx_id = $wpdb->get_var($wpdb->prepare("SELECT rx_id FROM $table_name WHERE ex_id=%d", $ex_id));

        $sync_available_date = new DateTime($listing->administration_managment->from_date);
        $sync_available_date = $sync_available_date->getTimestamp();
        $sync_today_date = new DateTime('today');
        $sync_today_date = $sync_today_date->getTimestamp();


        if ($sync_available_date > $sync_today_date) {

            $sync_1_day_in_seconds = 60 * 60 * 24;
            $sync_reserved_dates_array = [];
            for ($i = $sync_today_date; $i < $sync_available_date; $i += $sync_1_day_in_seconds) {
                $sync_reserved_dates_array[$i] = 'OR';
            }
            update_post_meta($rx_id, 'reservation_dates', $sync_reserved_dates_array);
        }
    } else {
        echo "no data to sync";
    }
}

function remove_all_hl_data($table_name)
{
    global $wpdb;

    $query = $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name));
    if ($wpdb->get_var($query) == $table_name) {


        $rx_id_array = $wpdb->get_results($wpdb->prepare("SELECT rx_id FROM $table_name "));


        foreach ($rx_id_array as $rx_id_obj) {

            $rx_id = $rx_id_obj->rx_id;

            $listing_array = get_post_meta($rx_id, 'homey_listing_images');

            foreach ($listing_array as $attachment_id) {

                wp_delete_attachment($attachment_id, true);
            }
            wp_delete_post($rx_id, true);

            $wpdb->delete($table_name, array('rx_id' => $rx_id));
        }
    }
}
function hl_custom_create_img_array($media_attachment)
{
    // echo ("<pre>");
    // print('this is media attachment');
    // print_r($media_attachment);
    // echo ("</pre>");

    $img_array = [];
    foreach ($media_attachment->attachment as $attachment) {

        if (strval($attachment->attributes()->group == 'image')) {
            $img_url = strval($attachment->data->path);
            array_push($img_array, $img_url);
        }
    }

    return $img_array;
}





function hl_add_images_of_single_listing($sync_post_id, $images_array)
{

    $image_count = 0;

    foreach ($images_array as $single_image) {

        $img_id = media_sideload_image($single_image, $sync_post_id, null, 'id');


        add_post_meta($sync_post_id, 'homey_listing_images', $img_id);

        $image_count++;
        if ($image_count == 1) {
            update_post_meta($sync_post_id, '_thumbnail_id', $img_id);
        }
    }
}
function hl_remove_last_listing($table_name)
{
    global $wpdb;

    $last_entry = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC limit 1");
    $last_entry_id = $last_entry[0]->id;
    $last_entry_rx_id = $last_entry[0]->rx_id;
    $start_id = $last_entry[0]->ex_id;



    $listing_img_array = get_post_meta($last_entry_rx_id, 'homey_listing_images');

    foreach ($listing_img_array as $attachment_id) {

        wp_delete_attachment($attachment_id, true);
    }
    wp_delete_post($last_entry_rx_id, true);

    $wpdb->delete($table_name, array('id' => $last_entry_id));

    $wpdb->query("ALTER TABLE $table_name AUTO_INCREMENT =$last_entry_id");

    return $start_id;
}
function hl_camelcase_to_sentence($word)
{
    $arr = preg_split('/(?=[A-Z])/', $word);
    $str = strtolower(implode(' ', $arr));
    return $str;
}
function hl_get_url($attachments)
{

    foreach ($attachments as $attachment) {
        if ($attachment->attributes()->group == "Remote") {
            return $attachment->data->path;
        }
    }
}

function hl_add_single_listing($listing, $table_name)
{
    //create custom table in the database

    global $wpdb;

    $ex_id = strval($listing->administration_technical->openimmo_obid);
    $ex_zip = strval($listing->geo->zip);
    $ext_url = strval(hl_get_url($listing->media_attachment->attachment));


    $description = strval($listing->free_texts->location) . "\n \n" . strval($listing->free_texts->environment_description) . "\n \n" .
        strval($listing->free_texts->object_description) . "\n \n" .
        strval($listing->free_texts->other_details) . "\n \n" .
        'Cancellation Policy: ' . strval($listing->free_texts->cancellation_policy);

    // wp_die($sh_description);

    $my_post = array(
        'post_author'   => 106,
        'post_title' => strval($listing->free_texts->object_title),
        'post_status'   => 'publish',
        'post_type' => 'listing',
        'post_content' => $description,

    );

    $post_id = wp_insert_post($my_post);



    // insert into custom table
    insert_into_hlsync_custom_table($table_name, $ex_id, $post_id, $ext_url);


    // ====================
    //  set post meta
    // ====================


    //create new post meta to identify which external source and its id
    if ($ext_url != '') {
        update_post_meta($post_id, 'externel_sit_url', $ext_url);
    }

    //set homey affiliate booking link
    update_post_meta($post_id, 'homey_affiliate_booking_link', $ext_url);

    //create new post meta to identify which external source and its id

    update_post_meta($post_id, 'externel_site', 'thehomelike.com-' . $ex_id);

    //total ratings
    update_post_meta($post_id, 'listing_total_rating', 0);

    //guests
    // update_post_meta($post_id, 'homey_total_guests_plus_additional_guests', $listing->occupants);

    //booking type
    update_post_meta($post_id, 'homey_booking_type', 'per_month');


    //listing type
    $listing_type = strval($listing->object_category->object_type->acommodation->attributes()->accomodation_type);

    if (strcasecmp($listing_type, 'APARTMENT') == 0) {

        $listing_type = 'apartment';
        $room_type = 'entire-place';
        wp_set_object_terms($post_id, $listing_type, 'listing_type');
        wp_set_object_terms($post_id, $room_type, 'room_type');
    }

    if (strcasecmp($listing_type, 'PRIVATE_ROOM') == 0) {

        $listing_type = 'apartment';
        $room_type = 'private-room';
        wp_set_object_terms($post_id, $listing_type, 'listing_type');
        wp_set_object_terms($post_id, $room_type, 'room_type');
    }

    //instance booking
    update_post_meta($post_id, 'homey_instant_booking', 0);

    //bedrooms
    $no_bedrooms = intval($listing->areas->number_of_bedrooms);
    update_post_meta($post_id, 'homey_listing_bedrooms', $no_bedrooms);

    //rooms
    $no_rooms = intval($listing->areas->number_of_rooms);
    update_post_meta($post_id, 'homey_listing_rooms', $no_rooms);

    //post prefix

    update_post_meta($post_id, 'homey_price_postfix', 'month');

    //additional guest price
    update_post_meta($post_id, 'homey_additional_guests_price', '');


    //app suite
    update_post_meta($post_id, 'homey_aptSuit', '');

    //guests
    update_post_meta($post_id, 'homey_guests', intval($listing->areas->max_occupancy));

    //beds
    update_post_meta($post_id, 'homey_beds', '');

    //bath
    update_post_meta($post_id, 'homey_baths', intval($listing->areas->number_of_batrooms));

    //monthly price aka night price
    update_post_meta($post_id, 'homey_night_price', (float)$listing->prices->warm_rent);

    //weekdays
    update_post_meta($post_id, 'homey_weekends_days', 'sat_sun');

    //additional guests
    update_post_meta($post_id, 'homey_allow_additional_guests', '');

    //security deposit
    update_post_meta($post_id, 'homey_security_deposit', (float)$listing->prices->bail);

    //listing size
    if (intval($listing->areas->total_area) != 0) {

        update_post_meta($post_id, 'homey_listing_size', intval($listing->areas->total_area));
    }

    //listing size unit
    update_post_meta($post_id, 'homey_listing_size_unit', 'sqm');

    //listing address

    $floor = intval($listing->geo->floor);



    if ($floor == 0) {

        $address = strval($listing->geo->zip) . ' ' . strval($listing->geo->street) . ' ' . strval($listing->geo->city) . ' ' . strval("France");
    } else {

        $address = 'Floor ' . strval($listing->geo->floor) . ', ' . strval($listing->geo->zip) . ' ' . strval($listing->geo->street) . ' ' . strval($listing->geo->city) . ' ' . strval("France");
    }


    //this set country to france, this is hardcoded as this only sync paris france listings
    wp_set_object_terms($post_id, 'france', 'listing_country', true);

    //this set city to paris, this is hardcoded as this only sync paris france listings
    wp_set_object_terms($post_id, 'paris', 'listing_city', true);


    update_post_meta($post_id, 'homey_listing_address', $address);

    // cancellation policy

    update_post_meta($post_id, 'homey_cancellation_policy', strval($listing->free_texts->cancellation_policy));

    // $sh_min_stay = floor((intval($listing->Minimum_stay)) / 30);
    //min book months
    // update_post_meta($post_id, 'homey_min_book_months', $sh_min_stay);

    //max book months
    // update_post_meta($post_id, 'homey_max_book_months', '');


    //facilities
    // update_post_meta($im_post_id, 'homey_smoke', '');
    // update_post_meta($im_post_id, 'homey_pets', '');
    // update_post_meta($im_post_id, 'homey_party', '');
    // update_post_meta($im_post_id, 'homey_children', '');



    update_post_meta($post_id, 'homey_accomodation', '');


    //Amenities
    // this can be inserted as an array  
    // wp_set_object_terms( $listing_id, $amenities_array, 'listing_amenity' );

    $pets_allowed = filter_var($listing->administration_managment->house_pets, FILTER_VALIDATE_BOOLEAN);

    $smoking_allowed = !filter_var($listing->administration_managment->non_smoker, FILTER_VALIDATE_BOOLEAN);


    if ($pets_allowed != false) {
        wp_set_object_terms($post_id, 'pets-allowed', 'listing_amenity', $pets_allowed);
    }
    if ($smoking_allowed != false) {
        wp_set_object_terms($post_id, 'smoking-allowed', 'listing_amenity', $smoking_allowed);
    }

    if (strval($listing->equipment->type_of_parking_space->attributes()->free_space) == 'true') {

        wp_set_object_terms($post_id, 'parking', 'listing_amenity', true);
    }

    // if ($listing->place_features->balcony_or_terrace == true) {

    //     wp_set_object_terms($post_id, 'balcony-terrace', 'listing_amenity', true);
    // }

    if (strval($listing->equipment->air_conditioned == 'true')) {
        wp_set_object_terms($post_id, 'air-conditioning', 'listing_amenity', true);
    }

    // if ($listing->closet == 1) {
    //     wp_set_object_terms($post_id, 'closet', 'listing_amenity', true);
    // }
    // if ($listing->desk == 1) {
    //     wp_set_object_terms($post_id, 'desk', 'listing_amenity', true);
    // }
    if (strval($listing->equipment->user_defined_field->dishwasher == 'true')) {
        wp_set_object_terms($post_id, 'dishwasher', 'listing_amenity', true);
    }
    if (strval($listing->equipment->user_defined_field->dryer == 'true')) {
        wp_set_object_terms($post_id, 'dryer', 'listing_amenity', true);
    }
    if (strval($listing->equipment->user_defined_field->tv == 'true')) {
        wp_set_object_terms($post_id, 'tv', 'listing_amenity', true);
    }

    if (strval($listing->equipment->user_defined_field->washing_machine == 'true')) {
        wp_set_object_terms($post_id, 'washing-machine', 'listing_amenity', true);
    }

    if (strval($listing->equipment->user_defined_field->washing_machine == 'true')) {
        wp_set_object_terms($post_id, 'microwave', 'listing_amenity', true);
    }


    // if ($listing->place_features->wifi == 'included') {
    //     wp_set_object_terms($post_id, 'wi-fi', 'listing_amenity', true);
    // }

    if (strval($listing->equipment->kitchen->attributes()->oven == 'true')) {

        wp_set_object_terms($post_id, 'oven', 'listing_amenity', true);
    }
    // if ($listing->place_features->heating == 'included') {
    //     wp_set_object_terms($post_id, 'heating', 'listing_amenity', true);
    // }

    // if ($listing->place_features->bed_linen == 'providedAndIncludedInRent') {
    //     wp_set_object_terms($post_id, 'linens', 'listing_amenity', true);
    // }



    if (strval($listing->equipment->kitchen->attributes()->ebk == 'true')) {
        wp_set_object_terms($post_id, 'equipped-kitchen', 'listing_amenity', true);
    }

    if (strval($listing->equipment->furnished->attributes()->furn == 'full')) {
        wp_set_object_terms($post_id, 'furnished', 'listing_amenity', true);
    }

    // services
    $im_pa_fees_array = [];

    // array_push(
    //     $im_pa_fees_array,
    //     array(
    //         'name' => 'Agency Fees', 'price' => $palisting->rent_conditions->agency_fees, 'type' => 'single_fee'
    //     )
    // );

    // array_push(
    //     $im_pa_fees_array,
    //     array(
    //         'name' => 'Monthly Utilities',
    //         'price' => $palisting->rent_conditions->monthly_utilities,
    //         'type' => 'single_fee'
    //     )
    // );

    update_post_meta($post_id, 'homey_extra_prices', $im_pa_fees_array);


    //extra pices
    update_post_meta($post_id, 'homey_services', '');

    //additional rules
    // update_post_meta($post_id, 'homey_additional_rules', 'Extra fees to be paid to the host includes <br><b>Deposit: $' . (float)$listing->Deposit . '</br> Admin Fees: $' . (float)$listing->Admin_Fee . '</b>');

    //closed dates
    update_post_meta($post_id, 'homey_mon_fri_closed', 0);

    update_post_meta($post_id, 'homey_sat_closed', 0);

    update_post_meta($post_id, 'homey_sun_closed', 0);


    //zip
    update_post_meta($post_id, 'homey_zip', $ex_zip);

    // vidoe url
    update_post_meta($post_id, 'homey_video_url', '');

    // homey url
    update_post_meta($post_id, 'homey_featured', 0);

    $latitude = (float)$listing->geo->geocoordinates->attributes()->latitude;
    $longitude = (float)$listing->geo->geocoordinates->attributes()->longitude;

    // geolocation
    update_post_meta($post_id, 'homey_geolocation_lat', $latitude);

    update_post_meta($post_id, 'homey_geolocation_long', $longitude);

    update_post_meta($post_id, 'homey_listing_location',   $latitude  . ',' . $longitude);

    // geolocation to wp_homey_map
    homey_insert_lat_long($latitude,  $longitude, $post_id);


    // map related
    update_post_meta($post_id, 'homey_listing_map', 1);

    update_post_meta($post_id, 'homey_show_map', 1);

    // additinal guests
    update_post_meta($post_id, 'homey_num_additional_guests', '');


    // reservation dates
    $available_date = new DateTime(strval($listing->administration_managment->from_date));
    $available_date = $available_date->getTimestamp();
    $today_date = new DateTime('today');
    $today_date = $today_date->getTimestamp();


    $one_day_in_seconds = 60 * 60 * 24;

    $reserved_dates_array = [];

    for ($i = $today_date; $i < $available_date; $i += $one_day_in_seconds) {
        $reserved_dates_array[$i] = 'OR';
    }

    update_post_meta($post_id, 'reservation_dates', $reserved_dates_array);

    // add images
    // $sh_images_array = explode(",", $listing->Photos);

    $img_array = hl_custom_create_img_array($listing->media_attachment);
    hl_add_images_of_single_listing($post_id, $img_array);
}


function hl_start_sync_from_middle($start_at)
{

    $decoded = get_hl_listings_xml();
    $start_processing = 0;

    foreach ($decoded as $listing) {

        $listing_country = $listing->geo->country->attributes()->iso_country;
        if ($listing_country != "FRA") {
            continue;
        }

        if ($start_at == $listing->administration_technical->openimmo_obid) {
            $start_processing = 1;
        }

        if ($start_processing == 1 and $listing_country == "FRA") {
            global $wpdb;
            $table_name = $wpdb->prefix . "hlsync_custom";
            hl_add_single_listing($listing, $table_name);
        }
    }
}

function get_hl_listing_details($ex_id)
{
    $decoded = get_hl_listings_xml();

    foreach ($decoded as $listing) {
        if ($ex_id == $listing->$listing->administration_technical->openimmo_obid) {
            return $listing;
        }
    }
}
function get_hl_ex_id($rx_id)
{


    if ($rx_id != '') {
        global $wpdb;
        $table_name = $wpdb->prefix . "hlsync_custom";
        $ex_id = $wpdb->get_var($wpdb->prepare("SELECT ex_id FROM $table_name WHERE rx_id=%d", $rx_id));

        if ($ex_id) {
            return $ex_id;
        } else {
            return "Not Found";
        }
    } else {
        return "Enter a valid ID";
    }
}



function hlsync_script()

{
    global $wpdb;
    $table_name = $wpdb->prefix . "hlsync_custom";
    // if (array_key_exists('change-extra-fee', $_POST)) {

    //     sh_change_additional_rules_of_listings($table_name);
    // }
    // // change policy of all listings
    // if (array_key_exists('change-policy', $_POST)) {

    //     sh_change_meta_of_listings($table_name, 'homey_cancellation_policy', 'Cancellation policy will be according to the landlord. Contact us for more info');
    // }

    // get pa id of a listing
    $search_ex_id = '';
    $hl_listing_details_show = '';
    if (array_key_exists('get-hl-id', $_POST)) {

        $get_rx_id = $_POST['hl-id'];
        $search_ex_id = get_hl_ex_id($get_rx_id);
        $hl_listing_details_show = get_hl_listing_details($search_ex_id);
    }
    // do the partial sync
    if (array_key_exists('hl-partial-sync', $_POST)) {

        $table_name = $wpdb->prefix . "hlsync_custom";
        $hl_last_listing = hl_remove_last_listing($table_name);
        hl_start_sync_from_middle($hl_last_listing);
    }

    // to sync the available dates only
    if (array_key_exists('hl-available-dates-sync', $_POST)) {
        global $wpdb;
        $decoded = get_hl_listings_xml();
        foreach ($decoded as $listing) {

            sync_reservation_dates_hl_listings($listing, $table_name);
        }
    }

    // add listings initially
    if (array_key_exists('hl-intial-sync', $_POST)) {
        //create custom table in the database
        remove_all_hl_data($table_name);
        drop_hl_rx_post_table($table_name);
        create_hl_rx_post_table($table_name);

        $decoded = get_hl_listings_xml();

        foreach ($decoded as $listing) {


            $listing_country = $listing->geo->country->attributes()->iso_country;
            if ($listing_country != "FRA") {
                continue;
            }
            global $wpdb;
            $table_name = $wpdb->prefix . "hlsync_custom";
            hl_add_single_listing($listing, $table_name);
        }

?>
        <div id="setting-error-settings-updated" class="updated settings-error notice is dismissible"><strong>Sync
                Completed</strong></div>

    <?php    }


    if (array_key_exists('hl-get-month-clicks', $_POST)) {

        global $wpdb;
        $table_name = $wpdb->prefix . "hlsync_custom";
        $column = 'clicks';
        $clicks_array = count_clicks_dashboard($table_name, $column);
    }


    ?>
    <div class="wrap">

        <h2> Sync Homelike Listings</h2>

        <form action="" method="POST">
            <!-- <label for="spotahome-api-endpoint">API</label>
            <input type="text" name="spotahome-api-endpoint" id="spotahome-api-endpoint" value="https://feeds.datafeedwatch.com/35132/079cebbf72a8e9372a5d4e39bb53907080476f68.xml" disabled style="width: 40rem;"> -->
            <br>
            <br>

            <!-- ====================
                initial sync
            ==================== -->

            <!-- <input type="submit" name="hl-intial-sync" class="button button-primary" value="Initial Sync"> -->

            <!-- ====================
                available dates sync
            ==================== -->

            <!-- <input type="submit" name="hl-available-dates-sync" class="button button-primary" value="Sync Available Dates"> -->

            <!-- ====================
                partial sync
            ==================== -->
            <!-- <input type="submit" name="hl-partial-sync" class="button button-primary" value="Partial Sync"> -->
            <br>
            <br>

            <!-- get PA ID -->
            <label for="get-homelike-id" style="font-size: 1rem;margin-top:5rem;margin-bottom:1.5rem;">Get the HL
                ID</label>
            <br>
            <br>

            <input type="number" name="hl-id" placeholder="Type Homelike ID">


            <input type="submit" name="get-hl-id" class="button button-primary" value="Get HL ID" style="margin-left: 1rem;">


            <label name="show-hl-id" style="margin-left:1rem;"><?php echo $search_ex_id ?></label>

            <pre>
<?php if ($hl_listing_details_show != '') {
        print_r($hl_listing_details_show);
    } ?>
        </pre>



            <!-- Clicks Details -->
            <label for="show-hl-clicks" style="font-size: 1rem;margin-top:5rem;margin-bottom:1.5rem;">Clicks Details</label>
            <br>
            <br>

            <input type="submit" name="hl-get-month-clicks" class="button button-primary" value="Show Clicks by Month">
            <label name="show-hl-id" style="margin-left:1rem;"><?php echo $search_ex_id ?></label>

            <br>
            <br>
            <?php
            if (isset($clicks_array)) {
            ?>

                <table id="sh-table">
                    <tr>
                        <th>Months</th>
                        <th>Clicks</th>
                    </tr>
                    <tr>
                        <td>January</td>
                        <td><?php echo ($clicks_array[0]) ?></td>
                    </tr>
                    <tr>
                        <td>February</td>
                        <td><?php echo ($clicks_array[1]) ?></td>
                    </tr>
                    <tr>
                        <td>March</td>
                        <td><?php echo ($clicks_array[2]) ?></td>
                    </tr>
                    <tr>
                        <td>April</td>
                        <td><?php echo ($clicks_array[3]) ?></td>
                    </tr>
                    <tr>
                        <td>May</td>
                        <td><?php echo ($clicks_array[4]) ?></td>
                    </tr>
                    <tr>
                        <td>June</td>
                        <td><?php echo ($clicks_array[5]) ?></td>
                    </tr>
                    <tr>
                        <td>July</td>
                        <td><?php echo ($clicks_array[6]) ?></td>
                    </tr>
                    <tr>
                        <td>August</td>
                        <td><?php echo ($clicks_array[7]) ?></td>
                    </tr>
                    <tr>
                        <td>September</td>
                        <td><?php echo ($clicks_array[8]) ?></td>
                    </tr>
                    <tr>
                        <td>October</td>
                        <td><?php echo ($clicks_array[9]) ?></td>
                    </tr>
                    <tr>
                        <td>November</td>
                        <td><?php echo ($clicks_array[10]) ?></td>
                    </tr>
                    <tr>
                        <td>December</td>
                        <td><?php echo ($clicks_array[11]) ?></td>
                    </tr>

                </table>
            <?php } ?>


            <style>
                #sh-table td:nth-child(even) {
                    padding-left: 1rem;
                }
            </style>

            <!-- change policy -->

            <!-- <input type="submit" name="change-policy" class="button button-primary" value="Change Policy"> -->
            <br>
            <br>

            <!-- change Extra Fee Rules -->

            <!-- <input type="submit" name="change-extra-fee" class="button button-primary" value="Change Extra Fee"> -->
        </form>
    </div>
<?php }

//pasync