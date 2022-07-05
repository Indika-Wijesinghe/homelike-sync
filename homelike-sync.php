<?php
set_time_limit(0);

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


function shsync_admin_menu()
{
    add_menu_page('Spotahome Sync', 'Spotahome Sync', 'manage_options', 'shsync-menu', 'shsync_script', '', 210);
}

add_action('admin_menu', 'shsync_admin_menu');

function create_sh_rx_post_table($table_name)
{
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            sh_id int(16) NOT NULL,
            rx_id int(16) NOT NULL,
            ex_url varchar(512),
            clicks varchar(256),
            PRIMARY KEY  (id)
            ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function drop_sh_rx_post_table($table_name)
{
    global $wpdb;
    $query = $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name));
    if ($wpdb->get_var($query) == $table_name) {
        $sql = "DROP TABLE IF EXISTS $table_name";
        $wpdb->query($sql);
    }
}

function get_sh_listings_xml()
{

    $url = plugin_dir_path(__FILE__) . "079cebbf72a8e9372a5d4e39bb53907080476f68.xml";
    $xml = simplexml_load_file($url);
    return $xml;
}

function insert_into_shsync_custom_table($table_name, $sh_id, $rx_id, $ex_url)
{
    global $wpdb;
    $clicks = array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0);

    $clicks = json_encode($clicks);

    $wpdb->insert($table_name, array('sh_id' => $sh_id, 'rx_id' => $rx_id, 'ex_url' => $ex_url, 'clicks' => $clicks));
}

function sync_reservation_dates_sh_listings($shlisting, $table_name)
{
    global $wpdb;
    $sh_id = intval($shlisting->Id);

    $query = $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name));


    if ($wpdb->get_var($query) == $table_name) {

        $rx_id = $wpdb->get_var($wpdb->prepare("SELECT rx_id FROM $table_name WHERE sh_id=%d", $sh_id));

        $shsync_available_date = new DateTime($shlisting->Availability);
        $shsync_available_date = $shsync_available_date->getTimestamp();
        $shsync_today_date = new DateTime('today');
        $shsync_today_date = $shsync_today_date->getTimestamp();


        if ($shsync_available_date > $shsync_today_date) {

            $shsync_1_day_in_seconds = 60 * 60 * 24;
            $shsync_reserved_dates_array = [];
            for ($i = $shsync_today_date; $i < $shsync_available_date; $i += $shsync_1_day_in_seconds) {
                $shsync_reserved_dates_array[$i] = 'OR';
            }
            update_post_meta($rx_id, 'reservation_dates', $shsync_reserved_dates_array);
        }
    } else {
        echo "no data to sync";
    }
}

function remove_all_sh_data($table_name)
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

