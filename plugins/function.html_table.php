<?php
/**
 * Smarty plugin
 * @package Smarty
 * @subpackage plugins
 */


/**
 * Smarty {html_table} function plugin
 *
 * Type:     function<br>
 * Name:     html_table<br>
 * Date:     Feb 17, 2003<br>
 * Purpose:  make an html table from an array of data<br>
 * Input:<br>
 *         - loop = array to loop through
 *         - cols = number of columns
 *         - table_attr = table attributes
 *         - tr_attr = table row attributes (arrays are cycled)
 *         - td_attr = table cell attributes (arrays are cycled)
 *         - trailpad = value to pad trailing cells with
 * 
 * Examples:
 * <pre>
 * {table loop=$data}
 * {table loop=$data cols=4 tr_attr='"bgcolor=red"'}
 * {table loop=$data cols=4 tr_attr=$colors}
 * </pre>
 * @author	 Monte Ohrt <monte@ispi.net>
 * @version  1.0
 * @link http://smarty.php.net/manual/en/language.function.html.table.php {html_table}
 *          (Smarty online manual)
 * @param array
 * @param Smarty
 * @return string
 */
function smarty_function_html_table($params, &$smarty)
{
	$table_attr = 'border="1"';
	$tr_attr = '';
	$td_attr = '';
	$cols = 3;
	$trailpad = '&nbsp;';
	
	extract($params);

    if (!isset($loop)) {
        $smarty->trigger_error("html_table: missing 'loop' parameter");
        return;
	}
	
	$output = "<table $table_attr>\n";
	$output .= "<tr " . smarty_function_html_table_cycle('tr', $tr_attr) . ">\n";

	for($x = 0, $y = count($loop); $x < $y; $x++) {
		$output .= "<td " . smarty_function_html_table_cycle('td', $td_attr) . ">" . $loop[$x] . "</td>\n";		
		if((!(($x+1) % $cols)) && $x < $y-1) {
			// go to next row
			$output .= "</tr>\n<tr " . smarty_function_html_table_cycle('tr', $tr_attr) . ">\n";
		}
		if($x == $y-1) {
			// last row, pad remaining cells
			$cells = $cols - $y % $cols;
			if($cells != $cols) {
				for($padloop = 0; $padloop < $cells; $padloop++) {
					$output .= "<td " . smarty_function_html_table_cycle('td', $td_attr) . ">$trailpad</td>\n";
				}
			}
			$output .= "</tr>\n";
		}
	}
			
	$output .= "</table>\n";
	
	return $output;
}

function smarty_function_html_table_cycle($name, $var) {
	static $names = array();
	
	if(!is_array($var)) {
		return $var;
	}
	
	if(!isset($names[$name]) || $names[$name] == count($var)-1) {
		$names[$name] = 0;
		return $var[0];
	}
	
	$names[$name]++;
	return $var[$names[$name]];
	
}


/* vim: set expandtab: */

?>
