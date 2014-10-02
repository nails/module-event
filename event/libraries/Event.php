<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Name:		Event Library
 * Description:	This library handles the creation and deletion of site "events"
 */

class Event
{
	private $_ci;
	private $db;
	private $user_model;
	private $_table;
	private $_table_prefix;
	private $_table_type;
	private	$_errors;
	private $_event_type;


	// --------------------------------------------------------------------------


	/**
	 * Construct the library
	 */
	public function __construct()
	{
		$this->_ci			=& get_instance();
		$this->db			=& $this->_ci->db;
		$this->user_model	=& $this->_ci->user_model;

		// --------------------------------------------------------------------------

		//	Set defaults
		$this->_error			= array();
		$this->_event_type		= array();
		$this->_table			= NAILS_DB_PREFIX . 'event';
		$this->_table_prefix	= 'e';
		$this->_table_type		= NAILS_DB_PREFIX . 'event_type';

		// --------------------------------------------------------------------------

		//	Load helper
		$this->_ci->load->helper( 'event' );
	}


	// --------------------------------------------------------------------------


	/**
	 * Creates an event object
	 * @param  string  $type               The type of event to create
	 * @param  integer $created_by         The event creator (NULL == system)
	 * @param  integer $level              The severity of the event
	 * @param  mixed   $interested_parties The ID of an interested party (array for multiple interested parties) [DEPRECATED]
	 * @param  mixed   $data               Any data to store alongside the event object
	 * @param  integer $ref                A numeric reference to store alongside the event (e.g the id of the object the event relates to)
	 * @param  string  $recorded           A strtotime() friendly string of the date to use instead of NOW() for the created date
	 * @return mixed                       Int on success FALSE on failure
	 */
	public function create( $type, $created_by = NULL, $level = 0, $interested_parties = NULL, $data = NULL, $ref = NULL, $recorded = NULL )
	{
		//	TODO: Remove deprecated interested parties

		/**
		 * When logged in as an admin events should not be created. Hide admin activity on
		 * production only, all other environments should generate events so they can be tested.
		 */

		if ( strtoupper( ENVIRONMENT ) == 'PRODUCTION' && $this->user_model->was_admin() ) :

			return TRUE;

		endif;

		// --------------------------------------------------------------------------

		if ( empty( $type ) ) :

			$this->_set_error( 'Event type not defined.' );
			return FALSE;

		endif;

		// --------------------------------------------------------------------------

		if ( ! is_string( $type ) ) :

			$this->_set_error( 'Event type must be a string.' );
			return FALSE;

		endif;

		// --------------------------------------------------------------------------

		//	Get the event type
		if ( ! isset( $this->_event_type[$type] ) ) :

			$this->db->select( 'id' );
			$this->db->where( 'slug', $type );
			$this->_event_type[$type] = $this->db->get( $this->_table_type )->row();

			if ( ! $this->_event_type[$type] )
				show_error( 'Unrecognised event type.' );


		endif;

		// --------------------------------------------------------------------------

		//	Prep created by
		if ( empty( $created_by ) ) :

			$created_by = active_user( 'id' ) ? (int) active_user( 'id' ) : NULL;

		endif;

		// --------------------------------------------------------------------------

		//	Prep data
		$_data					= array();
		$_data['type_id']		= (int) $this->_event_type[$type]->id;
		$_data['created_by']	= $created_by;
		$_data['url']			= uri_string();
		$_data['data']			= ( $data ) ? serialize( $data ) : NULL;
		$_data['ref']			= (int) $ref;
		$_data['ref']			= $_data['ref'] ? $_data['ref'] : NULL;
		$_data['level']			= $level;

		// --------------------------------------------------------------------------

		$this->db->set( $_data );

		if ( $recorded ) :

			$_data['created'] = date( 'Y-m-d H:i:s', strtotime( $recorded ) );

		else :

			$this->db->set( 'created', 'NOW()', FALSE );

		endif;

		// --------------------------------------------------------------------------

		//	Create the event
		$this->db->insert( $this->_table );

		// --------------------------------------------------------------------------

		if ( ! $this->db->affected_rows() ) :

			$this->_set_error( 'Event could not be created' );
			return FALSE;

		else :

			return $this->db->insert_id();

		endif;

		// --------------------------------------------------------------------------

		//	Return result
		return TRUE;

	}


