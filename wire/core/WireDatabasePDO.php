<?php namespace ProcessWire;

/**
 * ProcessWire PDO Database
 *
 * Serves as a wrapper to PHP's PDO class
 * 
 * 
 * ProcessWire 3.x, Copyright 2016 by Ryan Cramer
 * https://processwire.com
 *
 */

/**
 * Database class provides a layer on top of mysqli
 * 
 * #pw-summary All database operations in ProcessWire are performed via this PDO-style database class.
 * 
 * @method void unknownColumnError($column) #pw-internal
 *
 */
class WireDatabasePDO extends Wire implements WireDatabase {

	/**
	 * Log of all queries performed in this instance
	 *
	 */
	protected $queryLog = array();

	/**
	 * Max queries allowedin the query log (set from $config->dbQueryLogMax)
	 * 
	 * @var int
	 * 
	 */
	protected $queryLogMax = 500;

	/**
	 * Whether queries will be logged
	 * 
	 */
	protected $debugMode = false;

	/**
	 * Cached result from getTables() method
	 * 
	 * @var array
	 * 
	 */
	protected $tablesCache = array();

	/**
	 * Instance of PDO
	 * 
	 * @var \PDO
	 * 
	 */
	protected $pdo = null;

	/**
	 * Whether or not our _init() has been called for the current $pdo connection
	 * 
	 * @var bool
	 * 
	 */
	protected $init = false;

	/**
	 * Strip 4-byte characters in “quote” and “escapeStr” methods? (only when dbEngine is not utf8mb4)
	 * 
	 * @var bool
	 * 
	 */
	protected $stripMB4 = false;

	/**
	 * Lowercase value of $config->dbEngine
	 * 
	 * @var string
	 * 
	 */
	protected $engine = '';

	/**
	 * Lowercase value of $config->dbCharset
	 * 
	 * @var string
	 * 
	 */
	protected $charset = '';

	/**
	 * Substitute variable names according to engine as used by getVariable() method
	 * 
	 * @var array
	 * 
	 */
	protected $subVars = array(
		'myisam' => array(),
		'innodb' => array(
			'ft_min_word_len' => 'innodb_ft_min_token_size',
			'ft_max_word_len' => 'innodb_ft_max_token_size',
		),
	);

	/**
	 * PDO connection settings
	 * 
	 */
	private $pdoConfig = array(
		'dsn' => '', 
		'user' => '',
		'pass' => '', 	
		'options' => '',
	);

	/**
	 * Cached values from getVariable method
	 * 
	 * @var array associative of name => value
	 * 
	 */
	protected $variableCache = array();

	/**
	 * Cached InnoDB stopwords (keys are the stopwords and values are irrelevant)
	 * 
	 * @var array|null Becomes array once loaded
	 * 
	 */
	protected $stopwordCache = null;

