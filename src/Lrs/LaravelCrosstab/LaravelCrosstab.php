<?php 

/*
 * This file is part of the LaravelCrosstab package.
 *
 * (c) Library Research Service / Colorado State Library <LRS@lrs.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Lrs\LaravelCrosstab;

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
				$headers = array($k => $v) + $headers;
			} else {
				$headers[$k] = $v;	
			}
			$i++;
		}
		return $headers;	
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
		$default = array_fill_keys(array(
			'column', 'header-format', 'hook', 'join', 'order-by', 'title'
		), false);
		foreach ( $config as $k => $v ) {
			foreach ( $v as $k1 => $v1 ) {
				$this->config[$k][$k1] = array_merge($default, $v1);

			}
		}
		return $this;
	}

	/**
	 *	Set the DB/Eloquent source
	 */
	public function db($db) {
		if ( $db instanceof \Illuminate\Database\Query\Builder ) {
			$this->db = clone($db);
			return $this;
		}
		throw new \Exception('You must provide a valid instance of "Illuminate\Database\Query\Builder", e.g. DB::table("foo") or SomeEloquentClass::.');
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
				if ( !isset($this->headers[$k1][$v->{$k1.'_column'}]) ) {
					$this->headers[$k1][$v->{$k1.'_column'}] = $v->{$k1.'_column'};
				}
			}
		}
		return $this;
	}
	
	/**
	 *	Put query results into a table matrix that can be easily iterated.
	 *	NOTE:  this is not particularly fast because of the recursion 
	 *	that is necessary when dealing with a **variable number of axi**.
	 *	Any help speeding up this method would be greatly appreciated!
	 */
	public function getTableMatrix($valueType = 'count') {
		if ( !$this->result ) {
			$this->get();	
		}
		// Container
		$this->tableMatrix = array_fill_keys(array(
			'colspans', 'header-frequencies', 'headers', 'rows', 'footers'
		), array());
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
			$s = $this->rowToArray((array)$v, $keys, $valueType);
			$folded = $this->arrayReplaceDistinct($folded, $s);
		}
		// Put each individual value into a row, in the same order as the header
		$columnTotals = array();
		$rowTotals = array();
		foreach ( current(array_slice($this->headers, -1)) as $k => $v ) {
			$i = 1;
			$rowTotals[$k] = array();
			if ( isset($folded[$v]) && is_array($folded[$v]) ) {
				/**
				 *	Multiple dimensions
				 */
				foreach ( $folded[$v] as $k1 => $v1 ) {
					if ( !isset($columnTotals[$i]) ) {
						$columnTotals[$i] = array();
					}
					if ( is_array($v1) ) {
						/**
						 *	Three or more dimensions
						 *	Recursively get every value from multi-dimensional array
						 */
						$it = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($v1)); 
						foreach ( $it as $k2 => $v2 ) {
							if ( !isset($columnTotals[$i]) ) {
								$columnTotals[$i] = array();
							}
							$columnTotals[$i][] = $v2;
							$rowTotals[$k][] = $v2;
							$this->tableMatrix['rows'][$v][] = $v2;
							$i++;
						}
					} else {
						/**
						 *	Two dimensions
						 */
						$columnTotals[$i][] = $v1;
						$rowTotals[$k][] = $v1;
						$this->tableMatrix['rows'][$v][] = $v1;
						$i++;
					}
				}
			} else {
				/**
				 *	Single dimension
				 */
				$columnTotals[$i][] = $folded[$v];
				$rowTotals[$v][] = $folded[$v];
				$this->tableMatrix['rows'][$v][] = $rowTotals[$k];
				$i++;
			}
			/**
			 *	Sum or average row values
			 */
			if ( is_array($folded[$v]) && sizeof($folded[$v]) > 1 ) {
				$arr = array_filter($rowTotals[$v], 'trim');
				if ( $valueType == 'count' ) {
					$this->tableMatrix['rows'][$v][] = array_sum($arr);
				} else if ( $valueType == 'avg' ) {
					$this->tableMatrix['rows'][$v][] = array_sum($arr) / count($arr);
				} else if ( $valueType == 'sum' ) {
					$this->tableMatrix['rows'][$v][] = array_sum($arr);
				}
			}
		}
		/**
		 *	Sum or average column totals
		 */
		$footerKey = 'totals';
		foreach ( $columnTotals as $k => $v ) {
			$arr = array_filter($v, 'trim');
			if ( $valueType == 'count' ) {
				$columnTotals[$k] = array_sum($arr);
			} else if ( $valueType == 'avg' ) {
				$footerKey = 'averages';
				$count = count($arr);
				if ( $count > 0 ) {
					$columnTotals[$k] = array_sum($arr) / count($arr);
				} else {
					$columnTotals[$k] = false;	
				}
			} else if ( $valueType == 'sum' ) {
				$footerKey = 'sum';
				$columnTotals[$k] = array_sum($arr);
			}
		}
		$this->tableMatrix['footers'][$footerKey] = $columnTotals;
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
		return stristr($this->{$axis}['column'], 'AVG(') !== false || stristr($this->{$axis}['name'], 'AVG(') !== false;
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
		//		col1 AS x_column ... etc
		//	FROM 
		//		counts
		//	JOIN 
		//		... 
		//	GROUP BY 
		//		x_column, y_column, ...etc
		//	ORDER BY 
		//		x_column, total DESC ... etc
		$this->runHook('before-columns');
		$i = 1;
		foreach ( $this->axis as $k => $v ) {
			array_push($this->columns, $this->db->raw($v['column'].' AS '.$k.'_column'));	
			if ( !$this->isGroupByAggregate($v['column']) ) {
				$this->db->groupBy($k.'_column');
				if ( $v['order-by'] ) {
					if ( $v['order-by'] == 'column' ) {
						$this->db->orderBy($k.'_column');
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
	public function rowToArray($row, $keys, $valueType) {
		$out = array();
		$i = 1;
		foreach ( $keys as $v ) {
			if ( $i == 1 ) {
				if ( $valueType == 'sum' ) {
					$out = $row['cross_sum'];
				} else if ( $valueType == 'avg' ) {
					$out = $row['cross_avg'];
				} else {
					$out = $row['cross_count'];
				}
				$i = 2;
			}
			// axisKey_column values might be problematic in certain cases!
			$out = array(
				$row[$v.'_column'] => $out
			);
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