	// --------------------------------------------------------------------------

	/**
	 * Destroys an event object
	 * @param  integer $id The event ID
	 * @return boolean
	 */
	public function destroy( $id )
	{
		if ( empty( $id ) ) :

			$this->_set_error( 'Event ID not defined.' );
			return FALSE;

		endif;

		// -------------------------------------------------------------------------

		//	Perform delete
		$this->db->where( 'id', $id );
		$this->db->delete( $this->_table );

		if  ( $this->db->affected_rows() ) :

			return TRUE;

		else :

			$this->_set_error( 'Event failed to delete' );
			return FALSE;

		endif;
	}


	// --------------------------------------------------------------------------


	/**
	 * Returns all event objects.
	 * @param  array   $order                      The column on which to order
	 * @param  array   $limit                      The number of items to restrict the query to
	 * @param  string  $where                      A search string compatible with CI's where() method
	 * @param  boolean $include_interested_parties Whether or not to include interested parties [DEPRECATED]
	 * @return array
	 */
	public function get_all( $order = NULL, $limit = NULL, $where = NULL, $include_interested_parties = FALSE )
	{
		//	TODO: Remove deprecated interested parties functionality

		//	Fetch all objects from the table
		$this->db->select( $this->_table_prefix . '.*, et.slug type_slug, et.label type_label, et.description type_description, et.ref_join_table, et.ref_join_column' );
		$this->db->select( 'ue.email,u.first_name,u.last_name,u.profile_img,u.gender' );

		// --------------------------------------------------------------------------

		//	Sorting
		if ( is_array( $order ) ) :

			$this->db->order_by( $order[0], $order[1] );

		else :

			$this->db->order_by( $this->_table_prefix . '.created', 'DESC' );

		endif;

		// --------------------------------------------------------------------------

		//	Set Limit
		if ( is_array( $limit ) ) :

			$this->db->limit( $limit[0], $limit[1] );

		endif;

		// --------------------------------------------------------------------------

		//	Build conditionals
		$this->_getcount_common( $where );

		// --------------------------------------------------------------------------

		$_events = $this->db->get( $this->_table . ' ' . $this->_table_prefix )->result();

		// --------------------------------------------------------------------------

		/**
		 * Prep the output. Loop the results and organise into single events with
		 * interested parties as a sub-array. This method only requires a single
		 * query to the DB rather than one for each returned event.
		 */

		$_created_parts_keys	= array( 'year', 'month', 'day' );

		foreach( $_events AS $event ) :

			$this->_format_event_object( $event) ;

			// --------------------------------------------------------------------------

			if ( $include_interested_parties ) :

				$event->interested_parties = $this->_get_interested_parties_for_event( $event->id );

			endif;

		endforeach;

		// --------------------------------------------------------------------------

		return $_events;
	}


	// --------------------------------------------------------------------------


	/**
	 * Counts events
	 * @param  string  $where  A search string compatible with CI's where() method
	 * @return integer
	 */
	public function count_all( $where = NULL )
	{
		$this->_getcount_common( $where );
		return $this->db->count_all_results( $this->_table . ' ' . $this->_table_prefix );
	}


	// --------------------------------------------------------------------------


	/**
	 * Applies conditionals for other methods
	 * @param  string $where  A search string compatible with CI's where() method
	 * @return void
	 */
	private function _getcount_common( $where = NULL )
	{
		$this->db->join( $this->_table_type . ' et',		$this->_table_prefix . '.type_id = et.id', 'LEFT' );
		$this->db->join( NAILS_DB_PREFIX . 'user u',		$this->_table_prefix . '.created_by = u.id', 'LEFT' );
		$this->db->join( NAILS_DB_PREFIX . 'user_email ue',	'ue.user_id = u.id AND ue.is_primary = 1', 'LEFT' );

		// --------------------------------------------------------------------------

		//	Set Where
		if ( $where ) :

			$this->db->where( $where );

		endif;
	}


	// --------------------------------------------------------------------------


	/**
	 * Return an individual event
	 * @param  integer $id The event's ID
	 * @return mixed       stdClass on success, FALSE on failure
	 */
	public function get_by_id( $id )
	{
		$this->db->where( $this->_table_prefix . '.id', $id );
		$_event = $this->get_all();

		// --------------------------------------------------------------------------

		if ( $_event ) :

			return $_event[0];

		else :

			$this->_set_error( 'No event by that ID (' . $id . ').' );
			return FALSE;

		endif;
	}


