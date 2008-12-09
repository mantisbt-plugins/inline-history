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

	/**
	 * Hook the bugnote viewing events.
	 */
	function hooks() {
		$hooks = array();

		$hooks['EVENT_VIEW_BUGNOTES_START'] = 'bugnote_start';
		$hooks['EVENT_VIEW_BUGNOTE'] = 'bugnote';
		$hooks['EVENT_VIEW_BUGNOTES_END'] = 'bugnote_end';

		return $hooks;
	}

	/**
	 * Prepare the bug history entries and bugnote timestamps,
	 * and then display any entries from before the first bugnote.
	 * @param string Event name
	 * @param int Bug ID
	 * @param array Bug note objects
	 */
	function bugnote_start( $p_event, $p_bug_id, $p_bugnotes ) {
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
			$this->history = history_get_events_array( $p_bug_id );
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
				'<td colspan="2"><span style="width: 35%; float: left">',
					'<span class="small" style="width: 50%; float: left">', $t_item['date'], '</span>',
					'<span class="small" style="width: 50%; float: right">', print_user( $t_item['userid'] ), '</span>',
				'</span>',
				'<span class="small" style="width: 65%; float: right">',
					'<span class="small" style="width: 45%; float: left">', string_display( $t_item['note'] ), '</span>',
					'<span class="small" style="width: 55%; float: right">', string_display_line_links( $t_item['change'] ), '</span>',
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
			while( count( $this->history ) > 0 &&
				$this->history[0]['date'] < $t_note_time ) {

				$t_entries[] = array_shift( $this->history );
			}
		} else {
			$t_entries = $this->history;
			$this->history = array();
		}

		return $t_entries;
	}
}

