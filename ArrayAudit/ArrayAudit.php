<?php

/*
 * Works with PHP 5.4 +
 *
 */


class ArrayAudit {

	private $data_set_id          = NULL;
	private $mysqli               = NULL;
	private $query_unix           = NULL;
	private $end_of_time          = '2147483648';
	private $insert_tree_iterator = [];


	function __construct($db_host, $db_user, $db_password, $db_name, $data_set_id = NULL, $query_unix = NULL) {

		$this->connect($db_host, $db_user, $db_password, $db_name);

		if ($data_set_id !== NULL) {
			$this->setDataSetId($data_set_id);
		}

		if ($query_unix !== NULL) {
			$this->setQueryUnix($query_unix);
		}
	}


	/**
	 * Connects to a mysql database
	 *
	 * @param  string  $db_host       Database host name
	 * @param  string  $db_user       Database username
	 * @param  string  $db_password   Database password
	 * @param  string  $db_name       Database name
	 *
	 * @return  null
	 * @throws  Exception If a connection error occurs
	 *
	 * @access  private
	 */
	private function connect($db_host, $db_user, $db_password, $db_name) {

		$this->mysqli = new mysqli($db_host, $db_user, $db_password, $db_name);

		if ($this->mysqli->connect_error) {

			throw new Exception ('Connect Error (' . $this->mysqli->connect_errno . ') ' . $this->mysqli->connect_error);
		}
	}


	/**
	 * Sets the Account ID
	 *
	 * @param  string  $data_set_id  The account ID to use when making database requests and updates.
	 *
	 * @return  null
	 * @throws  Exception  If account ID is not an integer greater than 0
	 *
	 * @access  public
	 */
	public function setDataSetId($data_set_id) {

		$data_set_id = (string) $data_set_id;

		if (ctype_digit($data_set_id)) {
			$this->data_set_id = (string) floor($data_set_id);
		}
		else {
			throw new Exception ('account_id must be an integer greater than 0');
		}
	}


	/**
	 * Sets the unix timestamp to use for queries
	 *
	 * @param  string  $unix_timestamp  The unix timestamp to use for queries
	 *
	 * @return  null
	 * @throws  Exception  If timestamp is not an integer greater than 0
	 *
	 * @access  public
	 */
	public function setQueryUnix($unix_timestamp) {

		$unix_timestamp = (string) $unix_timestamp;

		if (ctype_digit($unix_timestamp)) {
			$this->query_unix = (string) floor($unix_timestamp);
		}
		else {
			throw new Exception ('unix_timestamp must be an integer greater than 0');
		}
	}


	public function getDataSetIdId() {

		return $this->data_set_id;
	}


	private function mysqliErrorCheck() {
		if ($this->mysqli->error) {
			throw new Exception ('Mysqli error '.$this->mysqli->errno.': '.$this->mysqli->error);
		}
	}


