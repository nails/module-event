<?php

/**
 * Name:        Event Library
 * Description: This library handles the creation and deletion of site "events"
 */

class Event
{
    //  Class traits
    use NAILS_COMMON_TRAIT_ERROR_HANDLING;
    use NAILS_COMMON_TRAIT_CACHING;

    private $_ci;
    private $db;
    private $user_model;
    private $eventTypes;
    private $_table;
    private $_table_prefix;


    // --------------------------------------------------------------------------


    /**
     * Construct the library
     */
    public function __construct()
    {
        $this->_ci          =& get_instance();
        $this->db           =& $this->_ci->db;
        $this->user_model   =& $this->_ci->user_model;

        // --------------------------------------------------------------------------

        //  Set defaults
        $this->_error           = array();
        $this->eventTypes       = array();
        $this->_table           = NAILS_DB_PREFIX . 'event';
        $this->_table_prefix    = 'e';

        // --------------------------------------------------------------------------

        //  Load helper
        $this->_ci->load->helper('event');

        // --------------------------------------------------------------------------

        //  Look for email types defined by enabled modules
        $modules = _NAILS_GET_AVAILABLE_MODULES();

        foreach ($modules as $module) {

            $_module    = explode('-', $module);
            $_path      = FCPATH . 'vendor/' . $module . '/' . $_module[1] . '/config/event_types.php';

            if (file_exists($_path)) {

                include $_path;

                if (!empty($config['event_types'])) {

                    foreach ($config['event_types'] as $type) {

                        $this->addType($type);
                    }
                }
            }
        }

        //  Finally, look for app email types
        $path = FCPATH . APPPATH . 'config/event_types.php';

        if (file_exists($path)) {

            include $path;

            if (!empty($config['event_types'])) {

                foreach ($config['event_types'] as $type) {

                    $this->addType($type);
                }
            }
        }
    }


    // --------------------------------------------------------------------------


    /**
     * Adds a new email type to the stack
     * @param mixed $slug             The event's slug; calling code refers to events by this value. Alternatively pass
     *                                a stdClass to set all values.
     * @param string $label           The human friendly name to give the event
     * @param string $description     The human friendly description of the event's purpose
     * @param array $hooks            An array of hooks to fire when an event is fired
     */
    public function addType($slug, $label = '', $description = '', $hooks = array())
    {
        if (empty($slug)) {

            return false;
        }

        if (is_string($slug)) {

            $this->eventTypes[$slug]                    = new stdClass();
            $this->eventTypes[$slug]->slug          = $slug;
            $this->eventTypes[$slug]->label         = $label;
            $this->eventTypes[$slug]->description   = $description;
            $this->eventTypes[$slug]->hooks         = $hooks;

        } else {

            if (empty($slug->slug)) {

                return false;
            }

            $this->eventTypes[$slug->slug]              = new stdClass();
            $this->eventTypes[$slug->slug]->slug            = $slug->slug;
            $this->eventTypes[$slug->slug]->label           = $slug->label;
            $this->eventTypes[$slug->slug]->description = $slug->description;
            $this->eventTypes[$slug->slug]->hooks       = $slug->hooks;

        }

        return true;
    }


    // --------------------------------------------------------------------------


