<?php
namespace izzum\statemachine\persistence;
use izzum\statemachine\loader\Loader;
use izzum\statemachine\StateMachine;
use izzum\statemachine\Identifier;
use izzum\statemachine\loader\LoaderArray;
use izzum\statemachine\loader\LoaderData;
use izzum\statemachine\Exception;
use izzum\statemachine\Transition;
use izzum\statemachine\State;

/**
 * A persistence adapter/loader specifically for the PHP Data Objects (PDO)
 * extension.
 * PDO use database drivers for different backend implementations (postgres,
 * mysql, sqlite, MSSQL, oracle etc).
 * By providing a DSN connection string, you can connect to different backends.
 *
 * This specific adapter uses the schema as defined in
 * - /assets/sql/postgres.sql
 * - /assets/sql/sqlite.sql
 * - /assets/sql/mysql.sql
 * but can be used on tables for a different database vendor as long as
 * the table names, fields and constraints are the same. Optionally,
 * a prefix can be set.
 *
 * This Adapter does double duty as a Loader since both use the
 * same backend. You could use two seperate classes for this, but you can
 * implement both in one class.
 * Loader::load(), Adapter::getEntityIds(), Adapter::processGetState()
 * Adapter::processSetState(), Adapter::add()
 *
 * This is not a highly optimized adapter, but serves as something you can use
 * out of the box. If you need an adapter more specialized to your
 * needs/framework/system you can easily write one yourself.
 *
 * More functionality related to a backend can be implemented here:
 * - get the history of transitions from the history table
 * - get the factories for machines from the machine table
 *
 * @link http://php.net/manual/en/pdo.drivers.php
 * @link http://php.net/manual/en/book.pdo.php
 *      
 * @author rolf
 */
class PDO extends Adapter implements Loader {
    
    /**
     * the pdo connection string
     * 
     * @var string
     */
    private $dsn;
    
    /**
     *
     * @var string
     */
    private $user;
    /**
     *
     * @var string
     */
    private $password;
    /**
     * pdo options
     * 
     * @var array
     */
    private $options;
    
    /**
     * the locally cached connections
     * 
     * @var \PDO
     */
    private $connection;
    
    /**
     * table prefix
     * 
     * @var string
     */
    private $prefix = '';

    /**
     *
     * @param string $dsn
     *            a PDO data source name
     *            example: 'pgsql:host=localhost;port=5432;dbname=izzum'
     * @param string $user
     *            optional, defaults to null
     * @param string $password
     *            optional, defaults to null
     * @param array $options
     *            optional, defaults to empty array.
     * @link http://php.net/manual/en/pdo.connections.php
     */
    public function __construct($dsn, $user = null, $password = null, $options = array())
    {
        $this->dsn = $dsn;
        $this->user = $user;
        $this->password = $password;
        $this->options = $options;
    }

    /**
     * get the connection to a database via the PDO adapter.
     * The connection retrieved will be reused if one already exists.
     * 
     * @return \PDO
     * @throws Exception
     */
    protected function getConnection()
    {
        try {
            if ($this->connection === null) {
                $this->connection = new \PDO($this->dsn, $this->user, $this->password, $this->options);
                $this->setupConnection($this->connection);
            }
            return $this->connection;
        } catch(\Exception $e) {
            throw new Exception(sprintf("error creating PDO [%s], message: [%s]", $this->dsn, $e->getMessage()), Exception::PERSISTENCE_FAILED_TO_CONNECT);
        }
    }

    protected function setupConnection(\PDO $connection)
    {
    /**
     * hook, override to:
     * - set schema on postgresql
     * - set PRAGMA on sqlite
     * - etc..
     * whatever is the need to do a setup on, the first time
     * you create a connection
     * - SET UTF-8 on mysql can be done with an option in the $options
     * constructor argument
     */
    }

    /**
     *
     * @return string the type of backend we connect to
     */
    public function getType()
    {
        $index = strpos($this->dsn, ":");
        return $index ? substr($this->dsn, 0, $index) : $this->dsn;
    }

    /**
     * set the table prefix to be used
     * 
     * @param string $prefix            
     */
    public final function setPrefix($prefix)
    {
        $this->prefix = $prefix;
    }

    /**
     * get the table prefix
     * 
     * @return string
     */
    public final function getPrefix()
    {
        return $this->prefix;
    }
    
    /**
     * Load the statemachine with data.
     * This is an implemented method from the Loader interface.
     * All other methods are actually implemented methods from the Adapter
     * class.
     *
     * @param StateMachine $statemachine
     */
    public function load(StateMachine $statemachine)
    {
        $data = $this->getLoaderData($statemachine->getContext()->getMachine());
        // delegate to LoaderArray
        $loader = new LoaderArray($data);
        $loader->load($statemachine);
    }

