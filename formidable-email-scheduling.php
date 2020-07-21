<?php
namespace convey_frm_email;
/*
Plugin Name: Formidable Email Scheduling
Description: Schedule the emails sent by Formidable Forms
Version:     0.0.5
Author:      Andrew J Klimek
Author URI:  https://github.com/andrewklimek
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Added new hook to formidable for this to work so much better:
https://github.com/Strategy11/formidable-forms/commit/d81c0c03a3d5e7fc866bdfb0d932c4683d3b4602
$skip_this_action = apply_filters( 'frm_skip_form_action', $skip_this_action, compact( 'action', 'entry', 'form', 'event' ) );
*/

// error_log(var_export(get_option('cron'),true));

// Taken from formidable's autoresponder so I can stop them sabotaging the "Only when just updated" functionality
function remove_action_from_global( $atts ) {
	global $frm_vars;
	if ( isset( $frm_vars['action_check'] ) && isset( $frm_vars['action_check'][ $atts['action']->ID ] ) ) {
		unset( $frm_vars['action_check'][ $atts['action']->ID ] );
	}
}


function ajax_field_update( $atts ){
	define( 'CONVEY_AJAX_FIELD_UPDATE', $atts['field']->id );
	// do_action( 'frm_after_update_entry', $atts['entry_id'], $atts['field']->form_id );// This is being one in some other custom code
}

register_activation_hook( __FILE__, __NAMESPACE__ .'\activation' );
register_deactivation_hook( __FILE__, __NAMESPACE__ .'\deactivation' );
add_action( __NAMESPACE__ .'\cron_hook', __NAMESPACE__ .'\check_schedule' );
add_action( 'frm_additional_action_settings', __NAMESPACE__ .'\add_settings', 100, 2 );
add_action( 'frm_skip_form_action', __NAMESPACE__ .'\intercept_and_schedule', 11, 2 );
add_action( 'admin_print_scripts', __NAMESPACE__ .'\admin_inline_js' );
add_filter( 'frm_pre_update_entry', __NAMESPACE__ .'\compare_values_for_updates', 10, 2 );
add_filter( 'cron_schedules', __NAMESPACE__ .'\custom_cron_schedule' );
add_action('frm_after_update_field', __NAMESPACE__ .'\ajax_field_update' );

