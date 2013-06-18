<?php
# Copyright (C) 2008	John Reese
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.

/**
 * Inline History plugin.
 * Adds the ability to display bug history entries intermixed with bugnotes.
 * @author John Reese
 */
class InlineHistoryPlugin extends MantisPlugin {

	function register() {
		$this->name = plugin_lang_get( 'title' );
		$this->description = plugin_lang_get( 'description' );

		$this->version = '0.1';
		$this->requires = array(
			'MantisCore' => '1.2.0',
		);

		$this->author = 'John Reese';
		$this->contact = 'jreese@leetcode.net';
		$this->url = 'http://leetcode.net';
	}

	function config() {
		return array(
			'default_enabled' => ON,
		);
	}

	/**
	 * Hook the bugnote viewing events.
	 */
	function hooks() {
		$hooks = array();

		$hooks['EVENT_LAYOUT_RESOURCES'] = 'css';
		$hooks['EVENT_VIEW_BUGNOTES_START'] = 'bugnote_start';
		$hooks['EVENT_VIEW_BUGNOTE'] = 'bugnote';
		$hooks['EVENT_ACCOUNT_PREF_UPDATE_FORM'] = 'user_pref_update_form';
		$hooks['EVENT_ACCOUNT_PREF_UPDATE'] = 'user_pref_update';

		return $hooks;
	}

	function css() {
		return '<link rel="stylesheet" type="text/css" href="' . plugin_file( 'style.css' ) . '"/>';
	}