    /**
     * implementation of the hook in the Adapter::getState() template method
     * 
     * @param Identifier $identifier            
     * @param string $state            
     */
    public function processGetState(Identifier $identifier)
    {
        $connection = $this->getConnection();
        $prefix = $this->getPrefix();
        try {
            $query = 'SELECT state FROM ' . $prefix . 'statemachine_entities WHERE machine = ' . ':machine AND entity_id = :entity_id';
            $statement = $connection->prepare($query);
            $statement->bindParam(":machine", $identifier->getMachine());
            $statement->bindParam(":entity_id", $identifier->getEntityId());
            $result = $statement->execute();
            if ($result === false) {
                throw new Exception($this->getErrorInfo($statement));
            }
        } catch(\Exception $e) {
            throw new Exception(sprintf('query for getting current state failed: [%s]', $e->getMessage()), Exception::PERSISTENCE_LAYER_EXCEPTION);
        }
        $row = $statement->fetch();
        if ($row === false) {
            return State::STATE_UNKNOWN;
            throw new Exception(sprintf('no state found for [%s]. ' . 'Did you add it to the persistence layer?', $identifier->toString()), Exception::PERSISTENCE_LAYER_EXCEPTION);
        }
        return $row ['state'];
    }

    /**
     * implementation of the hook in the Adapter::setState() template method
     * 
     * @param Identifier $identifier            
     * @param string $state            
     * @return boolean true if not already present, false if stored before
     */
    public function processSetState(Identifier $identifier, $state)
    {
        if ($this->isPersisted($identifier)) {
            $this->updateState($identifier, $state);
            return false;
        } else {
            $this->insertState($identifier, $state);
            return true;
        }
    }

    /**
     * adds state info to the persistance layer.
     * Thereby marking the time when the object was created.
     * 
     * @param Identifier $identifier            
     * @return boolean
     */
    public function add(Identifier $identifier, $state)
    {
        if ($this->isPersisted($identifier)) {
            return false;
        }
        $this->insertState($identifier, $state);
        return true;
    }
    
    /**
     * Stores a failed transition in the storage facility.
     *
     * @param Identifier $identifier
     * @param Transition $transition
     * @param \Exception $e
     */
    public function setFailedTransition(Identifier $identifier, Transition $transition, \Exception $e)
    {
        // check if it is persisted, otherwise we cannot get the current state
        if ($this->isPersisted($identifier)) {
            $message = new \stdClass();
            $message->code = $e->getCode();
            $message->transition = $transition->getName();
            $message->message = $e->getMessage();
            $message->file = $e->getFile();
            $message->line = $e->getLine();
            $state = $this->getState($identifier);
            $message->state = $state;
            // convert to json for storage (text field with json can be searched
            // via sql)
            $json = json_encode($message);
            $this->addHistory($identifier, $state, $json, true);
        }
    }
    
    /**
     *
     * @param string $machine
     *            the machine to get the names for
     * @param string $state
     * @return string[] an array of entity ids
     * @throws Exception
     */
    public function getEntityIds($machine, $state = null)
    {
        $connection = $this->getConnection();
        $prefix = $this->getPrefix();
        $query = 'SELECT se.entity_id FROM ' . $prefix . 'statemachine_entities AS se
                JOIN ' . $prefix . 'statemachine_states AS ss ON (se.state = ss.state AND
                se.machine = ss.machine) WHERE se.machine = :machine';
        $output = array();
        try {
            if ($state != null) {
                $query .= ' AND se.state = :state';
            }
            $statement = $connection->prepare($query);
            $statement->bindParam(":machine", $machine);
            if ($state != null) {
                $statement->bindParam(":state", $state);
            }
    
            $result = $statement->execute();
            if ($result === false) {
                throw new Exception($this->getErrorInfo($statement));
            }
    
            $rows = $statement->fetchAll();
            if ($rows === false) {
                throw new Exception("failed getting rows: " . $this->getErrorInfo($statement));
            }
    
            foreach ($rows as $row) {
                $output [] = $row ['entity_id'];
            }
        } catch(\Exception $e) {
            throw new Exception($e->getMessage(), Exception::PERSISTENCE_LAYER_EXCEPTION, $e);
        }
        return $output;
    }

