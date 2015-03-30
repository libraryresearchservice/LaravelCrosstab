<?php namespace Lrs\LaravelCrosstab;

class LaravelCrosstab {
	
	public $allowedAxis = array();
	public $avg;
	public $axis = array();
	public $columns = array();
	public $config;
	public $db;
	public $formattedHeaders = array();
	public $headers = array();
	public $hooks;
	public $result;
	public $sum;
	public $tableMatrix = array();

	public function __construct($db = false, $axis = array(), $config = array()) {
		$this->allowedAxis();
		if ( $db ) {
			$this->db($db);
		}
		$this->config($config);
		if ( is_array($axis) && sizeof($axis) > 0 ) {
			$this->axis($axis);
		}
	}
	
	/**
	 *	Replacement for array_replace_recursive(), which is horrendously slow
	 */
	public function arrayReplaceDistinct(array $arr1, array $arr2) {
		$m = $arr1;
		foreach ( $arr2 as $k => $v ) {
			if ( is_array($v) && isset($m[$k]) && is_array($m[$k]) ) {
				$m[$k] = $this->arrayReplaceDistinct($m[$k], $v);
			} else {
				$m[$k] = $v;
			}
		}
	  return $m;
	}
	
	/**
	 *	Set the DB/Eloquent source
	 */
	public function db($db) {
		if ( $db instanceof \Illuminate\Database\Query\Builder ) {
			$this->db = clone($db);
			return $this;
		}
		throw new \Exception('You must provide a valid instance of "Illuminate\Database\Query\Builder", e.g. DB::table("foo").');
	}
	
	/**
	 *	Set the size of the axis
	 */
	public function allowedAxis($arr = array()) {
		if ( !is_array($arr) || sizeof($arr) == 0 ) {
			$arr = range('a', 'd');	
		}
		$this->allowedAxis = $arr;
	}

	/**
	 *	Re-order headers for further processing
	 */
	public function arrangeHeaders() {
		$headers = array();
		$sizeOfHeaders = sizeof($this->headers);
		$i = 1;
		foreach ( $this->headers as $k => $v ) {
			if ( $i == $sizeOfHeaders ) {
				$headers = [$k => $v] + $headers;
			} else {
				$headers[$k] = $v;	
			}
			$i++;
		}
		return $headers;	
	}
	
	/**
	 *	Specify column to be averaged
	 */
	public function avg($column) {
		$this->avg = $column;
		return $this;	
	}
	
	/**
	 *	Set axis
	 */
	public function axis($axis = array()) {
		$axis = array_unique($axis);
		$size = sizeof($axis);
		$i = 1;
		foreach ( $this->allowedAxis as $v ) {		
			if ( $element = array_shift($axis) ) {
				if ( isset($this->config[$v][$element]) ) {
					$this->axis[$v] = $this->config[$v][$element];
					// Execute hooks immediately
					if ( is_callable($this->axis[$v]['hook']) ) {
						$this->axis[$v]['hook']();
					}
				}
			}
			if ( $i == $size ) {
				break;
			}
			$i++;
		}
		return $this;
	}
	
	/**
	 *	Calculate colspans for use in setting up table matrix
	 */
	public function calculateColspans() {
		if ( $this->numberOfAxis() > 1 && sizeof($this->headers) > 0 ) {
			$headers = $this->headers;
			ksort($headers);
			$lastHeader = array_pop($headers);
			$colspans = array_fill_keys(array_keys($headers), false);
			arsort($colspans);
			$colspans[key($colspans)] = 1;
			foreach ( $colspans as $k => $v ) {
				if ( !$v ) {
					$colspans[$k] = $next;
				} else {
					$next = $v;
				}
				$next = sizeof($this->headers[$k]) * $next;
			}
			ksort($colspans);
		} else {
			$colspans = array_fill_keys(array_keys($this->headers), 1);
		}
		return $colspans;
	}
		
	/**
	 *	Set configuration settings
	 */
	public function config($config = array()) {
		$default = array_fill_keys(['id', 'header-format', 'hook', 'join', 'key', 'name', 'order-by', 'title'], false);
		foreach ( $config as $k => $v ) {
			foreach ( $v as $k1 => $v1 ) {
				$this->config[$k][$k1] = array_merge($default, $v1);

			}
		}
		return $this;
	}
	
	/**
	 *	Format header values when callback is provided in configuration
	 */
	public function formatHeader($axis, $value) {
		if ( !isset($this->formattedHeaders[$axis][$value]) ) {
			if ( isset($this->axis[$axis]['header-format']) && is_callable($this->axis[$axis]['header-format']) ) {	
				$value = $this->axis[$axis]['header-format']($value);
			}
			$this->formattedHeaders[$axis][$value] = $value;
		}
		return $this->formattedHeaders[$axis][$value];
	}
	