	private function mysqliLockTables() {
		$this->mysqli->query('
			LOCK TABLES
				branch           WRITE,
				branch AS node   WRITE,
				branch AS parent WRITE,
				leaf             WRITE
		');
		$this->mysqliErrorCheck();
	}


	private function mysqliUnlockTables() {
		$this->mysqli->query('UNLOCK TABLES');
		$this->mysqliErrorCheck();
	}


	private function cleanDataType($data_type) {

		if ($data_type !== NULL) {

			$allow = ['var_char', 'pos_int'];

			if (!in_array($data_type, $allow)) {

				throw new Exception ('data_type (' . $data_type . ') is not valid');
			}
		}

		return $data_type;
	}


	private function cleanId($id, $name = 'id') {

		$id = (string) $id;

		if (!ctype_digit($id)) {
			throw new Exception ($name.' must be a positive integer, can be submitted as a string');
		}

		$id = floor($id);// removes leading 0s
		return $id;
	}


	private function cleanLabel($label) {

		if ($label !== NULL) {

			$label = (string) $label;

			if ($label == '') {
				throw new Exception ('label must be a non-empty string or NULL');
			}

			if (ctype_digit($label)) {
				throw new Exception ('label must contain at least 1 non-digit character');
			}
		}

		return $label;
	}


	public function insertTree($tree_type_id, $array) {

		if (!is_array($array)) {
			throw new Exception ('input must be an array');
		}

		// reset iterator details
		$this->insert_tree_iterator['tree_type_id'] = $tree_type_id;
		$this->insert_tree_iterator['parent_id']    = [];

		$this->insertTreeIterator($array);
	}


	private function insertTreeIterator($array) {

		foreach ($array as $key => $value) {

			if (isset($this->insert_tree_iterator['parent_id']) && count($this->insert_tree_iterator['parent_id']) > 0) {
				$parent_id = end($this->insert_tree_iterator['parent_id']);
				$depth = count($this->insert_tree_iterator['parent_id']);
			}
			else {
				$parent_id = 0;
				$depth = 0;
			}

			$named_parent_id = $unnamed_parent_id = $parent_id;

			if (is_array($value)) {
				// branch
				$key = (string) ($key);
				if (ctype_digit($key)) {
					// unnamed branch
					$label = NULL;
				}
				else {
					// named branch
					$label = $this->cleanLabel($key);
				}

				// insert data
				$new_parent_id = $this->insertBranch($this->insert_tree_iterator['tree_type_id'], $parent_id, $label, $depth, $named_parent_id, $unnamed_parent_id);

				// add id to parent array
				$this->insert_tree_iterator['parent_id'][] = $new_parent_id;

				// repeat function on value
				$this->insertTreeIterator($value);

				// remove id from parent array
				array_pop($this->insert_tree_iterator['parent_id']);
			}
			else {
				// end branch
				$label     = $this->cleanLabel($key);
				$data_type = ctype_digit($value) ? 'pos_int' : 'var_char';

				// insert data
				$this->insertBranch($this->insert_tree_iterator['tree_type_id'], $parent_id, $label, $depth, $named_parent_id, $unnamed_parent_id, $data_type, $value);
			}
		}
	}


	public function insertBranch($tree_type_id, $parent_id, $label, $depth, $named_parent_id, $unnamed_parent_id, $data_type = NULL, $value = NULL) {

		// check incoming
		$tree_type_id = $this->cleanId($tree_type_id, 'tree_type_id');
		$parent_id    = $this->cleanId($parent_id, 'parent_id');
		$label        = $this->cleanLabel($label);
		$data_type    = $this->cleanDataType($data_type);


		// lock tables for insert
		$this->mysqliLockTables();

		if ($parent_id == '0') {
			// inserting a top level branch

			// get the max rgt value
			$res = $this->mysqli->query("
				SELECT MAX(rgt) AS rgt
				FROM branch
				WHERE
					data_set_id = '".$this->mysqli->real_escape_string($this->data_set_id)."'
					AND
					tree_type_id = '".$tree_type_id."'
				GROUP BY branch.tree_type_id
			");
			$this->mysqliErrorCheck();

			if ($row = $res->fetch_assoc()) {
				$rgt = $row['rgt']+1;
			}
			else {
				// brand new tree
				$rgt = '1';
			}
		}
		else {
			// inserting lower level branch

			// get the rgt value of the parent
			$res = $this->mysqli->query("
				SELECT rgt
				FROM branch
				WHERE
					id = '".$this->mysqli->real_escape_string($parent_id)."'
					AND
					data_set_id = '".$this->mysqli->real_escape_string($this->data_set_id)."'
					AND
					tree_type_id = '".$tree_type_id."'
			");
			$this->mysqliErrorCheck();

			if ($row = $res->fetch_assoc()) {
				$rgt = $row['rgt'];
			}
			else {
				throw new Exception ('parent_id ('.$parent_id.') was not valid');
			}

			// prepare table 'branch' lft
			$this->mysqli->query("
				UPDATE branch
				SET
					lft = lft+2
				WHERE
					lft >= " . $rgt . "
					AND
					data_set_id = '" . $this->mysqli->real_escape_string($this->data_set_id) . "'
					AND
					tree_type_id = '" . $tree_type_id . "'
			");
			$this->mysqliErrorCheck();

			// prepare table 'branch' rgt
			$this->mysqli->query("
				UPDATE branch
				SET
					rgt = rgt+2
				WHERE
					rgt >= " . $rgt . "
					AND
					data_set_id = '" . $this->mysqli->real_escape_string($this->data_set_id) . "'
					AND
					tree_type_id = '" . $tree_type_id . "'
			");
			$this->mysqliErrorCheck();
		}

		// insert new row into table 'branch'
		$label             = ($label === NULL) ? 'NULL' : "'".$this->mysqli->real_escape_string($label)."'";
		$unnamed_parent_id = ($unnamed_parent_id === NULL) ? 'NULL' : "'".$this->mysqli->real_escape_string($unnamed_parent_id)."'";
		$this->mysqli->query("
			INSERT INTO branch
			SET
				data_set_id       = ".$this->data_set_id.",
				tree_type_id      = ".$tree_type_id.",
				label             = ".$label.",
				lft               = ".$rgt.",
				rgt               = ".($rgt+1).",
				depth             = ".$depth.",
				named_parent_id   = ".$named_parent_id.",
				unnamed_parent_id = ".$unnamed_parent_id."
		");
		$this->mysqliErrorCheck();

		// get ID
		$branch_id = $this->mysqli->insert_id;

		if ($data_type !== NULL) {
			// prepare data for table 'leaf'
			if ($data_type == 'var_char') {
				$var_char = '"' . $this->mysqli->real_escape_string($value) . '"';
				$pos_int = 'NULL';
			} else if ($data_type == 'pos_int') {
				$var_char = 'NULL';
				$pos_int = '"' . $this->mysqli->real_escape_string($value) . '"';
			} else {
				throw new Exception ('data_type (' . $data_type . ') was not valid');
			}

			// add data to table 'leaf'
			$this->mysqli->query("
					INSERT INTO leaf
					SET
						data_set_id = " . $this->data_set_id . ",
						branch_id   = " . $branch_id . ",
						var_char    = " . $var_char . ",
						pos_int       = " . $pos_int . ",
						valid_from  = " . $this->query_unix . ",
						valid_to    = " . $this->end_of_time . "
				");
			$this->mysqliErrorCheck();
		}

		// unlock tables
		$this->mysqliUnlockTables();

		return $branch_id;
	}


	public function getData($tree_type_id) {

		$res = $this->mysqli->query("

			SELECT
				node.id,
				node.label,
				CASE
					WHEN leaf.var_char IS NOT NULL
					THEN leaf.var_char
					WHEN leaf.pos_int IS NOT NULL
					THEN leaf.pos_int
					ELSE NULL
				END AS leaf_value,
				MAX(parent.lft) AS parent_lft,
				node.lft,
				node.rgt,
				COUNT(parent.id) AS depth,
				IF (leaf.var_char IS NULL AND leaf.pos_int IS NULL, '1', '0') AS is_branch

			FROM branch AS node

			LEFT JOIN branch AS parent
				ON node.lft > parent.lft
				AND node.lft < parent.rgt
				AND parent.data_set_id = '".$this->data_set_id."'
				AND parent.tree_type_id = '".$this->mysqli->real_escape_string($tree_type_id)."'

			LEFT JOIN leaf
				ON leaf.branch_id = node.id
				AND leaf.valid_from <= '".$this->query_unix."'
				AND leaf.valid_to > '".$this->query_unix."'

			WHERE
				node.data_set_id = '".$this->data_set_id."'
				AND node.tree_type_id = '".$this->mysqli->real_escape_string($tree_type_id)."'
				AND
				(
					node.lft < node.rgt - 1
					OR
					(leaf.var_char IS NOT NULL OR leaf.pos_int IS NOT NULL)
				)

			GROUP BY node.id

			ORDER BY depth DESC, is_branch ASC, node.label ASC, node.id ASC

		");

		$this->mysqliErrorCheck();

		$return = [];
		$store  = [];

		while ($row = $res->fetch_assoc()) {

			// simple result
			$a[] = $row;


			// create return array
			if ($row['label'] !== NULL && $row['leaf_value'] !== NULL) {
				// this item has a label and a value i.e it is an end node
				// add the data to the store array, storing it under the key of its parent_lft
				$store[$row['parent_lft']][$row['label']] = $row['leaf_value'];
			}
			else if (isset($store[$row['lft']])) {
				// this item is an array marker and has items ready to hold
				$array_key = ($row['label'] === NULL) ? $row['id'] : $row['label'];

				if ($row['parent_lft'] === NULL) {
					// this is a top level branch
					$return[$array_key] = $store[$row['lft']];
				}
				else {
					// this result is a child of something
					$store[$row['parent_lft']][$array_key] = $store[$row['lft']];
				}
				unset($store[$row['lft']]);
			}
		}

		$a['html_table'] = $this->queryResultToHtmlTable($a);
		$a['array']      = $return;

		return $a;
	}

	public function getData2($tree_type_id) {

		$res = $this->mysqli->query("

			SELECT
				node.id,
				node.label,
				CASE
					WHEN leaf.var_char IS NOT NULL
					THEN leaf.var_char
					WHEN leaf.pos_int IS NOT NULL
					THEN leaf.pos_int
					ELSE NULL
				END AS leaf_value,
				MAX(parent.lft) AS parent_lft,
				node.named_parent_id AS parent_id,
				node.lft,
				node.rgt,
				node.depth,
				node.is_leaf,
				IF(node.is_leaf = '1', '0', '1') AS is_branch

			FROM branch AS node

			LEFT JOIN branch AS parent
				ON node.lft > parent.lft
				AND node.lft < parent.rgt
				AND parent.data_set_id = '".$this->data_set_id."'
				AND parent.tree_type_id = '".$this->mysqli->real_escape_string($tree_type_id)."'

			LEFT JOIN leaf
				ON leaf.branch_id = node.id
				AND leaf.valid_from <= '".$this->query_unix."'
				AND leaf.valid_to > '".$this->query_unix."'

			WHERE
				node.data_set_id = '".$this->data_set_id."'
				AND node.tree_type_id = '".$this->mysqli->real_escape_string($tree_type_id)."'
				AND (node.is_leaf = 0 OR (leaf.var_char IS NOT NULL && leaf.pos_int IS NOT NULL))

			GROUP BY node.id

			ORDER BY depth DESC, is_branch ASC, node.label ASC, node.id ASC

		");

		$this->mysqliErrorCheck();

		$return = [];
		$store  = [];

		while ($row = $res->fetch_assoc()) {

			// simple result
			$a[] = $row;


			// create return array
			if ($row['label'] !== NULL && $row['leaf_value'] !== NULL) {
				// this item has a label and a value i.e it is an end node
				// add the data to the store array, storing it under the key of its parent_lft
				$store[$row['parent_lft']][$row['label']] = $row['leaf_value'];
			}
			else if (isset($store[$row['lft']])) {
				// this item is an array marker and has items ready to hold
				$array_key = ($row['label'] === NULL) ? $row['id'] : $row['label'];

				if ($row['parent_lft'] === NULL) {
					// this is a top level branch
					$return[$array_key] = $store[$row['lft']];
				}
				else {
					// this result is a child of something
					$store[$row['parent_lft']][$array_key] = $store[$row['lft']];
				}
				unset($store[$row['lft']]);
			}
		}

		$a['html_table'] = $this->queryResultToHtmlTable($a);
		$a['array']      = $return;

		return $a;
	}

	private function queryResultToHtmlTable($result) {

		$html[] = '<table class="table table-hover">';
		$html[] = '<thead>';
		$html[] = '<tr>';

		foreach ($result[0] as $k => $d) {

			$html[] = '<th>'. $k .'</th>';
		}

		$html[] = '</tr>';
		$html[] = '</thead>';
		$html[] = '<tbody>';

		foreach ($result as $row) {

			$html[] = '<tr>';

			foreach ($row as $data) {

				$html[] = '<td>'. $data .'</td>';
			}

			$html[] = '</tr>';
		}
		$html[] = '</tbody>';
		$html[] = '</table>';

		return implode('', $html);
	}

}