	/**
	 * Checks the database to see if user wants to view inline history.
	 * @param int User ID
	 * @return boolean Inline history is selected for the user
	 */
	function user_inline_view_enabled( $p_user_id=null ) {

		static $s_enabled = array();

		if( is_null( $p_user_id ) ) {
			$p_user_id = auth_get_current_user_id();
		}

		if( !isset( $s_enabled[ $p_user_id ] ) ){
			$t_user_table = plugin_table( 'user' );

			$t_query = "SELECT * FROM $t_user_table
						WHERE user_id=" . db_param();
			$t_result = db_query_bound( $t_query, array( $p_user_id ) );

			if ( db_num_rows ( $t_result ) < 1 ) {
				$s_enabled[ $p_user_id ] = plugin_config_get( 'default_enabled' );

				$t_query = "INSERT INTO $t_user_table
							( user_id, enabled )
							VALUES ( " . db_param() . ', ' . db_param() . ' )';
				db_query_bound ( $t_query, array( $p_user_id, $s_enabled[ $p_user_id ] ) );

			} else {
				$t_row = db_fetch_array( $t_result );
				$s_enabled[ $p_user_id ] = $t_row['enabled'];
			}
		}

		return $s_enabled[ $p_user_id ];
	}

	/**
	 * Adds a row to the user preference page to enable or
	 * disable inline history entries.
	 * @param string Event name
	 * @param int User ID
	 */
	function user_pref_update_form( $p_event, $p_user_id ) {

		if ( $this->user_inline_view_enabled( $p_user_id ) ){
			$t_checked = ' checked="checked"';
		}

		echo '<tr ', helper_alternate_class(), '><td class="category">',
			plugin_lang_get( 'view_inline_history' ),
			'<input type="hidden" name="inline_history" value="1"/>',
			'</td><td><input type="checkbox" name="inline_history_enabled"',
			$t_checked, '/></td></tr>';
	}

	/**
	 * Update the user preference in the database from the form
	 * @param string Event name
	 * @param int User ID
	 */
	function user_pref_update( $p_event, $p_user_id ) {

		$t_user_table = plugin_table( 'user' );

		$f_set = gpc_get_int( 'inline_history', 0 );
		$f_enabled = gpc_get_bool( 'inline_history_enabled', 0 );

		if ( !$f_set ) {
			return;
		}

		$t_query = "UPDATE $t_user_table
					SET enabled=" . db_param() .
					" WHERE user_id=" . db_param();
		db_query_bound( $t_query, array( $f_enabled, $p_user_id ) );
	}

	/**
	 * Prepare the bug history entries and bugnote timestamps,
	 * and then display any entries from before the first bugnote.
	 * @param string Event name
	 * @param int Bug ID
	 * @param array Bug note objects
	 */
	function bugnote_start( $p_event, $p_bug_id, $p_bugnotes ) {
		if( !$this->user_inline_view_enabled() ){
			return;
		}

		$this->order = ( 'ASC' == current_user_get_pref( 'bugnote_order' ) );

		$t_normal_date_format = config_get( 'normal_date_format' );

		$this->history = array();
		$this->bugnote_times = array();

		$t_last_id = 0;
		foreach( $p_bugnotes as $t_note ) {
			$this->bugnote_times[ $t_last_id ] = date( $t_normal_date_format, $t_note->date_submitted );
			$t_last_id = $t_note->id;
		}

		$t_access_level_needed = config_get( 'view_history_threshold' );
		if ( access_has_bug_level( $t_access_level_needed, $p_bug_id ) ) {
			$t_history = array_filter( history_get_events_array( $p_bug_id ), 'InlineHistory_Filter_Entries');
			# The array comes from the database sorted according to history_order
			# If this is not the same as bugnote_order we need to reverse the history array
			if( config_get( 'history_order' ) === current_user_get_pref( 'bugnote_order' ) ) {
				$this->history = $t_history;
			} else {
				$this->history = array_reverse( $t_history );
			}
		}

		$this->display_entries(0);
	}

	/**
	 * Display entries between the given bugnote and the next.
	 * @param string Event name
	 * @param int Bug ID
	 * @param int Bugnote ID
	 * @param boolean Private bugnote
	 */
	function bugnote( $p_event, $p_bug_id, $p_bugnote_id, $p_private ) {
		if( !$this->user_inline_view_enabled() ){
			return;
		}

		$this->display_entries( $p_bugnote_id );
	}


	/**
	 * Display history entries up to the given bugnote.
	 * @param int Bugnote ID
	 */
	function display_entries( $p_bugnote_id=-1 ) {
		$t_entries = $this->next_entries( $p_bugnote_id );

		if ( count( $t_entries ) < 1 ) { return; }

		if ( $p_bugnote_id != 0 ) {
			echo '<tr class="spacer"><td colspan="2"></td></tr>';
		}

		$t_class = 2;
		$t_last_date = ( isset( $this->bugnote_times[ $p_bugnote_id ] ) ? $this->bugnote_times[ $p_bugnote_id ] : '' );
		foreach( $t_entries as $t_item ) {
			if ( $t_item['date'] != $t_last_date ) {
				$t_class = $t_class % 2 + 1;
			}

			echo '<tr class="row-', $t_class, '">',
				'<td colspan="2"><span class="IHleft">',
					'<span class="IHdate">', $t_item['date'], '</span>',
					'<span class="IHuser">', print_user( $t_item['userid'] ), '</span>',
				'</span>',
				'<span class="IHright">',
					'<span class="IHfield">', string_display( $t_item['note'] ), '</span>',
					'<span class="IHchange">', string_display_line_links( $t_item['change'] ), '</span>',
				'</span></td></tr>';

			$t_last_date = $t_item['date'];
		}

		if ( $p_bugnote_id == 0 ) {
			echo '<tr class="spacer"><td colspan="2"></td></tr>';
		}
	}

	/**
	 * Generate a list of remaining history entries that occurred before
	 * the given bugnote in time.
	 * @param int Bugnote ID
	 * @return array History entries
	 */
	function next_entries( $p_bugnote_id=-1 ) {
		if ( $p_bugnote_id >= 0 && isset( $this->bugnote_times[ $p_bugnote_id ] ) ) {
			$t_note_time = $this->bugnote_times[ $p_bugnote_id ];
			$t_entries = array();
			if ( $this->order ) {
				while( $this->history[0] !== NULL && $this->history[0]['date'] < $t_note_time ) {
					$t_entries[] = array_shift( $this->history );
				}
			} else {
				while( $this->history[0] !== NULL && $this->history[0]['date'] >= $t_note_time ) {
					$t_entries[] = array_shift( $this->history );
				}
			}
		} else {
			$t_entries = $this->history;
			$this->history = array();
		}
		return $t_entries;
	}

	/**
	 * Database schema for plugin data.
	 */
	function schema() {
		return array(
			# 2009-03-20
			array( 'CreateTableSQL', array( plugin_table( 'user' ), "
				user_id		I		NOTNULL UNSIGNED PRIMARY,
				enabled		L		NOTNULL DEFAULT '0'
				" ) ),
		);
	}
}

/**
 * Filter out history entries for "Note Added".
 *
 * @param History entry
 * @return True if entry not "Note Added: xyz"
 */
function InlineHistory_Filter_Entries( $p_entry ) {
	return (stristr($p_entry['note'], 'Note Added:') != $p_entry['note']);
}