function sh_add_images_of_single_listing($shsync_post_id, $images_array)
{

    $sh_image_count = 0;

    foreach ($images_array as $single_image) {

        $sh_img_id = media_sideload_image($single_image, $shsync_post_id, null, 'id');


        add_post_meta($shsync_post_id, 'homey_listing_images', $sh_img_id);

        $sh_image_count++;
        if ($sh_image_count == 1) {
            update_post_meta($shsync_post_id, '_thumbnail_id', $sh_img_id);
        }
    }
}
function sh_remove_last_listing()
{
    global $wpdb;
    $table_name = $wpdb->prefix . "shsync_custom";

    $sh_last_entry = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC limit 1");
    $last_entry_id = $sh_last_entry[0]->id;
    $last_entry_rx_id = $sh_last_entry[0]->rx_id;
    $sh_start_id = $sh_last_entry[0]->sh_id;



    $sh_listing_img_array = get_post_meta($last_entry_rx_id, 'homey_listing_images');

    foreach ($sh_listing_img_array as $attachment_id) {

        wp_delete_attachment($attachment_id, true);
    }
    wp_delete_post($last_entry_rx_id, true);

    $wpdb->delete($table_name, array('id' => $last_entry_id));

    $wpdb->query("ALTER TABLE $table_name AUTO_INCREMENT =$last_entry_id");

    return $sh_start_id;
}
function sh_camelcase_to_sentence($word)
{
    $arr = preg_split('/(?=[A-Z])/', $word);
    $str = strtolower(implode(' ', $arr));
    return $str;
}
function sh_add_single_listing($shlisting)
{
    //create custom table in the database

    global $wpdb;
    $table_name = $wpdb->prefix . "shsync_custom";

    $sh_id = intval($shlisting->Id);
    $sh_zip = $shlisting->Postcode;
    $sh_ext_url = strval($shlisting->Url_en);

    $sh_bills = "Bills: \n \n" .
        "Wifi: " . sh_camelcase_to_sentence($shlisting->bills->wifi) . "\n" .
        "Water: " . sh_camelcase_to_sentence($shlisting->bills->water) . "\n" .
        "Electricity: " . sh_camelcase_to_sentence($shlisting->bills->electricity) . "\n" .
        "Gas: " . sh_camelcase_to_sentence($shlisting->bills->gas) . "\n";


    $sh_description = $shlisting->Description_en . "\n \n" . $shlisting->rules_en->landlord_policies_en . "\n \n" . $sh_bills;

    // wp_die($sh_description);

    $my_post = array(
        'post_author'   => 104,
        'post_title' => $shlisting->Title_en,
        'post_status'   => 'publish',
        'post_type' => 'listing',
        'post_content' => $sh_description,

    );

    $sh_post_id = wp_insert_post($my_post);



    // insert into pasync table
    insert_into_shsync_custom_table($table_name, $sh_id, $sh_post_id, $sh_ext_url);


    //set post meta=======================================================


    //create new post meta to identify which external source and its id
    if ($sh_ext_url != '') {
        update_post_meta($sh_post_id, 'externel_sit_url', $sh_ext_url);
    }

    //set homey affiliate booking link
    update_post_meta($sh_post_id, 'homey_affiliate_booking_link', $sh_ext_url);

    //create new post meta to identify which external source and its id

    update_post_meta($sh_post_id, 'externel_site', 'spotahome.com-' . $shlisting->Id);

    //total ratings
    update_post_meta($sh_post_id, 'listing_total_rating', 0);

    //guests
    // update_post_meta($sh_post_id, 'homey_total_guests_plus_additional_guests', $shlisting->occupants);

    //booking type
    update_post_meta($sh_post_id, 'homey_booking_type', 'per_month');


    //listing type
    $sh_listing_type = $shlisting->Type;

    if (strcasecmp($sh_listing_type, 'room_shared') == 0) {

        $sh_listing_type = 'shared-room';
    }
    wp_set_object_terms($sh_post_id, $sh_listing_type, 'listing_type');

    //instance booking
    update_post_meta($sh_post_id, 'homey_instant_booking', 0);

    //bedrooms
    $sh_no_bedrooms = intval($shlisting->Number_of_bedrooms);
    update_post_meta($sh_post_id, 'homey_listing_bedrooms', $sh_no_bedrooms);

    //rooms
    update_post_meta($sh_post_id, 'homey_listing_rooms', '');

    //post prefix
    update_post_meta($sh_post_id, 'homey_price_postfix', 'month');

    //additional guest price
    update_post_meta($sh_post_id, 'homey_additional_guests_price', '');


    //app suite
    update_post_meta($sh_post_id, 'homey_aptSuit', '');

    //guests
    update_post_meta($sh_post_id, 'homey_guests', '');

    //beds
    update_post_meta($sh_post_id, 'homey_beds', '');

    //bath
    update_post_meta($sh_post_id, 'homey_baths', intval($shlisting->Number_of_bathrooms));

    //monthly price aka night price
    update_post_meta($sh_post_id, 'homey_night_price', (float)$shlisting->Amount);

    //weekdays
    update_post_meta($sh_post_id, 'homey_weekends_days', 'sat_sun');

    //additional guests
    update_post_meta($sh_post_id, 'homey_allow_additional_guests', '');

    //security deposit
    update_post_meta($sh_post_id, 'homey_security_deposit', (float)$shlisting->rent_conditions->deposit);

    //listing size
    if (intval($shlisting->Place_size) != 0) {

        update_post_meta($sh_post_id, 'homey_listing_size', intval($shlisting->Place_size));
    }

    //listing size unit
    update_post_meta($sh_post_id, 'homey_listing_size_unit', 'sqm');

    //listing address
    update_post_meta($sh_post_id, 'homey_listing_address', $shlisting->Address . ' ' . $shlisting->Postcode);

    //cancellation policy
    //         update_post_meta($sh_post_id, 'homey_cancellation_policy', 'check https://www.parisattitude.com/tenant/cancellation-insurance.aspx for more cancellation policy
    //   ');

    $sh_min_stay = floor((intval($shlisting->Minimum_stay)) / 30);
    //min book months
    update_post_meta($sh_post_id, 'homey_min_book_months', $sh_min_stay);

    //max book months
    update_post_meta($sh_post_id, 'homey_max_book_months', '');


    //facilities
    // update_post_meta($im_post_id, 'homey_smoke', '');
    // update_post_meta($im_post_id, 'homey_pets', '');
    // update_post_meta($im_post_id, 'homey_party', '');
    // update_post_meta($im_post_id, 'homey_children', '');


    update_post_meta($sh_post_id, 'homey_accomodation', '');


    //Amenities
    // this can be inserted as an array  
    // wp_set_object_terms( $listing_id, $amenities_array, 'listing_amenity' );

    if ($shlisting->place_features->balcony_or_terrace == true) {

        wp_set_object_terms($sh_post_id, 'balcony-terrace', 'listing_amenity', true);
    }
    // if ($palisting->parking == 1) {
    //     wp_set_object_terms($sh_post_id, 'parking', 'listing_amenity', true);
    // }
    if ($shlisting->place_features->air_conditioner != 'notAvailable') {
        wp_set_object_terms($sh_post_id, 'air-conditioning', 'listing_amenity', true);
    }
    // if ($shlisting->closet == 1) {
    //     wp_set_object_terms($sh_post_id, 'closet', 'listing_amenity', true);
    // }
    // if ($shlisting->desk == 1) {
    //     wp_set_object_terms($sh_post_id, 'desk', 'listing_amenity', true);
    // }
    if ($shlisting->place_features->dishwasher == true) {
        wp_set_object_terms($sh_post_id, 'dishwasher', 'listing_amenity', true);
    }
    // if ($shlisting->dryer == 1) {
    //     wp_set_object_terms($sh_post_id, 'dryer', 'listing_amenity', true);
    // }
    // if ($shlisting->tv == 1) {
    //     wp_set_object_terms($sh_post_id, 'tv', 'listing_amenity', true);
    // }
    if ($shlisting->place_features->washing_machine != 'notAvailable') {
        wp_set_object_terms($sh_post_id, 'washing-machine', 'listing_amenity', true);
    }

    if ($shlisting->place_features->wifi == 'included') {
        wp_set_object_terms($sh_post_id, 'wi-fi', 'listing_amenity', true);
    }

    if ($shlisting->place_features->oven != false) {
        wp_set_object_terms($sh_post_id, 'oven', 'listing_amenity', true);
    }
    if ($shlisting->place_features->heating == 'included') {
        wp_set_object_terms($sh_post_id, 'heating', 'listing_amenity', true);
    }
    if ($shlisting->place_features->bed_linen == 'providedAndIncludedInRent') {
        wp_set_object_terms($sh_post_id, 'linens', 'listing_amenity', true);
    }

    if ($shlisting->place_features->pets_allowed != false) {
        wp_set_object_terms($sh_post_id, 'pets-allowed', 'listing_amenity', true);
    }

    if ($shlisting->place_features->equipped_kitchen == true) {
        wp_set_object_terms($sh_post_id, 'equipped-kitchen', 'listing_amenity', true);
    }

    if ($shlisting->place_features->furnished == true) {
        wp_set_object_terms($sh_post_id, 'furnished', 'listing_amenity', true);
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

    update_post_meta($sh_post_id, 'homey_extra_prices', $im_pa_fees_array);


    //extra pices
    update_post_meta($sh_post_id, 'homey_services', '');

    //additional rules
    update_post_meta($sh_post_id, 'homey_additional_rules', 'Extra fees to be paid to the host includes <br><b>Deposit: $' . (float)$shlisting->Deposit . '</br> Admin Fees: $' . (float)$shlisting->Admin_Fee . '</b>');

    //closed dates
    update_post_meta($sh_post_id, 'homey_mon_fri_closed', 0);

    update_post_meta($sh_post_id, 'homey_sat_closed', 0);

    update_post_meta($sh_post_id, 'homey_sun_closed', 0);


    //zip
    update_post_meta($sh_post_id, 'homey_zip', intval($shlisting->Postcode));

    // vidoe url
    update_post_meta($sh_post_id, 'homey_video_url', '');

    // homey url
    update_post_meta($sh_post_id, 'homey_featured', 0);

    // geolocation
    update_post_meta($sh_post_id, 'homey_geolocation_lat', (float)$shlisting->Latitude);

    update_post_meta($sh_post_id, 'homey_geolocation_long', (float)$shlisting->Longitude);

    update_post_meta($sh_post_id, 'homey_listing_location',  (float)$shlisting->Latitude . ',' . (float)$shlisting->Longitude);

    // geolocation to wp_homey_map
    homey_insert_lat_long((float)$shlisting->Latitude, (float)$shlisting->Longitude, $sh_post_id);



    // map related
    update_post_meta($sh_post_id, 'homey_listing_map', 1);

    update_post_meta($sh_post_id, 'homey_show_map', 1);

    // additinal guests
    update_post_meta($sh_post_id, 'homey_num_additional_guests', '');


    // reservation dates
    $sh_available_date = new DateTime($shlisting->Availability);
    $sh_available_date = $sh_available_date->getTimestamp();
    $sh_today_date = new DateTime('today');
    $sh_today_date = $sh_today_date->getTimestamp();


    $sh_1_day_in_seconds = 60 * 60 * 24;

    $sh_reserved_dates_array = [];

    for ($i = $sh_today_date; $i < $sh_available_date; $i += $sh_1_day_in_seconds) {
        $sh_reserved_dates_array[$i] = 'OR';
    }

    update_post_meta($sh_post_id, 'reservation_dates', $sh_reserved_dates_array);

    // add images
    $sh_images_array = explode(",", $shlisting->Photos);
    sh_add_images_of_single_listing($sh_post_id, $sh_images_array);
}


function sh_start_sync_from_middle($start_at)
{

    $decoded = get_sh_listings_xml();
    $start_processing = 0;

    foreach ($decoded as $shlisting) {

        if ($start_at == $shlisting->Id) {
            $start_processing = 1;
        }
        if ($start_processing == 1) {
            sh_add_single_listing($shlisting);
        }
    }
}

function get_sh_listing_details($sh_id)
{
    $decoded = get_sh_listings_xml();

    foreach ($decoded as $palisting) {
        if ($sh_id == $palisting->Id) {
            return $palisting;
        }
    }
}
function get_sh_id($rx_id)
{


    if ($rx_id != '') {
        global $wpdb;
        $table_name = $wpdb->prefix . "shsync_custom";
        $sh_id = $wpdb->get_var($wpdb->prepare("SELECT sh_id FROM $table_name WHERE rx_id=%d", $rx_id));

        if (is_numeric($sh_id) && $sh_id > 0) {
            return $sh_id;
        } else {
            return "Not Found";
        }
    } else {
        return "Enter a valid ID";
    }
}

function sh_change_meta_of_listings($table_name, $key, $value)
{
    global $wpdb;
    $listings = $wpdb->get_results("SELECT * FROM $table_name");
    foreach ($listings as $listing) {
        $post_id = $listing->rx_id;
        update_post_meta($post_id, $key, $value);
    }
}
function sh_change_additional_rules_of_listings($table_name)
{
    global $wpdb;
    $listings = $wpdb->get_results("SELECT * FROM $table_name");
    foreach ($listings as $listing) {
        $post_id = $listing->rx_id;
        $sh_id = $listing->sh_id;

        $all_sh_listings = get_sh_listings_xml();

        foreach ($all_sh_listings as $all_sh_listing) {
            if ($all_sh_listing->id == $sh_id) {
                update_post_meta($post_id, 'homey_additional_rules', 'Extra fees to be paid to the host includes <br><b>Deposit: $' . $all_sh_listing->rent_conditions->deposit . '</br> Agency Fees: $' . $all_sh_listing->rent_conditions->agency_fees . '<br>Monthly Utilities: $' . $all_sh_listing->rent_conditions->monthly_utilities . '<br>Important:Extra Fees are subject to change according to the booking duration' . '</b>');
            }
        }
    }
}
function shsync_script()

{
    global $wpdb;
    $table_name = $wpdb->prefix . "shsync_custom";
    // if (array_key_exists('change-extra-fee', $_POST)) {

    //     sh_change_additional_rules_of_listings($table_name);
    // }
    // // change policy of all listings
    // if (array_key_exists('change-policy', $_POST)) {

    //     sh_change_meta_of_listings($table_name, 'homey_cancellation_policy', 'Cancellation policy will be according to the landlord. Contact us for more info');
    // }

    // get pa id of a listing
    $search_sh_id = '';
    $sh_listing_details_show = '';
    if (array_key_exists('get-sh-id', $_POST)) {

        $get_rx_id = $_POST['sh-id'];
        $search_sh_id = get_sh_id($get_rx_id);
        $sh_listing_details_show = get_pa_listing_details($search_sh_id);
    }
    // do the partial sync
    if (array_key_exists('sh-partial-sync', $_POST)) {

        $sh_last_listing = sh_remove_last_listing();
        sh_start_sync_from_middle($sh_last_listing);
    }

    // to sync the available dates only
    if (array_key_exists('sh-available-dates-sync', $_POST)) {
        global $wpdb;
        $decoded = get_sh_listings_xml();
        foreach ($decoded as $shlisting) {

            sync_reservation_dates_sh_listings($shlisting, $table_name);
        }
    }

    // add listings initially
    if (array_key_exists('sh-intial-sync', $_POST)) {
        //create custom table in the database
        remove_all_sh_data($table_name);
        drop_sh_rx_post_table($table_name);
        create_sh_rx_post_table($table_name);

        $decoded = get_sh_listings_xml();

        $sh_count = 0;
        foreach ($decoded as $shlisting) {

            // $sh_count += 1;

            // if ($sh_count > 10) {
            //     break;
            // }
            sh_add_single_listing($shlisting);
        }

?>
        <div id="setting-error-settings-updated" class="updated settings-error notice is dismissible"><strong>Sync
                Completed</strong></div>

    <?php    }


    if (array_key_exists('sh-get-month-clicks', $_POST)) {

        global $wpdb;
        $table_name = $wpdb->prefix . "shsync_custom";
        $column = 'clicks';
        $clicks_array = count_clicks_dashboard($table_name, $column);
    }


    ?>
    <div class="wrap">

        <h2> Sync Spotahome Listings</h2>

        <form action="" method="POST">
            <label for="spotahome-api-endpoint">API</label>
            <input type="text" name="spotahome-api-endpoint" id="spotahome-api-endpoint" value="https://feeds.datafeedwatch.com/35132/079cebbf72a8e9372a5d4e39bb53907080476f68.xml" disabled style="width: 40rem;">
            <br>
            <br>
            <input type="submit" name="sh-intial-sync" class="button button-primary" value="Initial Sync">
            <input type="submit" name="sh-available-dates-sync" class="button button-primary" value="Sync Available Date">
            <input type="submit" name="sh-partial-sync" class="button button-primary" value="Partial Sync">
            <br>
            <br>

            <!-- get PA ID -->
            <label for="get-spotahome-id" style="font-size: 1rem;margin-top:5rem;margin-bottom:1.5rem;">Get the SH
                ID</label>
            <br>
            <br>

            <input type="number" name="sh-id" placeholder="Type Spotahome ID">


            <input type="submit" name="get-sh-id" class="button button-primary" value="Get SH ID" style="margin-left: 1rem;">


            <label name="show-sh-id" style="margin-left:1rem;"><?php echo $search_sh_id ?></label>

            <pre>
<?php if ($sh_listing_details_show != '') {
        print_r($sh_listing_details_show);
    } ?>
        </pre>



            <!-- Clicks Details -->
            <label for="show-sh-clicks" style="font-size: 1rem;margin-top:5rem;margin-bottom:1.5rem;">Clicks Details</label>
            <br>
            <br>

            <input type="submit" name="sh-get-month-clicks" class="button button-primary" value="Show Clicks by Month">
            <label name="show-sh-id" style="margin-left:1rem;"><?php echo $search_sh_id ?></label>

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