	/**
	 *	Get query results and build header
	 */
	public function get() {
		if ( sizeof($this->config) == 0 ) {
			throw new \Exception('You must use config() to provide Crosstab with information about your DB.');
		}
		$this->result = $this->query()->get();
		foreach ( $this->result as $v ) {
			foreach ( $this->axis as $k1 => $v1 ) {
				if ( !isset($this->headers[$k1][$v->{$k1.'_id'}]) ) {
					$this->headers[$k1][$v->{$k1.'_id'}] = $v->{$k1.'_name'};
				}
			}
		}
		return $this;
	}
	
	/**
	 *	Put query results into a table matrix that can be easily iterated.
	 *	NOTE:  this is not particularly fast because of the recursion 
	 *	that is necessary when dealing with a variable number of axi.
	 *	Any help speeding up this method would be greatly appreciated!
	 */
	public function getTableMatrix($value = 'count') {
		// Container
		$this->tableMatrix = array_fill_keys(['colspans', 'header-frequencies', 'headers', 'rows', 'footers'], array());
		if ( sizeof($this->headers) == 0 ) {
			return $this->tableMatrix;	
		}
		// Colspans
		$this->tableMatrix['colspans'] = $this->calculateColspans();
		// Number of times each header group is repeated in its row
		foreach ( array_slice($this->headers, 0, ( sizeof($this->headers) - 1 )) as $k => $v ) {
			if ( !isset($freq) ) {
				$freq = 1;
			} else {
				$freq = $freq * $num;
			}
			$this->tableMatrix['header-frequencies'][$k] = $freq;
			$num = sizeof($v);	
			
		}
		// Header rows
		foreach ( $this->tableMatrix['header-frequencies'] as $k => $v ) {
			for ( $i = 1; $i <= $v; $i++ ) {
				foreach ( $this->headers[$k] as $k1 => $v1 ) {
					$this->tableMatrix['headers'][$k][$i.'.'.$v1] = $this->formatHeader($k, $v1);	
				}
			}
		}
		// If X, Y, and Z are given, we want the headers arranged Z, Y, X to match 
		// the order in which we need to loop to make the table friendly array
		$headers = $this->arrangeHeaders();
		// Reverse the keys in $header and pass to rowToArray() so that the DB
		// result row can be converted to a multi-dimensional array.
		$keys = array_reverse(array_keys($headers));
		// Create a structured container that has each X in Y, each Y in Z, etc.
		$folded = array();
		while ( $segment = array_pop($headers) ) {
			if ( isset($prev) ) {
				$row = array_fill_keys($segment, array());
				foreach ( $row as $k => $v ) {
					$row[$k] = $prev;	
				}
				$folded = $prev = $row;
			} else {
				// First loop
				$prev = array_fill_keys($segment, false);
			}
		}
		// Merge the DB results into the structured container
		foreach ( $this->result as $v ) {
			$s = $this->rowToArray((array)$v, $keys, $value);
			$folded = $this->arrayReplaceDistinct($folded, $s);
			//$folded = array_replace_recursive($folded, $s);
		}
		// Put each individual value into a row, in the same order as the header
		$columnTotals = array();
		$rowTotals = array();
		foreach ( current(array_slice($this->headers, -1)) as $k => $v ) {
			$i = 1;
			//$columnTotals[$i] = 0;
			$rowTotals[$k] = 0;
			if ( isset($folded[$v]) && is_array($folded[$v]) ) {
				foreach ( $folded[$v] as $k1 => $v1 ) {
					if ( !isset($columnTotals[$i]) ) {
						$columnTotals[$i] = 0;	
					}
					if ( is_array($v1) ) {
						// Get every value from the multi-dimensional array
						$it = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($v1)); 
						foreach ( $it as $k2 => $v2 ) {
							if ( !isset($columnTotals[$i]) ) {
								$columnTotals[$i] = 0;	
							}
							$rowTotals[$k] += $v2;
							$columnTotals[$i] += $v2;
							$this->tableMatrix['rows'][$v][] = $v2;
							$i++;
						}
					} else {
						$rowTotals[$k] += $v1;
						$columnTotals[$i] += $v1;
						// Get every value from the array
						$this->tableMatrix['rows'][$v][] = $v1;
						$i++;
					}
				}
			} else {
				$columnTotals[$i] += $folded[$v];
				$rowTotals[$v] = $folded[$v];
				$i++;
				$this->tableMatrix['rows'][$v][] = $rowTotals[$k];
			}
			if ( is_array($folded[$v]) && sizeof($folded[$v]) > 1 ) {
				$this->tableMatrix['rows'][$v][] = $rowTotals[$k];
			}
		}
		$this->tableMatrix['footers']['total'] = $columnTotals;
		return $this->tableMatrix;
	}
	
	/**
	 *	Specify functions that should be executed before the query
	 */
	public function hook($key, Callable $function) {
		$this->hooks[$key][] = $function;
		return $this;
	}

	/**
	 *	Determine if the axis is being averaged
	 */
	public function isAveraged($axis) {
		return stristr($this->{$axis}['id'], 'AVG(') !== false || stristr($this->{$axis}['name'], 'AVG(') !== false;
	}
	
	/**
	 *	Determine if grouping has aggregate function
	 */
	public function isGroupByAggregate($str) {
		$aggregates = 'AVG|BIT_AND|BIT_OR|BIT_XOR|COUNT|GROUP_CONCAT|MAX|MIN|STD|STDDEV_POP|STDDEV_SAMP|STDDEV|SUM|VAR_POP|VAR_SAMP|VARIANCE';
		return preg_match('/'.$aggregates.'/', $str) === 1;
	}
	
	/**
	 *	Determine if only 1 axis has been provided (e.g. something x "total")
	 */
	public function isSingleDimension() {
		return $this->numberOfAxis() == 1;
	}
	
	/**
	 *	Count the number of axis
	 */
	public function numberOfAxis() {
		return sizeof($this->axis);	
	}
	
	/**
	 *	Build DB/Eloquent query
	 */
	public function query() {
		$this->runHook('before-query');
		//	SELECT
		//		col1 AS x_id, col2 AS x_name, ...etc
		//	FROM 
		//		counts
		//	JOIN 
		//		... 
		//	GROUP BY 
		//		x_id, y_id, ...etc
		//	ORDER BY 
		//		x_name, total DESC, y_name, ..etc
		$this->runHook('before-columns');
		$i = 1;
		foreach ( $this->axis as $k => $v ) {
			array_push($this->columns, $this->db->raw($v['id'].' AS '.$k.'_id'));	
			array_push($this->columns, $this->db->raw($v['name'].' AS '.$k.'_name'));
			if ( !$this->isGroupByAggregate($v['id']) ) {
				$this->db->groupBy($k.'_id');
				if ( $v['order-by'] ) {
					if ( $v['order-by'] == 'id' ) {
						$this->db->orderBy($k.'_id');
					} else if ( $v['order-by'] == 'name' ) {
						$this->db->orderBy($k.'_name');
					} else {
						$this->db->orderBy($this->db->raw($v['order-by']));
					}
				}
			}		
			$i++;
		}
		if ( $this->avg ) {
			array_push($this->columns, $this->db->raw('AVG('.$this->avg.') AS `cross_avg`'));
		}
		if ( $this->sum ) {
			array_push($this->columns, $this->db->raw('SUM('.$this->sum.') AS `cross_sum`'));
		}
		array_push($this->columns, $this->db->raw('COUNT(1) AS `cross_count`'));
		$this->db->select($this->columns);
		foreach ( $this->axis as $k => $v ) {
			if ( isset($this->axis[$k]['join']) && is_callable($this->axis[$k]['join']) ) {
				$anon = $this->axis[$k]['join'];
				$anon($this->db);
			}
		}
		return $this->db;
	}
	
	/**
	 *	Convert DB result row into array
	 */
	public function rowToArray($row, $keys, $value) {
		$out = array();
		$i = 1;
		foreach ( $keys as $v ) {
			if ( $i == 1 ) {
				if ( $value == 'sum' ) {
					$out = $row['cross_sum'];
				} else {
					$out = $row['cross_count'];
				}
				$i = 2;
			}
			// Use "_name" values as keys (may be problematic for certain datasets)
			$out = [$row[$v.'_name'] => $out];
		}
		return $out;
	}
	
	/**
	 *	Execute hooks
	 */
	public function runHook($name) {
		if ( isset($this->hooks[$name]) && is_array($this->hooks[$name]) ) {
			foreach ( $this->hooks[$name] as $v ) {
				if ( is_callable($v) ) {
					if ( $name == 'before-columns' ) {
						// Manipulate columns that will be passed to DB object
						$v($this->columns);
					} else if ( $name == 'before-query' ) {
						// Manipulate DB object
						$v($this->db);
					}
				}
			}
			return true;
		}
		return false;
	}
	
	/**
	 *	Specify column to sum()
	 */
	public function sum($column) {
		$this->sum = $column;
		return $this;	
	}
		
}
