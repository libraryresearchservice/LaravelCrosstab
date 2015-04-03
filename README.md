LaravelCrosstab
===============

LaravelCrosstab simplifies the process of generating crosstab data from Builder/Eloquent models.  Simply put, it generates a query that groups by two or more columns:

    SELECT column_a AS a_id, column_b AS b_id FROM some_database GROUP BY a_id, b_id

Results can then be placed into an array that can be used to easily create an HTML table of results:

    Array(
    	[headers] => array(
    		[a] => array(
    			[Ferrari] => Ferrari
    			[Nissan] => Nissan
    			[Porsche] => Porsche
    		)
    	),
    	[rows] => array(
    		[Red] => array(
    			[0] => 1,
    			[1] => 
    			[2] => 1
    			[3] => 2
    		),
    		[Black] => array(
    			[0] => 1,
    			[1] => 1
    			[2] => 
    			[3] => 2
    		),
    	),
    	[footers] => array(
    		[total] => array(
    			[1] => 2
    			[2] => 1
    			[3] => 4
    		)
    	)
    )

Configuration
-------------
LaravelCrosstab requires fairly extensive configuration.  The downside is that you (obviously) have to spend some time setting it up.  The upside is that it gives you quite a lot of flexibility.  The following example walks you through the process of generating crosstab data from the following table:

	CREATE TABLE IF NOT EXISTS `crosstab_cars` (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
	  `color_name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
	  `color_id` int(10) unsigned NOT NULL,
	  `top_speed` tinyint(3) unsigned NOT NULL,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=8 ;


	INSERT INTO `crosstab_cars` (`id`, `name`, `color_name`, `color_id`, `top_speed`) VALUES
	(1, 'Ferrari', 'Red', 1, 210),
	(2, 'Ferrari', 'Black', 3, 194),
	(3, 'Nissan', 'Yellow', 2, 210),
	(4, 'Nissan', 'Blue', 4, 186),
	(5, 'Nissan', 'Black', 3, 194),
	(6, 'Porsche', 'Red', 1, 194),
	(7, 'Porsche', 'Yellow', 2, 194);

**Step 1: Define Configuration Settings**

In our example, 'a' and 'b' are keys for two axis. It might help to think of them as 'x' and 'y' as they appear on a graph, e.g. the name of the car by the name of the color.  Axis keys are irrelevant and can be customized using method allowedAxis().  By default, Laravel Crosstab accepts axis with values between 'a' and 'd'.

Data that can be crosstabbed are identifed by a key, e.g. 'car', along with various other configuration settings.

* **header-format**: a callback that will be applied to the first set of headers (i.e.  first horizontal headers)
* **id**: column that represents the unique ID - e.g. 1 - for the data element
* **join**: a callback that will be passed to DB/Eloquent for the purpose of joining additional tables
* **key**: unique identifier for the data element.  *The 'key' attribute is not used by LaravelCrosstab, but might be something you will reference when building HTML tables.*
* **name**:  column that represents the value - e.g. Ferrari - for the data element.
* **title**:  title of the data element.  *The 'title' attribute is not used by LaravelCrosstab, but might be something you will reference when building HTML tables.*

A typical configuration will look like:

    $config = array(
		'a'	=> array(
			'car' => array(
				'column'		=> 'crosstab_cars.name',
				'header-format'	=> false,
				'join'			=> false,
				'title'			=> 'Car'
			),
			'color'	=> array(
				'column'		=> 'crosstab_cars.color_name',
				'header-format'	=> false,
				'join'			=> false,
				'title'			=> 'Color'
			),
			'speed'	=> array(
				'column'		=> 'crosstab_cars.top_speed',
				'header-format'	=> false,
				'join'			=> false,
				'title'			=> 'Top Speed'
			)
		)
	);
    // For our example, configure 'b' exactly like 'a'
    $config['b'] = $config['a'];

**Step 2: Define Axis**

You can define these manually or (more likely) grab them from GET or POST data.

    $axis = array('car', 'color'); // Or maybe you want array(Input::get('a'), Input::get('b'))

These axis (combined with the above configuration, will produce the following query:

	select 
		crosstab_cars.name AS a_id, 
		crosstab_cars.color_name AS b_id, 
		COUNT(1) AS `cross_count` 
	from 
		`crosstab_cars` 
	group by 
		`a_id`, `b_id`

**Step 3:  Create DB/Eloquent Model**

LaravelCrosstab accepts an instance of DB or Eloquent (or an model that inherits from Eloquent). 

    $cars = DB::table('crosstab_cars');

Manipulate the model as much (or little) as you'd like before passing it to LaravelCrosstab.  For example, if you know you only want certain cars:

    $cars = DB::table('crosstab_cars')->where('column', 'value');

**Step 4: Generate Crosstab Data**

First create a new instance of the class by passing your DB/Eloquent model ($cars), the axis you want to query ($axis, and your settings ($config).

    $laravelCrosstab = new Lrs\LaravelCrosstab\LaravelCrosstab($cars, $axis, $config);

To get a (HTML) table friendly matrix of results:

    $table = $laravelCrosstab->getTableMatrix();
    
Or simply:

    $table = $laravelCrosstab();

It is also possible to execute the query without transforming the database results.

    // Execute query
    $laravelCrosstab->get();
    // Loop raw database results
    foreach ( $laravelCrosstab->results as $row ) {
    	// do something
    }

**Step 5:  Create HTML Table**

LaravelCrosstab does not include a method for generating HTML tables, so you're free to structure and style them to best fit your needs/wants/projects.

    <table class="table table-striped">
    	<thead>
    		<?php
    		foreach ( $table['headers'] as $k => $v ) {
    			?>
    			<tr>
    				<th></th>
    				<th class="text-center" colspan="<?php echo sizeof($v) ?>">Cars</th>
    				<th></th>
    			</tr>
    			<tr>
    				<th class="header_<?php echo $i ?>">Colors</th>
    				<?php
    				foreach ( $v as $k1 => $v1 ) {
    					$val = $v1 ? $v1 : 'No Data';
    					?>
    					<th class="header_<?php echo $i ?>" colspan="<?php echo $table['colspans'][$k] ?>"><?php echo $val ?></th>
    					<?php	
    				}
    				if ( $i == 1 && sizeof($v) > 1 ) {
    					?>
    					<th class="header_<?php echo $i ?>" rowspan="<?php echo sizeof($table['headers']) ?>">Total</th>
    					<?php	
    				}
    				?>
    			</tr>
    			<?php
    			$i++;
    		}
    		?>
    	</thead>
    	<tbody>
    		<?php
    		foreach ( $table['rows'] as $k => $v ) {
    			?>
    			<tr>
    				<th class="header-row"><?php echo $k ?></th>
    				<?php
    				if ( !isset($tableax) ) {
    					$max = sizeof($v);	
    				}
    				$i = 1;
    				foreach ( $v as $k1 => $v1 ) {
    					?>
    					<td<?php echo $i == $max ? ' class="row_total"' : '' ?>><?php echo number_format($v1) ?></td>
    					<?php
    					$i++;
    				}
    				?>
    			</tr>
    			<?php
    		}
    		?>
    	</tbody>
    	<tfoot>
    		<?php
    		foreach ( $table['footers'] as $k => $v ) {
    			?>
    			<tr>
    				<th class="header-row">Total</th>
    				<?php
    				foreach ( $v as $k1 => $v1 ) {
    					?>
    					<th><?php echo  number_format($v1) ?></th>
    					<?php	
    				}
    				if ( sizeof($v) > 1 ) {
    					?>
    					<th class="header-row"><?php echo number_format(array_sum($v)) ?></th>
    					<?php
    				}
    				?>
    			</tr>
    			<?php
    		}
    		?>
    	</tfoot>
    </table>

Color  |Ferrari|Nissan|Porsche|Total
-------|-------|------|-------|-----
Black  |1      |1     |-      |2
Red    |1      |-     |1      |2
Blue   |-      |1     |-      |1
Yellow |-      |1     |1      |2
Total  |2      |3     |2      |7

Advanced Configuration
----------------------

**Specify a join**

For example, suppose our table of cars relates to a table of colors:

	CREATE TABLE IF NOT EXISTS `crosstab_colors` (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=5 ;

	INSERT INTO `crosstab_colors` (`id`, `name`) VALUES
	(1, 'Red'),
	(2, 'Yellow'),
	(3, 'Black'),
	(4, 'Blue');

Include the join in your configuration:

	'colorById'	=> array(
		'header-format'	=> false,
		'column'		=> 'crosstab_colors.name',
		'join'			=> array(
			'key'		=> 'crosstab_colors', // key is used to prevent double joining if another axis has the same join
			'function'	=> function($q) {
				return $q->join('crosstab_colors', 'crosstab_cars.color_id', '=', 'crosstab_colors.id');
			},
		)
		'title'			=> 'Color (joined by ID)'
	),

Your query now looks like:

	select 
		crosstab_cars.name as a_id, 
		crosstab_colors.name as b_id, 
		COUNT(1) as `cross_count` 
	from 
		`crosstab_cars`
	join
		crosstab_cars on crosstab_cars.color_id = crosstab_colors.id
	group by 
		`a_id`, `b_id`

**Format (x axis) header values**

	'colorById'	=> array(
		'column'	=> 'crosstab_colors.id',
		'header-format'	=> function($val) {
			return strtoupper($val);
		},
		'join'		=> function($q) {
			return $q->join('crosstab_colors', 'crosstab_cars.color_id', '=', 'crosstab_colors.id');
		},
		'title'		=> 'Color (joined by ID)'
	),

**Aggregate functions**

By default, LaravelCrosstab->getTableMatrix() returns a matrix that holds the values for COUNT(1).  It is also possible to get a matrix that holds AVG() or SUM() values.

	$crosstab = new Lrs\LaravelCrosstab\LaravelCrosstab($cars, $axis, $config);
	// SUM(crosstab_cars.top_speed) and AVG(crosstab_cars.top_speed)
	$crosstab->agg('sum', 'crosstab_cars.top_speed')->agg('avg', 'crosstab_cars.top_speed');
	// Note:  LaravelCrosstab does **not** execute separate queries to generate the following matrices
	$tableOfSums = $crosstab('sum');
	$tableOfAverages = $crosstab('average');