    /**
     * is the state information already persisted?
     * 
     * @param Identifier $identifier            
     * @return boolean
     * @throws Exception
     */
    public function isPersisted(Identifier $identifier)
    {
        $connection = $this->getConnection();
        $prefix = $this->getPrefix();
        try {
            $query = 'SELECT entity_id FROM ' . $prefix . 'statemachine_entities WHERE ' . 'machine = :machine AND entity_id = :entity_id';
            $statement = $connection->prepare($query);
            $statement->bindParam(":machine", $identifier->getMachine());
            $statement->bindParam(":entity_id", $identifier->getEntityId());
            $result = $statement->execute();
            if ($result === false) {
                throw new Exception($this->getErrorInfo($statement));
            }
            
            $row = $statement->fetch();
            
            if ($row === false) {
                return false;
            }
            return ($row ['entity_id'] == $identifier->getEntityId());
        } catch(\Exception $e) {
            throw new Exception(sprintf('query for getting persistence info failed: [%s]', $e->getMessage()), Exception::PERSISTENCE_LAYER_EXCEPTION);
        }
    }

    /**
     * insert state for context into persistance layer.
     * This method is public for testing purposes
     * 
     * @param Identifier $identifier            
     * @param string $state            
     */
    public function insertState(Identifier $identifier, $state)
    {
        
        // add a history record
        $this->addHistory($identifier, $state);
        
        $connection = $this->getConnection();
        $prefix = $this->getPrefix();
        try {
            $query = 'INSERT INTO ' . $prefix . 'statemachine_entities
                (machine, entity_id, state, changetime)
                    VALUES
                (:machine, :entity_id, :state, :timestamp)';
            $statement = $connection->prepare($query);
            $statement->bindParam(":machine", $identifier->getMachine());
            $statement->bindParam(":entity_id", $identifier->getEntityId());
            $statement->bindParam(":state", $state);
            $statement->bindParam(":timestamp", $this->getTimestampForDriver());
            $result = $statement->execute();
            if ($result === false) {
                throw new Exception($this->getErrorInfo($statement));
            }
        } catch(\Exception $e) {
            throw new Exception(sprintf('query for inserting state failed: [%s]', $e->getMessage()), Exception::PERSISTENCE_LAYER_EXCEPTION);
        }
    }

    protected function getErrorInfo(\PDOStatement $statement)
    {
        $info = $statement->errorInfo();
        $output = sprintf("%s - message: '%s'", $info [0], $info [2]);
        return $output;
    }

    /**
     * hook method
     * 
     * @return number|string
     */
    protected function getTimestampForDriver()
    {
        // yuk, seems postgres and sqlite need some different input.
        // maybe other drivers too. so therefore this hook method.
        if (strstr($this->dsn, 'sqlite:')) {
            return time(); // "CURRENT_TIMESTAMP";//"DateTime('now')";
        }
        // might have to be overriden for certain drivers.
        return 'now'; // now, CURRENT_TIMESTAMP
    }

    /**
     * hook method.
     * not all drivers have the same boolean datatype. convert here.
     * 
     * @param boolean $boolean            
     * @return boolean|int|string
     */
    protected function getBooleanForDriver($boolean)
    {
        if (strstr($this->dsn, 'sqlite:') || strstr($this->dsn, 'mysql:')) {
            return $boolean ? 1 : 0;
        }
        // might have to be overriden for certain drivers.
        return $boolean;
    }

    /**
     * update state for context into persistance layer
     * This method is public for testing purposes
     * 
     * @param Identifier $identifier            
     * @param string $state            
     * @throws Exception
     */
    public function updateState(Identifier $identifier, $state)
    {
        // add a history record
        $this->addHistory($identifier, $state);
        
        $connection = $this->getConnection();
        $prefix = $this->getPrefix();
        try {
            $query = 'UPDATE ' . $prefix . 'statemachine_entities SET state = :state, 
                changetime = :timestamp WHERE entity_id = :entity_id 
                AND machine = :machine';
            $statement = $connection->prepare($query);
            $statement->bindParam(":machine", $identifier->getMachine());
            $statement->bindParam(":entity_id", $identifier->getEntityId());
            $statement->bindParam(":state", $state);
            $statement->bindParam(":timestamp", $this->getTimestampForDriver());
            $result = $statement->execute();
            if ($result === false) {
                throw new Exception($this->getErrorInfo($statement));
            }
        } catch(\Exception $e) {
            throw new Exception(sprintf('query for updating state failed: [%s]', $e->getMessage()), Exception::PERSISTENCE_LAYER_EXCEPTION);
        }
    }

