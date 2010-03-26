<?php

// author: Adam Shaw
// Dual licensed under the MIT and GPL licenses (MIT-LICENSE.txt and GPL-LICENSE.txt)


//require_once dirname(__FILE__) . '/debug.php';


$GLOBALS['_TemplateInheritance_base'] = null;
$GLOBALS['_TemplateInheritance_stack'] = null;


// TODO: whitespace on same line as block
// TODO: add tests
// - filtered superblock
// - endblock with optional name
// - blockbase


function emptyblock($name) {
	$trace = _TemplateInheritance_trace();
	_TemplateInheritance_init($trace);
	_TemplateInheritance_insertBlock(
		_TemplateInheritance_newBlock($name, null, $trace)
	);
}


function startblock($name, $filters=null) {
	$trace = _TemplateInheritance_trace();
	_TemplateInheritance_init($trace);
	$stack =& $GLOBALS['_TemplateInheritance_stack'];
	$stack[] = _TemplateInheritance_newBlock($name, $filters, $trace);
}


function endblock($name=null) {
	$trace = _TemplateInheritance_trace();
	_TemplateInheritance_init($trace);
	$stack =& $GLOBALS['_TemplateInheritance_stack'];
	if ($stack) {
		$block = array_pop($stack);
		if ($name && $name != $block['name']) {
			_TemplateInheritance_warning("orphan endblock('$name')", $trace);
		}else{
			_TemplateInheritance_insertBlock($block);
		}
	}else{
		_TemplateInheritance_warning(
			$name ? "orphan endblock('$name')" : "orphan endblock()",
			$trace
		);
	}
}


function superblock() {
	if ($GLOBALS['_TemplateInheritance_stack']) {
		echo getsuperblock();
	}else{
		_TemplateInheritance_warning(
			"superblock() call must be within a block",
			_TemplateInheritance_trace()
		);
	}
}


function getsuperblock() {
	$stack =& $GLOBALS['_TemplateInheritance_stack'];
	if ($stack) {
		$hash =& $GLOBALS['_TemplateInheritance_hash'];
		$block = end($stack);
		if (isset($hash[$block['name']])) {
			return implode(
				_TemplateInheritance_compile(
					$hash[$block['name']]['block'],
					ob_get_contents()
				)
			);
		}
	}else{
		_TemplateInheritance_warning(
			"getsuperblock() call must be within a block",
			_TemplateInheritance_trace()
		);
	}
	return '';
}


function flushblocks() {
	$base =& $GLOBALS['_TemplateInheritance_base'];
	if ($base) {
		$stack =& $GLOBALS['_TemplateInheritance_stack'];
		$level =& $GLOBALS['_TemplateInheritance_level'];
		while ($block = array_pop($stack)) {
			_TemplateInheritance_warning(
				"missing endblock() for startblock('{$block['name']}')",
				_TemplateInheritance_trace(),
				$block['trace']
			);
		}
		while (ob_get_level() > $level) {
			ob_end_flush(); // will eventually trigger bufferCallback
		}
		$base = null;
		$stack = null;
	}
}


function blockbase() {
	_TemplateInheritance_init(
		_TemplateInheritance_trace()
	);
}


function _TemplateInheritance_init($trace) {
	$base =& $GLOBALS['_TemplateInheritance_base'];
	if ($base && !_TemplateInheritance_inBaseOrChild($trace)) {
		flushblocks(); // will set $base to null
	}
	if (!$base) {
		$base = array(
			'trace' => $trace,
			'filters' => null, // purely for compile
			'children' => array(),
			'start' => 0, // purely for compile
			'end' => null
		);
		$GLOBALS['_TemplateInheritance_level'] = ob_get_level();
		$GLOBALS['_TemplateInheritance_stack'] = array();
		$GLOBALS['_TemplateInheritance_hash'] = array();
		$GLOBALS['_TemplateInheritance_end'] = null;
		$GLOBALS['_TemplateInheritance_after'] = '';
		ob_start('_TemplateInheritance_bufferCallback');
	}
}


function _TemplateInheritance_newBlock($name, $filters, $trace) {
	$base =& $GLOBALS['_TemplateInheritance_base'];
	$stack =& $GLOBALS['_TemplateInheritance_stack'];
	while ($block = end($stack)) {
		if (_TemplateInheritance_isSameFile($block['trace'], $trace)) {
			break;
		}else{
			array_pop($stack);
			_TemplateInheritance_insertBlock($block);
			_TemplateInheritance_warning(
				"missing endblock() for startblock('{$block['name']}')",
				_TemplateInheritance_trace(),
				$block['trace']
			);
		}
	}
	if ($base['end'] === null && !_TemplateInheritance_inBase($trace)) {
		$base['end'] = ob_get_length();
	}
	if ($filters) {
		if (is_string($filters)) {
			$filters = preg_split('/\s*[,|]\s*/', trim($filters));
		}
		else if (!is_array($filters)) {
			$filters = array($filters);
		}
		foreach ($filters as $i => $f) {
			if ($f && is_string($f) && !function_exists($f)) {
				_TemplateInheritance_warning(
					"filter '$f' is not defined",
					$trace
				);
				$filters[$i] = null;
			}
		}
	}
	return array(
		'name' => $name,
		'trace' => $trace,
		'filters' => $filters,
		'children' => array(),
		'start' => ob_get_length()
	);
}