function check_schedule() {
	
	$now = get_gmt_from_date( date( "Y-m-d H:i:s", strtotime( "now" ) ) );
	global $wpdb;

	$emails = $wpdb->get_results( 
		"
		SELECT * 
		FROM {$wpdb->prefix}frm_convey_queue 
		WHERE send_date <= '{$now}' AND sent = 0 
		ORDER BY send_date ASC
		" );

	if ( $emails ) {
		foreach ( $emails as $email ) {
			$result = run_action( $email->action_id, $email->entry_id, $email->form_id );
			// Mark as read
			$wpdb->update( "{$wpdb->prefix}frm_convey_queue", array( 'sent' => $result['code'] ), array( 'id' => $email->id ), '%d', '%d' );
			
			// Debugging
			if ( $result['message'] !== 'OK' ) {
				error_log( $result['message'] );
			}
		}
	}
}

function run_action( $action_id, $entry_id, $form_id ) {
	$action = \FrmFormAction::get_single_action_type( $action_id, 'email');
	$entry = \FrmEntry::getOne( $entry_id, 'get meta too' );
	$form = \FrmForm::getOne( $form_id );
	
	// Check if anything's been deleted
	if ( ! $form ) {
			return array( 'code' => 3, 'message' => "The form is missing for action $action_id on entry $entry_id in form $form_id" );
	} elseif ( ! $action ) {
		return array( 'code' => 3, 'message' => "The form is missing for action $action_id on entry $entry_id in form $form_id" );
	} elseif ( ! $entry ) {
		return array( 'code' => 3, 'message' => "The form is missing for action $action_id on entry $entry_id in form $form_id" );
	}
	
	// check conditional logic
	$stop = \FrmFormAction::action_conditions_met( $action, $entry );
	if ( $stop ) {
		return array( 'code' => 2, 'message' => "The conditinals were not met for $action_id, $entry_id and $form_id" );
	}
	
	// if no errors, trigger email
	\FrmNotification::trigger_email( $action, $entry, $form );
	return array( 'code' => 1, 'message' => 'OK' );
}

function intercept_and_schedule( $skip_action_original, $atts ) {
		
	if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {// TODO Test this
		return $skip_action_original;
	}

	extract( $atts );// $action (obj), $entry (int or obj), $form (obj), $event (str)
	
	if ( empty( $action->post_content['scheduling_settings'] ) ) {
		return $skip_action_original;
	}
	$settings = $action->post_content['scheduling_settings'];
	global $wpdb;
		
	if ( ! is_object( $entry ) ) {// apparently $entry can be integer or object!
		$entry = \FrmEntry::getOne( $entry, true );
	}

	// Handle "just changed" field checks
	if ( /*$event === 'update' &&*/ !empty( $settings['changed'] ) ) {// Removing the event type check cause formidable's autoresponder ignores them
		
		// get an array of fields that need to change for this action to run
		$changed_setting = explode( ',', preg_replace( '/[^,\d]/', '', $settings['changed'] ) );
		
		// was this triggered by an "update field" shortcode [frm-entry-update-field]
		if ( defined( 'CONVEY_AJAX_FIELD_UPDATE' ) && CONVEY_AJAX_FIELD_UPDATE ) {
			// changed field setting needs to only have one field and match the field in the shortcode
			if ( count( $changed_setting ) !== 1 || $changed_setting[0] !== CONVEY_AJAX_FIELD_UPDATE ) {
				remove_action_from_global( $atts );
				return "skip";
			}
		} elseif ( $previous_values = wp_cache_get( 'frm_changed_fields' ) ) {

			$field_ids = $wpdb->get_col( "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id={$entry->id}", 0 );
			$meta_values = $wpdb->get_col( "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id={$entry->id}", 1 );
			$meta = array_combine( $field_ids, $meta_values );
			// poo($meta, '$meta');
			// poo($previous_values, '$previous_values');
			$changed = array_diff_assoc( $meta, $previous_values ) + array_diff_assoc( $previous_values, $meta );
			// poo($changed, '$changed');
			$changed_setting = array_flip( $changed_setting );// flip field IDs from value to key
			// $matches = array_intersect_key( $changed_setting, $previous_values );// Any... would return true if (!$matches)
			$matches = array_diff_key( $changed_setting, $changed );// All. Removes all fields from the required fields which are found in the changed fields
			if ( $matches ) {// any required fields left without a match?
				// poo($matches, '$matches');
				remove_action_from_global( $atts );
				return "skip";// some of the required fields were not changed so skip this action
			}
		} else {
			remove_action_from_global( $atts );
			return "skip";// either there were no changes or the cache returned nothing so we don't know!
		}
	}
	
	// Is it a scheduled action?  If not, exit with original $skip value.
	if ( empty( $settings['active'] ) ) {
		return $skip_action_original;
	}
	

	
	// if delete action, delete the scheduled emails and return original skip value
	if ( $event === 'delete' ) {
		$wpdb->delete(
			"{$wpdb->prefix}frm_convey_queue",
			array(
				"action_id"	=>	$action->ID,
				"entry_id"	=>	$entry->id
			),
			array( '%d', '%d' )
		);
		return $skip_action_original;
	}
			
	// OK we made it past all those checks, now let's skip this email and schedule it instead
	$skip_action = true;
	
	// poo($entry);
	
	// Set starting date
	if ( $settings['date'] ) {
		$datestring = $entry->metas[ $settings['date'] ];// if there's a date field use that field's meta
		$in_utc = false;
	} elseif ( !empty( $settings['changed'] ) ) {
		$in_utc = true;
		$datestring = $entry->updated_at;// default option when "just changed" is active, use update time
	} else {
		$in_utc = true;
		$datestring = $entry->created_at;// default and not watching for a changed field, use original entry creation time
	}
	
	if ( ! $datestring ) {
		return "skip and do not scheduled because the date is blank.";
	}
	
	// poo($datestring);
	// date will be Y-m-d format as stored by formidable. have to think about custom dates.
	// $tzcor = tzcorrect();
	// time() + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
	// $date = gmdate( "Y-m-d H:i:s", strtotime( "{$date} {$settings['at']}{$tzcor}" ) );
	// $date = get_gmt_from_date( date( "Y-m-d H:i:s", strtotime( $date . $settings['at'] ) ) );
	
	$datetime = tzcorrect_date_create( "{$datestring} {$settings['at']}", $in_utc );// create DateTime object with the correct timezone as set in WP settings.
	$wp_dtz = $datetime->getTimezone();// Store the timezone object so we can toggle between it and UTC
	$utc_dtz = timezone_open('UTC');// create a UTC timezone object
	$datetime->setTimezone($utc_dtz);// change timezone to UTC for database insert. FYI this is how wordpress prepares date for db insert, using get_gmt_from_date, but this is more efficient than looking up the current WP timezone each time and converting to SQL format first.
	$current_gmt = gmdate( 'Y-m-d H:i:s' );
	
	// complete skip scheduling stuff in the past.  subtract 60 seconds from current time to allow for latency.
	if ( ( $datetime->getTimestamp() ) < ( time() - 60 ) ) {
		poo( "skip action and do not schedule because the schedule time is past");
		return "skip action and do not schedule because the schedule time is past";
	}
	
	
	// Handle updates & rescheduling

	if ( $event !== 'create' ) {// none of this is needed if it's a new entry
		

		
		
		// Get the original schedueld date
		$previous_schedule = $wpdb->get_row( 
			"SELECT * FROM {$wpdb->prefix}frm_convey_queue 
			WHERE action_id = {$action->ID} AND entry_id = {$entry->id}
			ORDER BY send_date ASC"
		);
		if ( $previous_schedule ) {
			
			// if ( $previous_schedule->sent === '0' ) { // only reschedule is not sent yet? this is probably handled by the above line: if ( ( $datetime->getTimestamp() ) < ( time() - 60 ) ) {
			// if the original date and the new date aren't the same, set up rescheduled email
			/* if ( $send_date !== $original_date ) {
				$skip_action = $skip_action_original;// don't skip, but filter the message to a re-scheduling notice.
				add_filter( 'frm_email_subject', __NAMESPACE__ .'\rescheduled_notice_subject', 10, 2 );
				add_filter( 'frm_email_message', __NAMESPACE__ .'\rescheduled_notice_body', 10, 2 );
			} */
			// }
		
			// Finally delete all the original scheduled entries for this ID
			$delete_result = $wpdb->delete(
				"{$wpdb->prefix}frm_convey_queue",
				array(
					"action_id"	=>	$action->ID,
					"entry_id"	=>	$entry->id
				),
				array( '%d', '%d' )
			);
		
			// Debugging
			if ( ! $delete_result ) {
				error_log("There was an update but no rows were deleted for action ID {$action->ID}");
			}
		}
	}//if ( $event === 'update' )
	
	// If the event type doesn't match the conditional, we should only proceed if a reschedule is needed.
	if ( ! in_array( $event, $action->post_content['event'] ) && ! $delete_result ) {
		return $skip_action_original;
	}
	
	// Now we run the actual scheduling
	$wpdb->insert(
		"{$wpdb->prefix}frm_convey_queue",
		array(
			"send_date"	=>	$datetime->format("Y-m-d H:i:s"),
			"action_id"	=>	$action->ID,
			"entry_id"	=>	$entry->id,
			"form_id"	=>	$form->id,
			"created_date" => $current_gmt
		),
		array( '%s', '%d', '%d', '%d', '%s' )
	);
	
	// Handle recurring

	if ( $settings['every'] ) {
		
		if ( $settings['number'] || $settings['end_date'] || $settings['custom_end_date'] ) {
		
			// catch interval strings with no increment and add "next" in front
			if ( 0 === preg_match( '/^(\+|\-|\d|next)/i', $settings['every'] ) ) {
				$settings['every'] = "next {$settings['every']}";
			}
			// Get interval ending settings, defaults to not go crazy, tho they are pretty generous.
			$n = $settings['number'] ? $settings['number'] : '999';
			
			if ( $settings['end_date'] ) {
				$end = $entry->metas[ $settings['end_date'] ];//$settings['end_date'] is a field ID
			} elseif ( $settings['custom_end_date'] ) {
				$end = $settings['custom_end_date'];
			} else {
				$end = '+10 years';
			}
			if ( ! $end ) {
				error_log("error with end date, probably \$entry->metas[ \$settings['end_date'] ] isn't returning anything");
			}
			$end = tzcorrect_date_create( $end );// make an ending DateTime object to compare against current DateTime.
			if ( ! $end ) {
				error_log("failed creating datetime object from the end date which was most likely the formatting of: {$settings['custom_end_date']}");
			}
			$datetime->setTimezone($wp_dtz);// switch timezone back to WP setting to handle the interval expression correctly
			$datetime->modify( $settings['every'] );// advance datetime by the expression (done before the loop and at the end of each loop so the end date checks correctly)

			while ( $n > 0 && $datetime < $end ) {
				$datetime->setTimezone($utc_dtz);// change timezone to UTC for database insert.
			
				// add to queue
				$wpdb->insert(
					"{$wpdb->prefix}frm_convey_queue",
					array(
						"send_date"	=>	$datetime->format("Y-m-d H:i:s"),
						"action_id"	=>	$action->ID,
						"entry_id"	=>	$entry->id,
						"form_id"	=>	$form->id,
						"created_date" => $current_gmt
					),
					array( '%s', '%d', '%d', '%d', '%s' )
				);
			
				$datetime->setTimezone($wp_dtz);// switch timezone back to WP setting to handle the interval expression correctly
				$datetime->modify( $settings['every'] );// advance datetime by the expression
				--$n;// 1 down...
			}
		
		} else {//if ( $settings['number'] || $settings['end_date'] || $settings['custom_end_date'] )
			error_log("no number of repitions and no end date given for action ID {$action->ID}");
		}
	}// if ( $settings['every'] ) {
return $skip_action;
}

/**
* Add our settings to the email action
*/
function add_settings( $form_action, $args = array() ) {

	extract($args);// compact( 'form', 'action_control', 'action_key', 'values' )
	
	$settings = array( 'active' => '', 'date' => '', 'at' => '', 'every' => '', 'number' => '', 'end_date' => '', 'custom_end_date' => '', 'changed' => '' );//defaults
	
	if ( !empty( $form_action->post_content['scheduling_settings'] ) ) {
		$settings = $form_action->post_content['scheduling_settings'] + $settings;
	}
	
	$field_name = esc_attr( $action_control->get_field_name('scheduling_settings') );
	?>
	<h3>
		<label for="<?php echo esc_attr( $action_control->get_field_id('sheduling_active') ) ?>"><input onclick="toggleSchedulingDisplay(event)" type="checkbox" name="<?php echo $field_name ?>[active]" id="<?php echo esc_attr( $action_control->get_field_id('sheduling_active') ) ?>" value="1" <?php checked( $settings['active'], 1 ) ?>/> Schedule</label>
	</h3>
	<div class="scheduling-settings" <?php if ( ! $settings['active'] ) echo 'style="display:none;"' ?>>
		<p>
			<label>Send on </label>
			<select name="<?php echo $field_name ?>[date]">
				<option value="">Date of entry submission</option>
				<?php // $post_key = 'post_date';
				$field_val = $settings['date'];
				$post_field = array( 'date' );
				include(dirname(__FILE__) .'/_post_field_options.php');
				?>
			</select>
			<label>at </label>
			<input type="text" class="" value="<?php echo esc_attr( $settings['at'] ) ?>" name="<?php echo $field_name ?>[at]">
		</p>
		<p>
			<label>Resend every </label>
			<input type="text" class="regular-text" value="<?php echo esc_attr( $settings['every'] ) ?>" name="<?php echo $field_name ?>[every]">
		</p>
		<p>
			<label>Stop after </label>
			<input type="number" class="small-text" value="<?php echo esc_attr( $settings['number'] ) ?>" name="<?php echo $field_name ?>[number]">
			<label> emails, or after </label>
			<select name="<?php echo $field_name ?>[end_date]">
				<option value="">Custom date:</option>
				<?php
				$field_val = $settings['end_date'];
				$post_field = array( 'date' );
				include(dirname(__FILE__) .'/_post_field_options.php');
				?>
			</select>
			<label> </label>
				<input type="text" class="" value="<?php echo esc_attr( $settings['custom_end_date'] ) ?>" name="<?php echo $field_name ?>[custom_end_date]">
		</p>
	</div>
	<p><label>Only if all of these fields were just changed: </label> <input type="text" id="<?php echo esc_attr( $action_control->get_field_id('changed') ) ?>" class="frm_not_changed" value="<?php echo esc_attr( $settings['changed'] ) ?>" name="<?php echo $field_name ?>[changed]"></p>
	<?php
}


function rescheduled_notice_subject( $subject, $atts ) {
	extract( $atts );//compact('form', 'entry', 'email_key')
	$subject = "RESCHEDULED: $subject";
	remove_filter( 'frm_email_subject', __NAMESPACE__ .'\rescheduled_notice_subject', 10, 2 );
	return $subject;
}
function rescheduled_notice_body( $message, $atts ) {
	$message = "This has been rescheduled";
	remove_filter( 'frm_email_message', __NAMESPACE__ .'\rescheduled_notice_body', 10, 2 );
	return $message;
}

function admin_inline_js(){
	?>
	<script>
	function toggleSchedulingDisplay(e){
		var settings = e.currentTarget.parentElement.parentElement.nextElementSibling;
		settings.style.display = (settings.style.display == 'none') ? 'block' : 'none';
	}
	</script>
	<?php
}


function compare_values_for_updates($values, $id) {
	global $wpdb;
	// $meta = \FrmEntryMeta::get_entry_meta_info( $values['id'] );//FrmDb::get_var( 'frm_item_metas', array( 'item_id' => $entry_id ), '*', array(), '', 'results' );
	// $meta = \FrmDb::get_col( $wpdb->prefix . 'frm_item_metas', array( 'item_id' => $id ), 'meta_value' );//or $values['id']
	$field_ids = $wpdb->get_col( "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id={$id}", 0 );
	$meta_values = $wpdb->get_col( "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id={$id}", 1 );
	$meta = array_combine( $field_ids, $meta_values );
// 	poo($meta, '$meta');
// 	poo($values['item_meta'], '$values[item_meta]');
// 	$changed = array_diff_assoc( $meta, $values['item_meta'] );
	wp_cache_set( 'frm_changed_fields', $meta );
	return $values;
}

function custom_cron_schedule( $schedules ) {
	$schedules['fivemin'] = array(
		'interval' => 300,
		'display' => 'Every 5 Minutes'
	);
	return $schedules;
}

function activation() {
	create_database();
	if ( ! wp_next_scheduled ( __NAMESPACE__ .'\cron_hook' ) ) {
		$start = 300*(intval(time()/300)+1)-10;// a crazy calculation to get the next 5 minute mark hh:m[0|5]:50
		wp_schedule_event( time(), 'fivemin', __NAMESPACE__ .'\cron_hook' );//
	}
}

function deactivation() {
	wp_clear_scheduled_hook( __NAMESPACE__ .'\cron_hook' );
}

function tzcorrect( $string = '' ) {
	$hours = get_option( 'gmt_offset');
	$minutes = abs( ( $hours - intval($hours) ) * 60 );
	return sprintf( "%s GMT%+03d:%02d", $string, $hours, $minutes );
}
function get_gmt_from_string( $string, $format = 'Y-m-d H:i:s' ) {
	$tz = get_option( 'timezone_string' );
	if ( $tz ) {
		$datetime = date_create( $string, timezone_open( $tz ) );
		if ( ! $datetime ) {
			return gmdate( $format, 0 );
		}
		$datetime->setTimezone( timezone_open( 'UTC' ) );
		$string_gmt = $datetime->format( $format );
	} else {
		$hours = get_option( 'gmt_offset' );
		$minutes = abs( ( $hours - intval($hours) ) * 60 );
		$string = sprintf( "%s GMT%+03d:%02d", $string, $hours, $minutes );
		
		
		$datetime = strtotime( $string );
		if ( false === $datetime ) {
			return gmdate( $format, 0 );
		}
		$string_gmt = gmdate( $format, $datetime );
	}
	return $string_gmt;
}
function tzcorrect_date_create( $string, $utc = false ) {
	$tz = get_option( 'timezone_string' );
	if ( $tz ) {
		if ( $utc ) {
			$datetime = date_create( $string, timezone_open( 'UTC' ) );
			$datetime->setTimezone( timezone_open( $tz ) );
		} else {
			$datetime = date_create( $string, timezone_open( $tz ) );
		}
	} else {
		$hours = get_option( 'gmt_offset' );
		if ( $hours === '0' ){
			$datetime = date_create( $string, timezone_open( 'UTC' ) );
		} else {
			if ( $utc ) {
				$string = date( 'Y-m-d H:i:s', $hours * 3600 + strtotime( $string ) );
			}
			$minutes = abs( ( $hours - intval($hours) ) * 60 );
			$string = sprintf( "%s GMT%+03d:%02d", $string, $hours, $minutes );
			$datetime = date_create( $string );
		}
	}
	return $datetime;
}

/***
* Database 
***************/

function create_database() {
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );// to use dbDelta()
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE {$wpdb->prefix}frm_convey_queue (
		id bigint(20) unsigned NOT NULL auto_increment,
		send_date datetime NOT NULL default '0000-00-00 00:00:00',
		sent tinyint(1) unsigned NOT NULL default 0,
		action_id bigint(20) unsigned NOT NULL default 0,
		entry_id bigint(20) unsigned NOT NULL default 0,
		form_id bigint(20) unsigned NOT NULL default 0,
		created_date datetime NOT NULL default '0000-00-00 00:00:00',
		PRIMARY KEY  (id)
		) ENGINE=InnoDB {$charset_collate};");
}