	// --------------------------------------------------------------------------


	/**
	 * Returns events of a particular type
	 * @param  string $type The type of event toreturn
	 * @return array
	 */
	public function get_by_type( $type )
	{
		if ( is_numeric( $type ) ) :

			$this->db->where( 'et.id', $type );

		else :

			$this->db->where( 'et.slug', $type );

		endif;

		return $this->get_all();
	}


	// --------------------------------------------------------------------------


	/**
	 * Returns events created by a user
	 * @param  integer $user_id The ID of the user
	 * @return array
	 */
	public function get_by_user( $user_id )
	{
		$this->db->where( $this->_table_prefix . '.created_by', $user_id );
		return $this->get_all();
	}


	// --------------------------------------------------------------------------


	/**
	 * Returns the differing types of event
	 * @return array
	 */
	public function get_types()
	{
		$this->db->order_by( 'label,slug' );
		return $this->db->get( $this->_table_type )->result();
	}


	// --------------------------------------------------------------------------


	/**
	 * Get the differing types of event as a flat array
	 * @return array
	 */
	public function get_types_flat()
	{
		$_types = $this->get_types();

		$_out = array();

		foreach ( $_types AS $type ) :

			$_out[$type->id] = $type->label ? $type->label : title_case( str_replace( '_', ' ', $type->slug ) );

		endforeach;

		return $_out;
	}


	/**
	 * --------------------------------------------------------------------------
	 *
	 * ERROR METHODS
	 * These methods provide a consistent interface for setting and retrieving
	 * errors which are generated.
	 *
	 * --------------------------------------------------------------------------
	 **/


	/**
	 * Set a generic error
	 * @param string $error The error message
	 */
	protected function _set_error( $error )
	{
		$this->_errors[] = $error;
	}


	// --------------------------------------------------------------------------


	/**
	 * Return the error array
	 * @return array
	 */
	public function get_errors()
	{
		return $this->_errors;
	}


	// --------------------------------------------------------------------------


	/**
	 * Returns the last error
	 * @return string
	 */
	public function last_error()
	{
		return end( $this->_errors );
	}


	// --------------------------------------------------------------------------


	/**
	 * Clears the last error
	 * @return mixed
	 */
	public function clear_last_error()
	{
		return array_pop( $this->_errors );
	}


	// --------------------------------------------------------------------------


	/**
	 * Clears all errors
	 * @return void
	 */
	public function clear_errors()
	{
		$this->_errors = array();
	}


	// --------------------------------------------------------------------------


	/**
	 * Formats an event object
	 * @param  stdClass $obj The event object to format
	 * @return void
	 */
	protected function _format_event_object( &$obj )
	{
		//	Ints
		$obj->id	= (int) $obj->id;
		$obj->level	= (int) $obj->level;
		$obj->ref	= NULL === $obj->ref ? NULL : (int) $obj->ref;

		//	Type
		$obj->type					= new stdClass();
		$obj->type->id				= $obj->type_id;
		$obj->type->slug			= $obj->type_slug;
		$obj->type->label			= $obj->type_label;
		$obj->type->description		= $obj->type_description;
		$obj->type->ref_join_table	= $obj->ref_join_table;
		$obj->type->ref_join_column	= $obj->ref_join_column;

		unset( $obj->type_id );
		unset( $obj->type_slug );
		unset( $obj->type_label );
		unset( $obj->type_description );
		unset( $obj->ref_join_table );
		unset( $obj->ref_join_column );

		//	Data
		$obj->data	= unserialize( $obj->data );

		//	User
		$obj->user				= new stdClass();
		$obj->user->id			= $obj->created_by;
		$obj->user->email		= $obj->email;
		$obj->user->first_name	= $obj->first_name;
		$obj->user->last_name	= $obj->last_name;
		$obj->user->profile_img	= $obj->profile_img;
		$obj->user->gender		= $obj->gender;

		unset( $obj->created_by );
		unset( $obj->email );
		unset( $obj->first_name );
		unset( $obj->last_name );
		unset( $obj->profile_img );
		unset( $obj->gender );
	}
}

/* End of file event.php */
/* Location: ./application/libraries/event.php */