    /**
     * Creates an event object
     * @param  string  $type      The type of event to create
     * @param  mixed   $data      Any data to store alongside the event object
     * @param  integer $createdBy The event creator (null == system)
     * @param  integer $ref       A numeric reference to store alongside the event (e.g the id of the object the event relates to)
     * @param  string  $recorded  A strtotime() friendly string of the date to use instead of NOW() for the created date
     * @return mixed              Int on success false on failure
     */
    public function create($type, $data = null, $createdBy = null, $ref = null, $recorded = null)
    {
        /**
         * When logged in as an admin events should not be created. Hide admin activity on
         * production only, all other environments should generate events so they can be tested.
         */

        if (strtoupper(ENVIRONMENT) == 'PRODUCTION' && $this->user_model->was_admin()) {

            return true;
        }

        // --------------------------------------------------------------------------

        if (empty($type)) {

            $this->_set_error('Event type not defined.');
            return false;
        }

        // --------------------------------------------------------------------------

        if (!is_string($type)) {

            $this->_set_error('Event type must be a string.');
            return false;
        }

        // --------------------------------------------------------------------------

        //  Get the event type
        if (!isset($this->eventTypes[$type])) {

            show_error('Unrecognised event type.');
        }

        // --------------------------------------------------------------------------

        //  Prep created by
        if (empty($createdBy)) {

            $createdBy = active_user('id') ? (int) active_user('id') : null;
        }

        // --------------------------------------------------------------------------

        //  Prep data
        $_data                  = array();
        $_data['type']          = $type;
        $_data['created_by']    = $createdBy;
        $_data['url']           = uri_string();
        $_data['data']          = $data ? serialize($data) : null;
        $_data['ref']           = (int) $ref;
        $_data['ref']           = $_data['ref'] ? $_data['ref'] : null;

        $this->db->set($_data);

        if ($recorded) {

            $_data['created'] = date('Y-m-d H:i:s', strtotime($recorded));

        } else {

            $this->db->set('created', 'NOW()', false);
        }

        //  Create the event
        $this->db->insert($this->_table);

        // --------------------------------------------------------------------------

        if (!$this->db->affected_rows()) {

            $this->_set_error('Event could not be created');
            return false;

        } else {

            //  Call any hooks
            if (!empty($this->eventTypes[$type]->hooks)) {
                foreach ($this->eventTypes[$type]->hooks as $hook) {

                    if (empty($hook['path'])) {
                        continue;
                    }

                    if (!file_exists($hook['path'])) {
                        continue;
                    }

                    include_once $hook['path'];

                    if (!class_exists($hook['class'])) {
                        continue;
                    }

                    $class      = new $hook['class'];
                    $reflect    = new ReflectionClass($class);

                    try {

                        $method = $reflect->getMethod($hook['method']);

                    } catch(Exception $e) {
                        continue;
                    }

                    if (!$method->isPublic() && !$method->isStatic()) {
                        continue;
                    }

                    if ($method->isStatic()) {

                        $hook['class']::$hook['method']($type, $_data);

                    } else {

                        $class->$hook['method']($type, $_data);

                    }
                }
            }

            return $this->db->insert_id();
        }
    }


    // --------------------------------------------------------------------------


    /**
     * Destroys an event object
     * @param  integer $id The event ID
     * @return boolean
     */
    public function destroy($id)
    {
        if (empty($id)) {

            $this->_set_error('Event ID not defined.');
            return false;
        }

        // -------------------------------------------------------------------------

        //  Perform delete
        $this->db->where('id', $id);
        $this->db->delete($this->_table);

        if ($this->db->affected_rows()) {

            return true;

        } else {

            $this->_set_error('Event failed to delete');
            return false;
        }
    }


    // --------------------------------------------------------------------------


    /**
     * Returns all event objects.
     * @param  array   $order                      The column on which to order
     * @param  array   $limit                      The number of items to restrict the query to
     * @param  string  $where                      A search string compatible with CI's where() method
     * @return array
     */
    public function get_all($order = null, $limit = null, $where = null)
    {
        //  Fetch all objects from the table
        $this->db->select($this->_table_prefix . '.*');
        $this->db->select('ue.email,u.first_name,u.last_name,u.profile_img,u.gender');

        // --------------------------------------------------------------------------

        //  Sorting
        if (is_array($order)) {

            $this->db->order_by($order[0], $order[1]);

        } else {

            $this->db->order_by($this->_table_prefix . '.created', 'DESC');
        }

        // --------------------------------------------------------------------------

        //  Set Limit
        if (is_array($limit)) {

            $this->db->limit($limit[0], $limit[1]);
        }

        // --------------------------------------------------------------------------

        //  Build conditionals
        $this->_getcount_common($where);

        // --------------------------------------------------------------------------

        $_events = $this->db->get($this->_table . ' ' . $this->_table_prefix)->result();

        // --------------------------------------------------------------------------

        foreach ($_events as $event) {

            $this->_format_event_object($event);
        }

        // --------------------------------------------------------------------------

        return $_events;
    }


