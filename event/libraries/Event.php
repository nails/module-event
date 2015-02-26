<?php

/**
 * This library handles the creation and deletion of site "events"
 *
 * @package     Nails
 * @subpackage  module-event
 * @category    Library
 * @author      Nails Dev Team
 * @link
 */

class Event
{
    //  Class traits
    use NAILS_COMMON_TRAIT_ERROR_HANDLING;
    use NAILS_COMMON_TRAIT_GETCOUNT_COMMON;

    private $ci;
    private $db;
    private $user_model;
    private $eventTypes;
    private $table;
    private $tablePrefix;

    // --------------------------------------------------------------------------

    /**
     * Construct the library
     */
    public function __construct()
    {
        $this->ci         =& get_instance();
        $this->db         =& $this->ci->db;
        $this->user_model =& $this->ci->user_model;

        // --------------------------------------------------------------------------

        //  Set defaults
        $this->eventTypes    = array();
        $this->table        = NAILS_DB_PREFIX . 'event';
        $this->tablePrefix = 'e';

        // --------------------------------------------------------------------------

        //  Load helper
        $this->ci->load->helper('event');

        // --------------------------------------------------------------------------

        //  Look for email types defined by enabled modules
        $modules = _NAILS_GET_MODULES();

        foreach ($modules as $module) {

            $path = $module->path . $module->moduleName . '/config/event_types.php';

            if (file_exists($path)) {

                include $path;

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
     * Adds a new event type to the stack
     * @param  mixed $slug         The event's slug; calling code refers to events by this value. Alternatively pass a stdClass to set all values.
     * @param  string $label       The human friendly name to give the event
     * @param  string $description The human friendly description of the event's purpose
     * @param  array $hooks        An array of hooks to fire when an event is fired
     * @return boolean
     */
    public function addType($slug, $label = '', $description = '', $hooks = array())
    {
        if (empty($slug)) {

            return false;
        }

        if (is_string($slug)) {

            $this->eventTypes[$slug]              = new stdClass();
            $this->eventTypes[$slug]->slug        = $slug;
            $this->eventTypes[$slug]->label       = $label;
            $this->eventTypes[$slug]->description = $description;
            $this->eventTypes[$slug]->hooks       = $hooks;

        } else {

            if (empty($slug->slug)) {

                return false;
            }

            $this->eventTypes[$slug->slug]              = new stdClass();
            $this->eventTypes[$slug->slug]->slug        = $slug->slug;
            $this->eventTypes[$slug->slug]->label       = $slug->label;
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

        if (strtoupper(ENVIRONMENT) == 'PRODUCTION' && $this->user_model->wasAdmin()) {

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

            $createdBy = activeUser('id') ? (int) activeUser('id') : null;
        }

        // --------------------------------------------------------------------------

        //  Prep data
        $_data               = array();
        $_data['type']       = $type;
        $_data['created_by'] = $createdBy;
        $_data['url']        = uri_string();
        $_data['data']       = $data ? serialize($data) : null;
        $_data['ref']        = (int) $ref;
        $_data['ref']        = $_data['ref'] ? $_data['ref'] : null;

        $this->db->set($_data);

        if ($recorded) {

            $_data['created'] = date('Y-m-d H:i:s', strtotime($recorded));

        } else {

            $this->db->set('created', 'NOW()', false);
        }

        //  Create the event
        $this->db->insert($this->table);

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

                    $class   = new $hook['class'];
                    $reflect = new ReflectionClass($class);

                    try {

                        $method = $reflect->getMethod($hook['method']);

                    } catch (Exception $e) {

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
        $this->db->delete($this->table);

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
     * @param  array  $order The column on which to order
     * @param  array  $limit The number of items to restrict the query to
     * @param  string $where A search string compatible with CI's where() method
     * @return array
     */
    public function get_all($page = null, $perPage = null, $data = array(), $_caller = 'GET_ALL')
    {
        //  Fetch all objects from the table
        $this->db->select($this->tablePrefix . '.*');
        $this->db->select('ue.email,u.first_name,u.last_name,u.profile_img,u.gender');

        //  Apply common items; pass $data
        $this->_getcount_common_event($data, $_caller);

        // --------------------------------------------------------------------------

        //  Facilitate pagination
        if (!is_null($page)) {

            /**
             * Adjust the page variable, reduce by one so that the offset is calculated
             * correctly. Make sure we don't go into negative numbers
             */

            $page--;
            $page = $page < 0 ? 0 : $page;

            //  Work out what the offset should be
            $perPage = is_null($perPage) ? 50 : (int) $perPage;
            $offset  = $page * $perPage;

            $this->db->limit($perPage, $offset);
        }

        // --------------------------------------------------------------------------

        if (empty($data['RETURN_QUERY_OBJECT'])) {

            $events = $this->db->get($this->table . ' ' . $this->tablePrefix)->result();

            for ($i = 0; $i < count($events); $i++) {

                //  Format the object, make it pretty
                $this->_format_object($events[$i]);
            }

            return $events;

        } else {

            return $this->db->get($this->table . ' ' . $this->tablePrefix);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * This method applies the conditionals which are common across the get_*()
     * methods and the count() method.
     * @param array  $data    Data passed from the calling method
     * @param string $_caller The name of the calling method
     * @return void
     **/
    protected function _getcount_common_event($data = array(), $_caller = null)
    {
        if (!empty($data['keywords'])) {

            if (empty($data['or_like'])) {

                $data['or_like'] = array();
            }

            $toSlug = strtolower(str_replace(' ', '_', $data['keywords']));

            $data['or_like'][] = array(
                'column' => $this->tablePrefix . '.type',
                'value'  => $toSlug
            );
            $data['or_like'][] = array(
                'column' => 'ue.email',
                'value'  => $data['keywords']
            );
        }

        //  Common joins
        $this->db->join(NAILS_DB_PREFIX . 'user u', $this->tablePrefix . '.created_by = u.id', 'LEFT');
        $this->db->join(NAILS_DB_PREFIX . 'user_email ue', 'ue.user_id = u.id AND ue.is_primary = 1', 'LEFT');

        $this->_getcount_common($data, $_caller);
    }

    // --------------------------------------------------------------------------

    /**
     * Count the total number of events for a certain query
     * @return int
     */
    public function count_all($data)
    {
        $this->_getcount_common_event($data, 'COUNT_ALL');
        return $this->db->count_all_results($this->table . ' ' . $this->tablePrefix);
    }

    // --------------------------------------------------------------------------

    /**
     * Return an individual event
     * @param  integer $id The event's ID
     * @return mixed       stdClass on success, false on failure
     */
    public function get_by_id($id)
    {
        $data = array(
            'where' => array(
                array($this->tablePrefix . '.id', $id)
            )
        );
        $events = $this->get_all(null, null, $data);

        if (!$events) {

            return false;
        }

        return $events[0];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns events of a particular type
     * @param  string $type The type of event to return
     * @return array
     */
    public function get_by_type($type)
    {
        $data = array(
            'where' => array(
                array($this->tablePrefix . '.type', $type)
            )
        );
        $events = $this->get_all(null, null, $data);

        if (!$events) {

            return false;
        }

        return $events[0];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns events created by a user
     * @param  integer $userId The ID of the user
     * @return array
     */
    public function get_by_user($userId)
    {
        $data = array(
            'where' => array(
                array($this->tablePrefix . '.created_by', $userId)
            )
        );
        $events = $this->get_all(null, null, $data);

        if (!$events) {

            return false;
        }

        return $events[0];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the differing types of event
     * @return array
     */
    public function getAllTypes()
    {
        return $this->eventTypes;
    }

    // --------------------------------------------------------------------------

    /**
     * Get an individual type of event
     * @param  string $slug The event's slug
     * @return mixed        stdClass on success, false on failure
     */
    public function getTypeBySlug($slug)
    {
        return isset($this->eventTypes[$slug]) ? $this->eventTypes[$slug] : false;
    }

    // --------------------------------------------------------------------------

    /**
     * Get the differing types of event as a flat array
     * @return array
     */
    public function getAllTypesFlat()
    {
        $types = $this->getAllTypes();
        $out   = array();

        foreach ($types as $type) {

            $out[$type->slug] = $type->label ? $type->label : title_case(str_replace('_', ' ', $type->slug));
        }

        return $out;
    }

    // --------------------------------------------------------------------------

    /**
     * Formats an event object
     * @param  stdClass $obj The event object to format
     * @return void
     */
    protected function _format_object(&$obj)
    {
        //  Ints
        $obj->ref = $obj->ref ? (int) $obj->ref : null;

        //  Type
        $temp = $this->getTypeBySlug($obj->type);

        if (empty($temp)) {

            $temp = new \stdClass();
            $temp->slug        = $obj->type;
            $temp->label       = '';
            $temp->description = '';
            $temp->hooks       = array();
        }

        $obj->type = $temp;

        //  Data
        $obj->data = unserialize($obj->data);

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

    // --------------------------------------------------------------------------

    /**
     * Returns protected property $table
     * @return string
     */
    public function getTableName()
    {
        return $this->table;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns protected property $tablePrefix
     * @return string
     */
    public function getTablePrefix()
    {
        return $this->tablePrefix;
    }
}