    /**
     * Adds a history record for a transition
     * 
     * @param Identifier $identifier            
     * @param string $state            
     * @param string $message
     *            an optional message (which might be exception data or not).
     * @param boolean $is_exception
     *            an optional value, specifying if there was something
     *            exceptional or not.
     *            this can be used to signify an exception for storage in the
     *            backend so we can analyze the history
     *            for regular transitions and failed transitions
     * @throws Exception
     */
    public function addHistory(Identifier $identifier, $state, $message = null, $is_exception = false)
    {
        $connection = $this->getConnection();
        $prefix = $this->getPrefix();
        try {
            $query = 'INSERT INTO ' . $prefix . 'statemachine_history
                    (machine, entity_id, state, message, changetime, exception)
                        VALUES
                    (:machine, :entity_id, :state, :message, :timestamp, :exception)';
            $statement = $connection->prepare($query);
            $statement->bindParam(":machine", $identifier->getMachine());
            $statement->bindParam(":entity_id", $identifier->getEntityId());
            $statement->bindParam(":state", $state);
            $statement->bindParam(":message", $message);
            $statement->bindParam(":timestamp", $this->getTimestampForDriver());
            $statement->bindParam(":exception", $this->getBooleanForDriver($is_exception));
            $result = $statement->execute();
            if ($result === false) {
                throw new Exception($this->getErrorInfo($statement));
            }
        } catch(\Exception $e) {
            throw new Exception(sprintf('query for updating state failed: [%s]', $e->getMessage()), Exception::PERSISTENCE_LAYER_EXCEPTION);
        }
    }

    /**
     * get all the ordered transition and state information for a specific
     * machine.
     * This method is public for testing purposes
     * 
     * @param string $machine            
     * @return [][] resultset from postgres
     * @throws Exception
     */
    public function getTransitions($machine)
    {
        $connection = $this->getConnection();
        $prefix = $this->getPrefix();
        $query = 'SELECT st.machine, 
                        st.state_from AS state_from, st.state_to AS state_to, 
                        st.rule, st.command,
                        ss_to.type AS state_to_type, 
        				ss_to.exit_command as state_to_exit_command,
        				ss_to.entry_command as state_to_entry_command, 
        				ss.type AS state_from_type, 
        				ss.exit_command as state_from_exit_command,
        				ss.entry_command as state_from_entry_command,
                        st.priority, 
                        ss.description AS state_from_description,
                        ss_to.description AS state_to_description,
                        st.description AS transition_description,
        				st.event
                    FROM  ' . $prefix . 'statemachine_transitions AS st
                    LEFT JOIN
                        ' . $prefix . 'statemachine_states AS ss
                        ON (st.state_from = ss.state AND st.machine = ss.machine)
                    LEFT JOIN
                        ' . $prefix . 'statemachine_states AS ss_to
                        ON (st.state_to = ss_to.state AND st.machine = ss_to.machine)
                    WHERE
                        st.machine = :machine
                    ORDER BY 
                        st.state_from ASC, st.priority ASC, st.state_to ASC';
        try {
            $statement = $connection->prepare($query);
            $statement->bindParam(":machine", $machine);
            $result = $statement->execute();
            
            if ($result === false) {
                throw new Exception($this->getErrorInfo($statement));
            }
            $rows = $statement->fetchAll();
        } catch(\Exception $e) {
            throw new Exception($e->getMessage(), Exception::PERSISTENCE_LAYER_EXCEPTION, $e);
        }
        
        return $rows;
    }

    /**
     * gets all data for transitions.
     * This method is public for testing purposes
     * 
     * @param string $machine
     *            the machine name
     * @return Transition[]
     */
    public function getLoaderData($machine)
    {
        $rows = $this->getTransitions($machine);
        
        $output = array();
        // array for local caching of states
        $states = array();
        
        foreach ($rows as $row) {
            $state_from = $row ['state_from'];
            $state_to = $row ['state_to'];
            
            // create the 'from' state
            if (isset($states [$state_from])) {
                $from = $states [$state_from];
            } else {
                $from = new State($row ['state_from'], $row ['state_from_type'], $row ['state_from_entry_command'], $row ['state_from_exit_command']);
                $from->setDescription($row ['state_from_description']);
            }
            // cache the 'from' state for the next iterations
            $states [$from->getName()] = $from;
            
            // create the 'to' state
            if (isset($states [$state_to])) {
                $to = $states [$state_to];
            } else {
                $to = new State($row ['state_to'], $row ['state_to_type'], $row ['state_to_entry_command'], $row ['state_to_exit_command']);
                $to->setDescription($row ['state_to_description']);
            }
            // cache to 'to' state for the next iterations
            $states [$to->getName()] = $to;
            
            // build the transition
            $transition = new Transition($from, $to, $row ['event'], $row ['rule'], $row ['command']);
            $transition->setDescription($row ['transition_description']);
            
            $output [] = $transition;
        }
        
        return $output;
    }

    /**
     * do some cleanup
     */
    public function __destruct()
    {
        $this->connection = null;
    }
}