	/**
	 * Create a new PDO instance from ProcessWire $config API variable
	 * 
	 * If you need to make other PDO connections, just instantiate a new WireDatabasePDO (or native PDO)
	 * rather than calling this getInstance method. 
	 * 
	 * #pw-internal
	 * 
	 * @param Config $config
	 * @return WireDatabasePDO 
	 * @throws WireException
	 * 
	 */
	public static function getInstance(Config $config) {

		if(!class_exists('\PDO')) {
			throw new WireException('Required PDO class (database) not found - please add PDO support to your PHP.'); 
		}

		$host = $config->dbHost;
		$username = $config->dbUser;
		$password = $config->dbPass;
		$name = $config->dbName;
		$socket = $config->dbSocket; 
		$charset = $config->dbCharset;
		$options = $config->dbOptions;
		
		$initCommand = str_replace('{charset}', $charset, $config->dbInitCommand);
		
		if($socket) {
			// if socket is provided ignore $host and $port and use $socket instead:
			$dsn = "mysql:unix_socket=$socket;dbname=$name;";
		} else {
			$dsn = "mysql:dbname=$name;host=$host";
			$port = $config->dbPort;
			if($port) $dsn .= ";port=$port";
		}
		
		if(!is_array($options)) $options = array();
	
		if(!isset($options[\PDO::ATTR_ERRMODE])) {
			$options[\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;
		}
		
		if($initCommand && !isset($options[\PDO::MYSQL_ATTR_INIT_COMMAND])) {
			$options[\PDO::MYSQL_ATTR_INIT_COMMAND] = $initCommand;
		}
		
		$database = new WireDatabasePDO($dsn, $username, $password, $options); 
		$database->setDebugMode($config->debug);
		$config->wire($database);
		$database->_init();
	
		return $database;
	}

	/**
	 * Construct WireDatabasePDO
	 * 
	 * #pw-internal
	 * 
	 * @param $dsn
	 * @param null $username
	 * @param null $password
	 * @param array $driver_options
	 * 
	 */
	public function __construct($dsn, $username = null, $password = null, array $driver_options = array()) {
		parent::__construct();
		$this->pdoConfig['dsn'] = $dsn; 
		$this->pdoConfig['user'] = $username;
		$this->pdoConfig['pass'] = $password; 
		$this->pdoConfig['options'] = $driver_options; 
		$this->pdo();
	}

	/**
	 * Additional initialization after DB connection established and Wire instance populated
	 * 
	 * #pw-internal
	 * 
	 */
	public function _init() {
		if($this->init || !$this->isWired()) return;
		$this->init = true; 
		$config = $this->wire()->config;
		$this->stripMB4 = $config->dbStripMB4 && strtolower($config->dbEngine) != 'utf8mb4';
		$this->engine = strtolower($config->dbEngine);
		$this->charset = strtolower($config->dbCharset);
		$this->queryLogMax = (int) $config->dbQueryLogMax;
		if($config->debug && $this->pdo) {
			// custom PDO statement for debug mode
			$this->debugMode = true;
			$this->pdo->setAttribute(
				\PDO::ATTR_STATEMENT_CLASS, 
				array(__NAMESPACE__ . "\\WireDatabasePDOStatement", array($this))
			);
		}
		$sqlModes = $config->dbSqlModes;
		if(is_array($sqlModes)) {
			// ["5.7.0" => "remove:mode1,mode2/add:mode3"]
			foreach($sqlModes as $minVersion => $commands) {
				if(strpos($commands, '/') !== false) {
					$commands = explode('/', $commands);
				} else {
					$commands = array($commands);
				}
				foreach($commands as $modes) {
					$modes = trim($modes);
					if(empty($modes)) continue;
					$action = 'set';
					if(strpos($modes, ':')) list($action, $modes) = explode(':', $modes);
					$this->sqlMode(trim($action), trim($modes), $minVersion);
				}
			}
		}
	}

	/**
	 * Return the actual current PDO connection instance
	 *
	 * If connection is lost, this will restore it automatically. 
	 * 
	 * #pw-group-PDO
	 *
	 * @return \PDO
	 *
	 */
	public function pdo() {
		if(!$this->pdo) {
			$this->init = false;
			$this->pdo = new \PDO(
				$this->pdoConfig['dsn'],
				$this->pdoConfig['user'],
				$this->pdoConfig['pass'],
				$this->pdoConfig['options']
			);
		}
		if(!$this->init) $this->_init();
		return $this->pdo;
	}

	/**
	 * Fetch the SQLSTATE associated with the last operation on the statement handle
	 * 
	 * #pw-group-PDO
	 * 
	 * @return string
	 * @link http://php.net/manual/en/pdostatement.errorcode.php
	 * 
	 */
	public function errorCode() {
		return $this->pdo()->errorCode();
	}

	/**
	 * Fetch extended error information associated with the last operation on the database handle
	 * 
	 * #pw-group-PDO
	 * 
	 * @return array
	 * @link http://php.net/manual/en/pdo.errorinfo.php
	 * 
	 */
	public function errorInfo() {
		return $this->pdo()->errorInfo();
	}

	/**
	 * Retrieve a database connection attribute
	 * 
	 * #pw-group-PDO
	 * 
	 * @param int $attribute
	 * @return mixed
	 * @link http://php.net/manual/en/pdo.getattribute.php
	 * 
	 */
	public function getAttribute($attribute) {
		return $this->pdo()->getAttribute($attribute); 
	}

	/**
	 * Sets an attribute on the database handle
	 * 
	 * #pw-group-PDO
	 * 
	 * @param int $attribute
	 * @param mixed $value
	 * @return bool
	 * @link http://php.net/manual/en/pdo.setattribute.php
	 * 
	 */
	public function setAttribute($attribute, $value) {
		return $this->pdo()->setAttribute($attribute, $value); 
	}

	/**
	 * Returns the ID of the last inserted row or sequence value
	 * 
	 * #pw-group-PDO
	 * 
	 * @param string|null $name
	 * @return string
	 * @link http://php.net/manual/en/pdo.lastinsertid.php
	 * 
	 */
	public function lastInsertId($name = null) {
		return $this->pdo()->lastInsertId($name); 
	}

	/**
	 * Executes an SQL statement, returning a result set as a PDOStatement object
	 * 
	 * #pw-group-PDO
	 * 
	 * @param string $statement
	 * @param string $note
	 * @return \PDOStatement
	 * @link http://php.net/manual/en/pdo.query.php
	 * 
	 */
	public function query($statement, $note = '') {
		if($this->debugMode) $this->queryLog($statement, $note); 
		return $this->pdo()->query($statement); 
	}

	/**
	 * Initiates a transaction
	 * 
	 * #pw-group-PDO
	 * 
	 * @return bool
	 * @link http://php.net/manual/en/pdo.begintransaction.php
	 * 
	 */
	public function beginTransaction() {
		return $this->pdo()->beginTransaction();
	}

	/**
	 * Checks if inside a transaction
	 * 
	 * #pw-group-PDO
	 * 
	 * @return bool
	 * @link http://php.net/manual/en/pdo.intransaction.php
	 * 
	 */
	public function inTransaction() {
		return (bool) $this->pdo()->inTransaction();
	}

	/**
	 * Are transactions available with current DB engine (or table)?
	 * 
	 * #pw-group-PDO
	 * 
	 * @param string $table Optionally specify a table to specifically check to that table
	 * @return bool
	 * 
	 */
	public function supportsTransaction($table = '') {
		$engine = '';
		if($table) {
			$query = $this->prepare('SHOW TABLE STATUS WHERE name=:name'); 
			$query->bindValue(':name', $table); 
			$query->execute();
			if($query->rowCount()) {
				$row = $query->fetch(\PDO::FETCH_ASSOC);
				$engine = empty($row['engine']) ? '' : $row['engine'];
			}
			$query->closeCursor();
		} else {
			$engine = $this->wire('config')->dbEngine;
		}
		return strtoupper($engine) === 'INNODB';
	}

	/**
	 * Allow a new transaction to begin right now? (i.e. supported and not already in one)
	 * 
	 * Returns combined result of supportsTransaction() === true and inTransaction() === false.
	 * 
	 * #pw-group-PDO
	 * 
	 * @param string $table Optional table that transaction will be for
	 * @return bool
	 * @since 3.0.140
	 * 
	 */
	public function allowTransaction($table = '') {
		return $this->supportsTransaction($table) && !$this->inTransaction();
	}

	/**
	 * Commits a transaction
	 * 
	 * #pw-group-PDO
	 * 
	 * @return bool
	 * @link http://php.net/manual/en/pdo.commit.php
	 * 
	 */
	public function commit() {
		return $this->pdo()->commit();
	}

	/**
	 * Rolls back a transaction
	 * 
	 * #pw-group-PDO
	 * 
	 * @return bool
	 * @link http://php.net/manual/en/pdo.rollback.php
	 * 
	 */
	public function rollBack() {
		return $this->pdo()->rollBack();
	}

	/**
	 * Get an array of all queries that have been executed thus far
	 *
	 * Active in ProcessWire debug mode only
	 * 
	 * #pw-internal
	 *
	 * @deprecated use queryLog() method instead
	 * @return array
	 *
	 */
	static public function getQueryLog() {
		/** @var WireDatabasePDO $database */
		$database = wire('database');
		return $database->queryLog();
	}

	/**
	 * Prepare an SQL statement for accepting bound parameters
	 * 
	 * #pw-group-PDO
	 * 
	 * @param string $statement
	 * @param array|string $driver_options Driver options array or you may specify $note here
	 * @param string $note Debug notes to save with query in debug mode
	 * @return \PDOStatement
	 * @link http://php.net/manual/en/pdo.prepare.php
	 * 
	 */
	public function prepare($statement, $driver_options = array(), $note = '') {
		if(is_string($driver_options)) {
			$note = $driver_options; 
			$driver_options = array();
		}
		$pdoStatement = $this->pdo()->prepare($statement, $driver_options);
		if($this->debugMode) {
			if($pdoStatement instanceof WireDatabasePDOStatement) {
				/** @var WireDatabasePDOStatement $pdoStatement */
				$pdoStatement->setDebugNote($note);
			} else {
				$this->queryLog($statement, $note);
			}
		}
		return $pdoStatement;
	}

	/**
	 * Execute an SQL statement string
	 * 
	 * If given a PDOStatement, this method behaves the same as the execute() method. 
	 * 
	 * #pw-group-PDO
	 * 
	 * @param string|\PDOStatement $statement
	 * @param string $note
	 * @return bool|int
	 * @throws \PDOException
	 * @link http://php.net/manual/en/pdo.exec.php
	 * 
	 */
	public function exec($statement, $note = '') {
		if(is_object($statement) && $statement instanceof \PDOStatement) {
			return $this->execute($statement);
		}
		if($this->debugMode) $this->queryLog($statement, $note); 
		return $this->pdo()->exec($statement);
	}
	
	/**
	 * Execute a PDO statement, with retry and error handling
	 * 
	 * Given a PDOStatement ($query) this method will execute the statement and return
	 * true or false as to whether it was successful. 
	 * 
	 * Unlike other PDO methods, this one (native to ProcessWire) will retry queries
	 * if they failed due to a lost connection. By default it will retry up to 3 times,
	 * but you can adjust this number as needed in the arguments. 
	 * 
	 * ~~~~~
	 * // prepare the query
	 * $query = $database->prepare("SELECT id, name FROM pages LIMIT 10");
	 * // you can do the following, rather than native PDO $query->execute(); 
	 * $database->execute($query);
	 * ~~~~~
	 * 
	 * #pw-group-custom
	 *
	 * @param \PDOStatement $query
	 * @param bool $throw Whether or not to throw exception on query error (default=true)
	 * @param int $maxTries Max number of times it will attempt to retry query on error
	 * @return bool True on success, false on failure. Note if you want this, specify $throw=false in your arguments.
	 * @throws \PDOException
	 *
	 */
	public function execute(\PDOStatement $query, $throw = true, $maxTries = 3) {

		$tryAgain = 0;
		$_throw = $throw;

		do {
			try {
				$result = $query->execute();

			} catch(\PDOException $e) {

				$result = false;
				$error = $e->getMessage();
				$throw = false; // temporarily disable while we try more

				if($tryAgain === 0) {
					// setup retry loop
					$tryAgain = $maxTries;
				} else {
					// decrement retry loop
					$tryAgain--;
				}

				if(stripos($error, 'MySQL server has gone away') !== false) {
					// forces reconection on next query
					$this->wire('database')->closeConnection();

				} else if($query->errorCode() == '42S22') {
					// unknown column error
					$errorInfo = $query->errorInfo();
					if(preg_match('/[\'"]([_a-z0-9]+\.[_a-z0-9]+)[\'"]/i', $errorInfo[2], $matches)) {
						$this->unknownColumnError($matches[1]);
					}

				} else {
					// some other error that we don't have retry plans for
					// tryAgain=0 will force the loop to stop
					$tryAgain = 0;
				}

				if($tryAgain < 1) {
					// if at end of retry loop, restore original throw state
					$throw = $_throw;
				}

				if($throw) {
					throw $e;
				} else {
					$this->error($error);
				}
			}

		} while($tryAgain && !$result);

		return $result;
	}

	/**
	 * Hookable method called by execute() method when query encounters an unknown column
	 * 
	 * #pw-internal
	 *
	 * @param string $column Column format tableName.columnName
	 *
	 */
	protected function ___unknownColumnError($column) { }

	/**
	 * Log a query, or return logged queries
	 * 
	 * - To log a query, provide the $sql argument containing the query (string). 
	 * - To retrieve the query log, call this method with no arguments. 
	 * - Note the core only populates the query log when `$config->debug` mode is active.
	 * 
	 * #pw-group-custom
	 * 
	 * @param string $sql Query (string) to log
	 * @param string $note Any additional debugging notes about the query
	 * @return array|bool|int Returns query log array, boolean true if added, boolean false if not
	 * 
	 */
	public function queryLog($sql = '', $note = '') {
		if(empty($sql)) return $this->queryLog;
		if(!$this->debugMode) return false;
		if(count($this->queryLog) > $this->queryLogMax) {
			if(isset($this->queryLog['error'])) {
				$qty = (int) $this->queryLog['error'];
			} else {
				$qty = 0;
			}
			$qty++;
			$this->queryLog['error'] = "$qty additional queries omitted because \$config->dbQueryLogMax = $this->queryLogMax";
			return false;
		} else {
			$this->queryLog[] = $sql . ($note ? " -- $note" : "");
			return true;
		}
	}

	/**
	 * Get array of all tables in this database.
	 * 
	 * Note that this method caches its result unless you specify boolean false for the $allowCache argument. 
	 * 
	 * #pw-group-custom
	 *
	 * @param bool $allowCache Specify false if you don't want result to be cached or pulled from cache (default=true)
	 * @return array Returns array of table names
	 *
	 */
	public function getTables($allowCache = true) {
		if($allowCache && count($this->tablesCache)) return $this->tablesCache;
		$tables = array();
		$query = $this->query("SHOW TABLES");
		/** @noinspection PhpAssignmentInConditionInspection */
		while($col = $query->fetchColumn()) $tables[] = $col;
		if($allowCache) $this->tablesCache = $tables;
		return $tables; 
	}

	/**
	 * Does the given table exist in this database?
	 * 
	 * #pw-group-custom
	 * 
	 * @param string $table
	 * @return bool
	 * @since 3.0.133
	 * 
	 */
	public function tableExists($table) {
		$query = $this->prepare('SHOW TABLES LIKE ?');
		$query->execute(array($table));
		$result = $query->fetchColumn();
		return !empty($result);
	}

	/**
	 * Does the given column exist in given table? 
	 * 
	 * ~~~~~
	 * // Standard usage:
	 * if($database->columnExists('pages', 'name')) {
	 *   echo "The pages table has a 'name' column";
	 * }
	 * 
	 * // You can also bundle table and column together:
	 * if($database->columnExists('pages.name')) {
	 *   echo "The pages table has a 'name' column";
	 * }
	 * 
	 * $exists = $database->columnExists('pages', 'name', true); 
	 * if($exists) {
	 *   // associative array with indexes: Name, Type, Null, Key, Default, Extra
	 *   echo "The pages table has a 'name' column and here is verbose info: ";
	 *   print_r($exists); 
	 * }
	 * ~~~~~
	 *
	 * #pw-group-custom
	 * 
	 * @param string $table Specify table name (or table and column name in format "table.column").
	 * @param string $column Specify column name (or omit or blank string if already specified in $table argument). 
	 * @param bool $getInfo Return array of column info (with type info, etc.) rather than boolean?
	 * @return bool|array
	 * @since 3.0.154
	 * @throws WireDatabaseException
	 * 
	 */
	public function columnExists($table, $column = '', $getInfo = false) {
		if(empty($column)) {
			if(!strpos($table, '.')) throw new WireDatabaseException('No column specified');
			list($table, $column) = explode('.', $table, 2);
		}
		$exists = false;
		$table = $this->escapeTable($table);
		try {
			$query = $this->prepare("SHOW COLUMNS FROM `$table` WHERE Field=:column");
			$query->bindValue(':column', $column, \PDO::PARAM_STR);
			$query->execute();
			$numRows = (int) $query->rowCount();
			if($numRows) $exists = $getInfo ? $query->fetch(\PDO::FETCH_ASSOC) : true;
			$query->closeCursor();
		} catch(\Exception $e) {
			// most likely given table does not exist
			$exists = false;
		}
		return $exists;
	}

	/**
	 * Is the given string a database comparison operator?
	 * 
	 * #pw-group-custom
	 * 
	 * ~~~~~
	 * if($database->isOperator('>=')) {
	 *   // given string is a valid database operator
	 * } else {
	 *   // not a valid database operator
	 * }
	 * ~~~~~
	 *
	 * @param string $str 1-2 character operator to test
	 * @param bool|null $bitwise NULL=allow all operators, TRUE=allow only bitwise, FALSE=do not allow bitwise (default=NULL) added 3.0.143
	 * @return bool True if valid, false if not
	 *
	 */
	public function isOperator($str, $bitwise = null) {
		
		$operators = array('=', '<', '>', '>=', '<=', '<>', '!='); 
		$bitwiseOperators = array('&', '~', '&~', '|', '^', '<<', '>>');
		$len = strlen($str);
		
		if($len > 2 || $len < 1) return false;
		
		if($bitwise === null) {
			// allow all operators
			$operators = array_merge($operators, $bitwiseOperators); 
		} else if($bitwise === true) {
			// allow only bitwise operators
			$operators = $bitwise; 
		} else {
			// false or unrecognized $bitwise value: allow only regular operators
		}
		
		return in_array($str, $operators, true);
	}

	/**
	 * Is given word a fulltext stopword to the current database engine?
	 * 
	 * @param string $word
	 * @return bool
	 * @since 3.0.160
	 * 
	 */
	public function isStopword($word) {
		
		if($this->engine === 'myisam') {
			return DatabaseStopwords::has($word);
		}
			
		if($this->stopwordCache === null && $this->engine === 'innodb') {
			$cache = $this->wire()->cache;
			$stopwords = null;
			if($cache) {
				$stopwords = $cache->get('InnoDB.stopwords');
				if($stopwords) $stopwords = explode(',', $stopwords);
			}
			if(!$stopwords) {
				$query = $this->prepare('SELECT value FROM INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD');
				$query->execute();
				$stopwords = $query->fetchAll(\PDO::FETCH_COLUMN, 0);
				$query->closeCursor();
				if($cache) $cache->save('InnoDB.stopwords', implode(',', $stopwords), WireCache::expireDaily);
			}
			$this->stopwordCache = array_flip($stopwords);
		}
		
		if(!$this->stopwordCache) return false;
		
		return isset($this->stopwordCache[strtolower($word)]);
	}

	/**
	 * Sanitize a table name for _a-zA-Z0-9
	 * 
	 * #pw-group-sanitization
	 *
	 * @param string $table String containing table name
	 * @return string Sanitized table name
	 *
	 */
	public function escapeTable($table) {
		$table = (string) trim($table); 
		if(ctype_alnum($table)) return $table; 
		if(ctype_alnum(str_replace('_', '', $table))) return $table;
		return preg_replace('/[^_a-zA-Z0-9]/', '_', $table);
	}

	/**
	 * Sanitize a column name for _a-zA-Z0-9
	 * 
	 * #pw-group-sanitization
	 *
	 * @param string $col
	 * @return string
	 *
	 */
	public function escapeCol($col) {
		return $this->escapeTable($col);
	}

	/**
	 * Sanitize a table.column string, where either part is optional
	 * 
	 * #pw-group-sanitization
	 *
	 * @param string $str
	 * @return string
	 *
	 */
	public function escapeTableCol($str) {
		if(strpos($str, '.') === false) return $this->escapeTable($str); 
		list($table, $col) = explode('.', $str); 
		return $this->escapeTable($table) . '.' . $this->escapeCol($col);
	}

	/**
	 * Escape a string value, same as $db->quote() but without surrounding quotes
	 * 
	 * #pw-group-sanitization
	 *
	 * @param string $str
	 * @return string
	 *
	 */
	public function escapeStr($str) {
		return substr($this->quote($str), 1, -1);
	}

	/**
	 * Escape a string value, for backwards compatibility till PDO transition complete
	 * 
	 * #pw-internal
	 *
	 * @deprecated
	 * @param string $str
	 * @return string
	 *
	 */
	public function escape_string($str) {
		return $this->escapeStr($str); 
	}

	/**
	 * Quote and escape a string value
	 * 
	 * #pw-group-sanitization
	 * #pw-group-PDO
	 *
	 * @param string $str
	 * @return string
	 * @link http://php.net/manual/en/pdo.quote.php
	 *
	 */
	public function quote($str) {
		if($this->stripMB4 && is_string($str) && !empty($str)) {
			$str = $this->wire('sanitizer')->removeMB4($str);
		}
		return $this->pdo()->quote($str);
	}

	/**
	 * Escape a string value, plus escape characters necessary for a MySQL 'LIKE' phrase
	 * 
	 * #pw-group-sanitization
	 *
	 * @param string $like
	 * @return string
	 *
	 */
	public function escapeLike($like) {
		$like = $this->escapeStr($like); 
		return addcslashes($like, '%_'); 
	}

	/**
	 * Set whether debug mode is enabled for this database instance
	 * 
	 * #pw-internal
	 * 
	 * @param $debugMode
	 * 
	 */
	public function setDebugMode($debugMode) {
		$this->debugMode = (bool) $debugMode; 
	}

	/**
	 * @param string $key
	 * @return mixed|null|\PDO
	 * 
	 */
	public function __get($key) {
		if($key == 'pdo') return $this->pdo();
		return parent::__get($key);
	}

	/**
	 * Close the PDO connection
	 * 
	 * #pw-group-custom
	 * 
	 */
	public function closeConnection() {
		$this->pdo = null;
	}

	/**
	 * Get the value of a MySQL variable
	 * 
	 * ~~~~~
	 * // Get the minimum fulltext index word length
	 * $value = $database->getVariable('ft_min_word_len');
	 * echo $value; // outputs "4"
	 * ~~~~~
	 * 
	 * #pw-group-custom
	 * 
	 * @param string $name Name of MySQL variable you want to retrieve
	 * @param bool $cache Allow use of cached values? (default=true)
	 * @param bool $sub Allow substitution of MyISAM variable names to InnoDB equivalents when InnoDB is engine? (default=true)
	 * @return string|int
	 * 
	 */
	public function getVariable($name, $cache = true, $sub = true) {
		if($sub && isset($this->subVars[$this->engine][$name])) $name = $this->subVars[$this->engine][$name]; 
		if($cache && isset($this->variableCache[$name])) return $this->variableCache[$name];
		$query = $this->prepare('SHOW VARIABLES WHERE Variable_name=:name');
		$query->bindValue(':name', $name);
		$query->execute();
		/** @noinspection PhpUnusedLocalVariableInspection */
		list($varName, $value) = $query->fetch(\PDO::FETCH_NUM);
		$this->variableCache[$name] = $value;
		return $value;
	}

	/**
	 * Get current database engine (lowercase) 
	 * 
	 * @return string
	 * @since 3.0.160
	 * 
	 */
	public function getEngine() {
		return $this->engine;
	}

	/**
	 * Get current database charset (lowercase)
	 * 
	 * @return string
	 * @since 3.0.160
	 * 
	 */
	public function getCharset() {
		return $this->charset;
	}

	/**
	 * Retrieve new instance of WireDatabaseBackups ready to use with this connection
	 * 
	 * See `WireDatabaseBackup` class for usage. 
	 * 
	 * #pw-group-custom
	 * 
	 * @return WireDatabaseBackup
	 * @throws WireException|\Exception on fatal error
	 * @see WireDatabaseBackup::backup(), WireDatabaseBackup::restore()
	 * 
	 */
	public function backups() {
	
		$path = $this->wire('config')->paths->assets . 'backups/database/';
		if(!is_dir($path)) {
			$this->wire('files')->mkdir($path, true); 
			if(!is_dir($path)) throw new WireException("Unable to create path for backups: $path"); 
		}

		$backups = new WireDatabaseBackup($path); 
		$backups->setWire($this->wire());
		$backups->setDatabase($this);
		$backups->setDatabaseConfig($this->wire('config'));
		$backups->setBackupOptions(array('user' => $this->wire('user')->name)); 
	
		return $backups; 
	}

	/**
	 * Get max length allowed for a fully indexed varchar column in ProcessWire
	 * 
	 * #pw-internal
	 * 
	 * @return int
	 * 
	 */
	public function getMaxIndexLength() {
		$max = 250; 
		if($this->charset === 'utf8mb4') {
			if($this->engine === 'innodb') {
				$max = 191; 
			}
		}
		return $max;
	}

	/**
	 * Get SQL mode, set SQL mode, add to existing SQL mode, or remove from existing SQL mode
	 * 
	 * #pw-group-custom
	 * 
	 * ~~~~~
	 * // Get SQL mode
	 * $mode = $database->sqlMode();
	 * 
	 * // Add an SQL mode
	 * $database->sqlMode('add', 'STRICT_TRANS_TABLES');
	 * 
	 * // Remove SQL mode if version at least 5.7.0
	 * $database->sqlMode('remove', 'ONLY_FULL_GROUP_BY', '5.7.0');
	 * ~~~~~
	 * 
	 * @param string $action Specify "get", "set", "add" or "remove". (default="get")
	 * @param string $mode Mode string or CSV string with SQL mode(s), i.e. "STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY".
	 *   This argument should be omitted when using the "get" action. 
	 * @param string $minVersion Make the given action only apply if MySQL version is at least $minVersion, i.e. "5.7.0".
	 * @return string|bool Returns string in "get" action, boolean false if required version not present, or true otherwise.
	 * @throws WireException If given an invalid $action
	 * 
	 */
	public function sqlMode($action = 'get', $mode = '', $minVersion = '') {

		$result = true;
		$modes = array();
		
		if(empty($action)) $action = 'get';
		
		if($action !== 'get' && $minVersion) {
			$serverVersion = $this->getAttribute(\PDO::ATTR_SERVER_VERSION);
			if(version_compare($serverVersion, $minVersion, '<')) return false;
		}
	
		if($mode) {
			foreach(explode(',', $mode) as $m) {
				$modes[] = $this->escapeStr(strtoupper($this->wire('sanitizer')->fieldName($m)));
			}
		}
		
		switch($action) {
			case 'get':
				$query = $this->pdo()->query("SELECT @@sql_mode");
				$result = $query->fetchColumn();
				$query->closeCursor();
				break;
			case 'set':
				$modes = implode(',', $modes);
				$result = $modes;
				$this->pdo()->exec("SET sql_mode='$modes'");
				break;
			case 'add':
				foreach($modes as $m) {
					$this->pdo()->exec("SET sql_mode=(SELECT CONCAT(@@sql_mode,',$m'))");
				}
				break;
			case 'remove':
				foreach($modes as $m) {
					$this->pdo()->exec("SET sql_mode=(SELECT REPLACE(@@sql_mode,'$m',''))");
				}
				break;
			default:
				throw new WireException("Unknown action '$action'");
		}
		
		return $result;
	}
}