    // --------------------------------------------------------------------------


    /**
     * Counts events
     * @param  string  $where  A search string compatible with CI's where() method
     * @return integer
     */
    public function count_all($where = null)
    {
        $this->_getcount_common($where);
        return $this->db->count_all_results($this->_table . ' ' . $this->_table_prefix);
    }


    // --------------------------------------------------------------------------


    /**
     * Applies conditionals for other methods
     * @param  string $where  A search string compatible with CI's where() method
     * @return void
     */
    private function _getcount_common($where = null)
    {
        $this->db->join(NAILS_DB_PREFIX . 'user u', $this->_table_prefix . '.created_by = u.id', 'LEFT');
        $this->db->join(NAILS_DB_PREFIX . 'user_email ue', 'ue.user_id = u.id AND ue.is_primary = 1', 'LEFT');

        //  Set Where
        if ($where) {

            $this->db->where($where);
        }
    }


    // --------------------------------------------------------------------------


    /**
     * Return an individual event
     * @param  integer $id The event's ID
     * @return mixed       stdClass on success, FALSE on failure
     */
    public function get_by_id($id)
    {
        $this->db->where($this->_table_prefix . '.id', $id);
        $_event = $this->get_all();

        // --------------------------------------------------------------------------

        if ($_event) {

            return $_event[0];

        } else {

            $this->_set_error('No event by that ID (' . $id . ').');
            return false;
        }
    }


    // --------------------------------------------------------------------------


    /**
     * Returns events of a particular type
     * @param  string $type The type of event to return
     * @return array
     */
    public function get_by_type($type)
    {
        $this->db->where($this->_table_prefix . '.type', $type);
        return $this->get_all();
    }


    // --------------------------------------------------------------------------


    /**
     * Returns events created by a user
     * @param  integer $user_id The ID of the user
     * @return array
     */
    public function get_by_user($user_id)
    {
        $this->db->where($this->_table_prefix . '.created_by', $user_id);
        return $this->get_all();
    }


    // --------------------------------------------------------------------------


    /**
     * Returns the differing types of event
     * @return array
     */
    public function get_types()
    {
        return $this->eventTypes;
    }


    // --------------------------------------------------------------------------


    public function getType($slug) {
        return isset($this->eventTypes[$slug]) ? $this->eventTypes[$slug] : false;
    }


    // --------------------------------------------------------------------------


    /**
     * Get the differing types of event as a flat array
     * @return array
     */
    public function get_types_flat()
    {
        $_types = $this->get_types();
        $_out   = array();

        foreach ($_types as $type) {

            $_out[$type->slug] = $type->label ? $type->label : title_case(str_replace('_', ' ', $type->slug));

        }

        return $_out;
    }


    // --------------------------------------------------------------------------


    /**
     * Formats an event object
     * @param  stdClass $obj The event object to format
     * @return void
     */
    protected function _format_event_object(&$obj)
    {
        //  Ints
        $obj->ref = $obj->ref ? (int) $obj->ref : null;

        //  Type
        $obj->type              = $this->getType($obj->type);

        //  Data
        $obj->data  = unserialize($obj->data);

        //  User
        $obj->user              = new stdClass();
        $obj->user->id          = $obj->created_by;
        $obj->user->email       = $obj->email;
        $obj->user->first_name  = $obj->first_name;
        $obj->user->last_name   = $obj->last_name;
        $obj->user->profile_img = $obj->profile_img;
        $obj->user->gender      = $obj->gender;

        unset($obj->created_by);
        unset($obj->email);
        unset($obj->first_name);
        unset($obj->last_name);
        unset($obj->profile_img);
        unset($obj->gender);
    }
}

/* End of file Event.php */
/* Location: ./module-event/event/libraries/Event.php */
