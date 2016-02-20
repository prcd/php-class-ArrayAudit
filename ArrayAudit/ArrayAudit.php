<?php

/*
 * Works with PHP 5.4 +
 *
 */

class ArrayAudit {

	private $data_set_id  = NULL;
	private $mysqli       = NULL;
	private $query_unix   = NULL;


	function __construct($db_host, $db_user, $db_password, $db_name, $data_set_id = NULL) {

		$this->connect($db_host, $db_user, $db_password, $db_name);

		if ($data_set_id !== NULL) {

			$this->setDataSetId($data_set_id);
		}

		$this->query_unix = time();
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


	public function listData() {

		$res = $this->mysqli->query("

			SELECT
				node.id,
				MAX(parent.lft) AS parent_lft,
				node.is_scaffold,
				node.label,
				CASE
					WHEN item_value.var_char IS NOT NULL
					THEN item_value.var_char
					WHEN item_value.int IS NOT NULL
					THEN item_value.int
					ELSE NULL
				END AS item_value,
				node.lft,
				node.rgt,
				COUNT(parent.id) AS depth

			FROM item AS node

			LEFT JOIN item AS parent
				ON node.lft > parent.lft
				AND node.lft < parent.rgt

			LEFT JOIN item_value AS item_value
				ON item_value.item_id = node.id
				AND item_value.valid_from <= '".$this->query_unix."'
				AND item_value.valid_to > '".$this->query_unix."'

			WHERE
				(
					node.is_scaffold = '1'
					OR
					(item_value.var_char IS NOT NULL OR item_value.int IS NOT NULL)
				)
				AND node.data_set_id = '".$this->data_set_id."'

			GROUP BY node.id

			ORDER BY node.lft

		");

		if ($this->mysqli->error) {
			throw new Exception ('Mysqli error '.$this->mysqli->errno.': '.$this->mysqli->error);
		}

		while ($row = $res->fetch_assoc()) {
			$a[] = $row;
		}

		return $this->queryResultToHtmlTable($a);

	}

	public function getData() {

		$res = $this->mysqli->query("

			SELECT
				node.id,
				MAX(parent.lft) AS parent_lft,
				node.is_scaffold,
				node.label,
				CASE
					WHEN item_value.var_char IS NOT NULL
					THEN item_value.var_char
					WHEN item_value.int IS NOT NULL
					THEN item_value.int
					ELSE NULL
				END AS item_value,
				node.lft,
				node.rgt,
				COUNT(parent.id) AS depth

			FROM item AS node

			LEFT JOIN item AS parent
				ON node.lft > parent.lft
				AND node.lft < parent.rgt

			LEFT JOIN item_value AS item_value
				ON item_value.item_id = node.id
				AND item_value.valid_from <= '".$this->query_unix."'
				AND item_value.valid_to > '".$this->query_unix."'

			WHERE
				(
					node.is_scaffold = '1'
					OR
					(item_value.var_char IS NOT NULL OR item_value.int IS NOT NULL)
				)
				AND node.data_set_id = '".$this->data_set_id."'

			GROUP BY node.id

			ORDER BY depth DESC, node.lft

		");

		if ($this->mysqli->error) {
			throw new Exception ('Mysqli error '.$this->mysqli->errno.': '.$this->mysqli->error);
		}


		$return_array = [];

		while ($row = $res->fetch_assoc()) {

			// simple result
			$a[] = $row;


			// create return array
			if ($row['label'] !== NULL && $row['item_value'] !== NULL) {
				// this item has a label and a value i.e it is an end node
				// add the data to the return array, storing it under the key of its parent_lft
				$return_array[$row['parent_lft']][$row['label']] = $row['item_value'];
			}

			if (isset($return_array[$row['lft']])) {
				// this item is an array marker and has items ready to hold

				if ($row['parent_lft'] === NULL) {
					// this will be the final iteration as the item has no parent
					$return_array = $return_array[$row['lft']];
				}
				else if ($row['label'] == NULL) {
					// this is a placeholder item, doesn't have a name - use ID for key
					$return_array[$row['parent_lft']][$row['id']] = $return_array[$row['lft']];
				}
				else {
					// use the item value as a key
					$return_array[$row['parent_lft']][$row['label']] = $return_array[$row['lft']];
				}
				// this is not required as it has now been assigned
				unset($return_array[$row['lft']]);
			}
		}

		$a['html_table'] = $this->queryResultToHtmlTable($a);
		$a['array']      = $return_array;

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