function _TemplateInheritance_insertBlock($block) { // at this point, $block is done being modified
	$base =& $GLOBALS['_TemplateInheritance_base'];
	$stack =& $GLOBALS['_TemplateInheritance_stack'];
	$hash =& $GLOBALS['_TemplateInheritance_hash'];
	$end =& $GLOBALS['_TemplateInheritance_end'];
	$block['end'] = $end = ob_get_length();
	$name = $block['name'];
	if ($stack || _TemplateInheritance_inBase($block['trace'])) {
		$block_anchor = array(
			'start' => $block['start'],
			'end' => $end,
			'block' => $block
		);
		if ($stack) {
			// nested block
			$stack[count($stack)-1]['children'][] =& $block_anchor;
		}else{
			// top-level block in base
			$base['children'][] =& $block_anchor;
		}
		$hash[$name] =& $block_anchor; // same reference as children array
	}
	else if (isset($hash[$name])) {
		if (_TemplateInheritance_isSameFile($hash[$name]['block']['trace'], $block['trace'])) {
			_TemplateInheritance_warning(
				"cannot define another block called '$name'",
				_TemplateInheritance_trace(),
				$block['trace']
			);
		}else{
			// top-level block in a child template; override the base's block
			$hash[$name]['block'] = $block;
		}
	}
}


function _TemplateInheritance_bufferCallback($buffer) {
	$base =& $GLOBALS['_TemplateInheritance_base'];
	$stack =& $GLOBALS['_TemplateInheritance_stack'];
	$end =& $GLOBALS['_TemplateInheritance_end'];
	$after =& $GLOBALS['_TemplateInheritance_after'];
	if ($base) {
		while ($block = array_pop($stack)) {
			_TemplateInheritance_insertBlock($block);
			_TemplateInheritance_warning(
				"missing endblock() for startblock('{$block['name']}')",
				_TemplateInheritance_trace(),
				$block['trace']
			);
		}
		if ($base['end'] === null) {
			$base['end'] = strlen($buffer);
			$end = null; // todo: more explanation
			// means there were no blocks other than the base's
		}
		$parts = _TemplateInheritance_compile($base, $buffer);
		// remove trailing whitespace from end
		$i = count($parts) - 1;
		$parts[$i] = rtrim($parts[$i]);
		// if there are child template blocks, preserve output after last one
		if ($end !== null) {
			$parts[] = substr($buffer, $end);
		}
		// for error messages
		$parts[] = $after;
		return implode($parts);
	}else{
		return '';
	}
}


function _TemplateInheritance_compile($block, $buffer) {
	$parts = array();
	$previ = $block['start'];
	foreach ($block['children'] as $child_anchor) {
		$parts[] = substr($buffer, $previ, $child_anchor['start'] - $previ);
		$parts = array_merge(
			$parts,
			_TemplateInheritance_compile($child_anchor['block'], $buffer)
		);
		$previ = $child_anchor['end'];
	}
	if ($previ != $block['end']) {
		// could be a big buffer, so only do substr if necessary
		$parts[] = substr($buffer, $previ, $block['end'] - $previ);
	}
	if ($block['filters']) {
		$s = implode($parts);
		foreach ($block['filters'] as $filter) {
			if ($filter) {
				$s = call_user_func($filter, $s);
			}
		}
		return array($s);
	}
	return $parts;
}


function _TemplateInheritance_warning($message, $trace, $warning_trace=null) {
	if (error_reporting() & E_USER_WARNING) {
		if (defined('STDIN')) {
			// from command line
			$format = "\nWarning: %s in %s on line %d\n";
		}else{
			// from browser
			$format = "<br />\n<b>Warning</b>:  %s in <b>%s</b> on line <b>%d</b><br />\n";
		}
		if (!$warning_trace) {
			$warning_trace = $trace;
		}
		$s = sprintf($format, $message, $warning_trace[0]['file'], $warning_trace[0]['line']);
		if (!$GLOBALS['_TemplateInheritance_base'] || _TemplateInheritance_inBase($trace)) {
			echo $s;
		}else{
			$GLOBALS['_TemplateInheritance_after'] .= $s;
		}
	}
}


/* backtrace utilities
------------------------------------------------------------------------*/


function _TemplateInheritance_trace() {
	$trace = debug_backtrace();
	foreach ($trace as $i => $location) {
		if ($location['file'] !== __FILE__) {
			return array_slice($trace, $i);
		}
	}
}


function _TemplateInheritance_inBase($trace) {
	return _TemplateInheritance_isSameFile($trace, $GLOBALS['_TemplateInheritance_base']['trace']);
}


function _TemplateInheritance_inBaseOrChild($trace) {
	$base_trace = $GLOBALS['_TemplateInheritance_base']['trace'];
	return
		$trace && $base_trace &&
		_TemplateInheritance_isSubtrace(array_slice($trace, 1), $base_trace) &&
		$trace[0]['file'] === $base_trace[count($base_trace)-count($trace)]['file'];
}


function _TemplateInheritance_isSameFile($trace1, $trace2) {
	return
		$trace1 && $trace2 &&
		$trace1[0]['file'] === $trace2[0]['file'] &&
		array_slice($trace1, 1) === array_slice($trace2, 1);
}


function _TemplateInheritance_isSubtrace($trace1, $trace2) { // is trace1 a subtrace of trace2
	$len1 = count($trace1);
	$len2 = count($trace2);
	if ($len1 > $len2) {
		return false;
	}
	for ($i=0; $i<$len1; $i++) {
		if ($trace1[$len1-1-$i] !== $trace2[$len2-1-$i]) {
			return false;
		}
	}
	return true;
}


?>
