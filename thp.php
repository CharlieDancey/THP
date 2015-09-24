<?php

/* May 2014, added backquotes and commas to parser
   `expr - quotes expression but...
   `(something ,else) - quotes expression while evaluating else
   as with quote these are expanded to function calls so
   'expr - (quote expr)
   `expr - (backquote expr)
   ,expr - (comma expr)
   
   Next we'll add the functions themselves DONE but not thouroughly tested... 21 May 2014 - CD
   
   
   
   */


// added list_internal_functions, quote
// fixed foreach bug when (foreach [sym:nil] ...) as input would throw an error... April 2014 - CD
// increased MAXSTACK to 10000 - April 2014 EXPERIMENTAL
// changed HTML for yurtco...
// added htmlspecialchars 11 March 2013 - CD
// added (strtr string from to) 11 March 2013 NOT WORKING
/* 
 5th March, added techno css - CD
 December 2010 Version - REVISED Dec 2011 by CD - Revised December 2012 - Added exec from another version Feb 2013
 LAST EDIT 1st March 2013 - latest version at this time.
 
 
 KNOWN BUGS/ISSUES
 
    Seem to have a problem stacking function definitions


	THIS should use array index rather than element value. 					- IT DOES NOW!
	You cannot tell whether function args are evaluated or not 				- YOU CAN IN USER FUNCTIONS
	You cannot suppress arg evaluation in a function definition 			- YES YOU CAN USE AN UNDERSCORE	
	There are no automatically called constructor functions for objects. 	- THERE ARE NOW!!
	
	There is no (parent ...) or (super ...)  to match (this... )
	
*/


set_time_limit(240); 									// how long a script can run before dying.
error_reporting(E_ERROR |  E_PARSE);         // keep warnings on
date_default_timezone_set('UTC');						// affects date handling *required* for date() function


$ver = explode( '.', PHP_VERSION );
$ver_num = $ver[0] . $ver[1] . $ver[2];

if( $ver_num < 500 )
{
	die("This version of THP requires PHP5.");
}



/* SAMPLE OBJECT CODE */
/*

(class 'testclass 'root '(a b (c 4) (d (plus 2 2))) '((testclassfun (function (x) (times 2 x))))) 
(class 'newclass 'testclass '((a 444) (size 44) (description "cool object") (z 123)) nil) 

(class 'table
       'root
       '((width 100)
         (height 100)
         (rows 2)
         (cols 2)
         (color 'red)
         (border 0)
         (filler "•"))
       '(
            (setcolor
                (function (x)
                    (setthis color x)))
            (setborder
                (function (x)
                    (setthis border x)))
            (draw
                (function ()
                    (htmlblock 'table ((border (this border))(bgcolor (this color))(width (this width))(height (this height)))
                         (htmlblock 'tr ()
                            (htmlblock 'td () (this filler))))))
       )
 )

(class 'vector2d 
       'root 
       '((x 10) (y 10)) 
       '(
          
          (print 
            (function () 
                (interpolate "|" "VECTOR x=|(this x)| y=|(this y)|")))
          (times 
            (function (x) 
                (setthis x (times (this x) 2)) 
                (setthis y (times (this y) 2))))
          (setx (function (x) (setthis x x)))
          (sety (function (y) (setthis y y)))
          (setz (function (z) (setthis z z)))
          
          (length
            (function ()
                (local ((x (this x))(y (this y)))
                    (sqrt (plus (times x x)(times y y))))))
          
          (manhattan 
            (function () 
                (plus (abs (this x))(abs (this y)))))))
          

(setq x (new 'vector2d))
(setq x (new 'vector2d))
(setq x (new 'vector2d))
(x (length))
(x (setx 18))
(x (manhattan))
(minus (x (manhattan)) (x (length)))
(setq x (new 'table))
(x (setcolor 'blue))
(x (setborder 3))
(print (x border))
(x (draw))



*/


/* INTERPOLATION
	
	1: If string contains '|' it will be interpolated at eval time.
	2: There is no string escaping. Use (chr 34) for \" and (chr 124) for |
	
	Then we'll change the syntax to
	
	`|| and here's some text |(plus 2 2)|`
	`-- and here again x=-x-`
	
	With the proviso that we can also escape in these strings:
	
	`|| The vertical bar \| can be used to enclose items to be evaluated at runtime as in \|(plus 2 2)\| which expands to |(plus 2 2)|`
	
	Actually, fuck it, why not just use | as before but *don't* interpolate a string unless it contains unescaped vertical bars.
	
	"two plus two is |(plus 2 2)|"
	"Interpolation looks like this \\\|(plus 2 2)\\\|"
	
*/

/* 
	THP - yet another LISP interpreter from Charlie Dancey
	--------------------------------------------------------
	April 2007
	
	Background: my iLisp interpreter has been deployed for several years now. It's 
	proved an excellent tool for creating web pages and MySQL clients, very robust
	and reliable, short development times although, like most LISPs it runs slowly
	and hence is only suitable for low-traffic applications (like 99% of all web sites).
	
	The big flaw with iLisp was it's string handling. There were only three atomic data types
	in iLisp, symbols, strings and numbers. All were stored internally as PHP strings. Strings
	were distinguised from symbols by means of wrapping them in double quotes, which was
	problematic to say the least.
	
	This LISP takes a new aproach. 
	
	LISTS are stored as PHP arrays, as in iLisp.

	ATOMIC data types are stored as strings with a four character header to indicate the type.
	
	'sym:symbolname'
	'str:this is a string' - string
	'si/:this is an interpolated string using a slash as a delimeter/(plus 2 2)/'
	'si|:this one uses a vertical bar as a delimeter |(plus 2 2)|'
	'num:12.34' - number
	
	Other types can be added.
	
	Some other rules
	
	() evals to and is equal to sym:nil
	
	sym:nil in string context equates to "" ### MAYBE we should use converse of this ???
	sym:nil in number context equates to 0  ### ditto
	sym:nil in list context equates to array()
	
	sym:t in string context is "t"
	sym:t in number context is 1
	sym:t in list context is ERROR
	
	The PHP value NULL, or empty string reolves to sym:nil.
	
*/

/* 	CHANGES from iLisp
	
	STRING parsing now handles escaped characters properly (big change).
	
	STRINGS can be enclosed in normal quotes (") or backquotes (`) thus it is now
	possible to create strings containing single and double quotes without getting lost
	in a sea of backslashes.
	
	INTERPOLATION is now handled by the pseudo-function (interpolate 'sep 'string) in which
	a separator char is defined (anything you like).
	
	LOCAL variables are handled better. Existing values are stacked and retrieved from a FIFO stack,
	so they get restored in reverse order. This fixes an very hard-to-trace bug that can happen if you
	do something like:
	
		(local ((a 1)(b 2)(a 44))
			... )
	
	Note, the same variable name was used twice. This obviously screws up things within the block,
	but if the variables are restored left-to-right it will also screw up variables in the enclosing
	scope. Using a FIFO stack solves this.
	
	Have done the same with (with... ) by converting it into a call to 'local
	
	COMMENTS are now parsed out of the source completely so they can safely be placed anywhere.
	
	STRLEN nil is zero.
	
	EVALUATION OF FUNCTIONS - the head of a list is evaluated repeatedly until *either* it resolves 
	to a function *or* it resolves to a  non-list - in which case an error is raised
		
*/

$_LISP_THIS   		= null;				
$_LISP_SUPER        = 0;
$_LISP_CLASSES   	= array();
/*
	This is the root class on which all others are based, it provides a couple of basic methods.
	(setq myobject (new 'root))
	(myobject (classname)) => root
	(myobject (properties)) => nil

*/

$_LISP_CLASSES['root']    = array( 'name' 	 	=> 'root',
                                   'extends' 	=> NULL,
                                   'properties' => array(),
                                   'methods' 	=> array('classname'  =>_LISP_parse("function () (classname)"),
                                                         'properties' =>_LISP_parse("function () (properties)"),
                                                         'objectindex' =>_LISP_parse("function () (objectindex)")));
                                   


$_LISP_VARS['t'] 	= 'sym:t';
$_LISP_VARS['nil'] 	= 'sym:nil';
$symnil = 'sym:nil';
$listnil = array();


// We now need a stack for local variables, since we have implemented catch and throw.
// Adding an eval stack.... April 2014 -CD

$_LISP_VAR_STACK = array(); // the varstack
$_LISP_VAR_TOP   = 0;       // always points to *next* slot in stack.
$_LISP_EVAL_STACK = array(); // the eval stack holds stuff we are evaluating

function pushEvalStack($obj)
{
	global $_LISP_EVAL_STACK;
	array_push($_LISP_EVAL_STACK, $obj);
	
}

function popEvalStack()
{
	global $_LISP_EVAL_STACK;
	array_pop($_LISP_EVAL_STACK);
}

function _PHP_FUNC_evalstack($obj)
{
	global $_LISP_EVAL_STACK;
	return $_LISP_EVAL_STACK;
}



function popVars($vartop)   // pop back to $vartop
{
	global $_LISP_VARS, $_LISP_VAR_STACK, $_LISP_VAR_TOP;
	while($_LISP_VAR_TOP > $vartop)
	{
		$_LISP_VAR_TOP--;
		$_LISP_VARS[$_LISP_VAR_STACK[$_LISP_VAR_TOP][0]] = $_LISP_VAR_STACK[$_LISP_VAR_TOP][1];
	}
}

function pushVar($varname)
{
	global $_LISP_VARS, $_LISP_VAR_STACK, $_LISP_VAR_TOP;
	
	$_LISP_VAR_STACK[$_LISP_VAR_TOP++] = array($varname, $_LISP_VARS[$varname]); // doesnt work ??? WHAT??! ###
}

function _PHP_FUNC_use($obj) // only here for historical reasons.
{
	return 'sym:t';
}

function report_error($mess)
{
	global $current_code, $_LISP_EVAL_STACK;
	$extra = _LISP_printstring($current_code, true);
	//echo"ERROR: $mess - $extra <br>";
	throw new Exception( serialize(array('sym:error',  "str:$mess" , "str:$extra", $_LISP_EVAL_STACK)));
}

function  _LISP_evalobject(&$obj)
{
	global $_LISP_VARS, $current_code, $_LISP_THIS;
	pushEvalStack($obj);
	if(is_null($obj))
	{
		popEvalStack();
		return 'sym:nil';
	}
		
	// handle lists which are either function calls or objects	
	if(is_array($obj))
		if(sizeof($obj) == 0)
		{
			popEvalStack();
			return 'sym:nil';
		}
		else
		{
			$current_code = $obj;

			$ret = _LISP_eval_list($obj);
			popEvalStack();
			return $ret;
		}
			
	if(is_string($obj))
	{
		switch(substr($obj, 0, 3))
		{
			case 'num': popEvalStack(); return $obj; 								// numbers evaluate to themselves.
			case 'obj': popEvalStack(); return $obj;								// so do objects
			case 'sym': $value = &$_LISP_VARS[substr($obj, 4)];
					

						if( $value == NULL || sizeof($value) == 0 )
						{
							popEvalStack();
							return 'sym:nil';
						}
						else
						{
							popEvalStack();
							return $value; 
						}
			case 'str': if(strstr($obj, '|'))						// we interpolate if we find a vertical bar
						{	
							$ret = _LISP_interpolate($obj);
							popEvalStack();
							return $ret;
						}
						else
						{
							popEvalStack();
							return $obj; 								// so do strings
						}
			default: report_error("evalobject bad object - $obj");
		}
	}	
}

/*

	INTERPOLATE:
	
	In the source code you declare an interpolated string as follows:
	
	(interpolate 'sepchar 'string)
	
	(interpolate '| "2 + 2 = |(plus 2 2)|")
	
	*After* parsing the boock will be converted from:
	
	array('interpolate', "|", "2 + 2 = |(plus 2 2)|")
	
	into
	
	si|:2 + 2 = |(plus 2 2)|
	
	...which will be expanded by _LISP_evalobject at eval time.
	
	This is an improvement over the old scheme in which *every* sring was
	interpolated at eval time - costing a lot of processor cycles.
	
	NEXT STEP, use backquotes for strings that contain quotes.... TODO


*/

function _LISP_interpolate($string)
{
	global $_LISP_VARS, $current_code;
	$current_code = 'str:['. $string . ']';
	$done 		= false;
	$outstring	="str:";
	$separator 	= '|';
	$len 		= strlen($string);
	
	for($i=4;$i<$len;$i++)
	{
		if($string[$i] == $separator)
		{
		    $i++;
		    $var="";
		    while($string[$i] != $separator && $i<$len)
		        $var .= $string[$i++];
		        
		    if($i == $len) report_error("interpolate - unexpected end of interpolation");
		    $expr = _LISP_parse($var);
		    foreach($expr as $item) $value = _LISP_evalobject($item);
		    if(is_array($value))
		    	$outstring .= _LISP_printstring($value, false);
		    else	
		    	$outstring .= substr($value, 4);
		 }
		 else
		    $outstring .= $string[$i];
	}
	return $outstring;
}

function _LISP_eval_list($obj) ///### empty functions return nothing...not even nil
{
	global $_LISP_VARS, 
	       $stackwarning, 
	       $_LISP_THIS, 
	       $_LISP_CLASSES, 
	       $_LISP_OBJECTS,
	       $_LISP_VAR_TOP,
	       $current_code;

	$MAXSTACK = 10000;
	if(++$stackwarning > $MAXSTACK) exit("#err# - Recursion limit hit!! (nested $stackwarning times) Aborting!<br><br>");

	if(@substr($funname = $obj[0], 0, 4) == 'sym:')      						// HEAD is a symbol
	{
		$funname = strtolower(substr($obj[0], 4)); 								// function names are not case sensitive
		if($funname == 'quote')  												// (quote x), also written 'x returns x unevaluated
		{	
			$stackwarning--;
			return $obj[1];
		}
		elseif(function_exists($handle = "_PHP_FUNC_$funname"))					// check for PHP function
		{	
			$ret = $handle($obj);												// and execute it.
			$stackwarning--;
			return $ret;
		}
		else
		{
			$thefunction = $_LISP_VARS[$funname] 						            // try looking up symbol, this MUST return something useful (object or function)
			   or report_error("$funname is neither a function nor an object (1)");
			//print_r($thefunction);   
			if(@substr($thefunction, 0, 4) == 'sym:')
				report_error("$funname is neither a function nor an object ($thefunction) (1.1)");
			$obj[0] = $thefunction;													// replace it and recurse (TODO should check stack here)
			
			$ret = _LISP_eval_list($obj);											// and loop and therein lies the rub...
			$stackwarning--;
			return $ret;
		}
	}
	elseif(@substr($funname = $obj[0], 0, 4) == 'obj:')							// object call (object (fun arg)) or (object 'var)
	{																			// or indeed (object (fun1 arg) (fun2 arg) var) which would eval the two fun calls and end up returning var. DUN
		  $theobject = $obj[0];												// obj:12
		  
		  $theobjectindex = substr($theobject, 4);
		  
		  if(($theobjectref = $_LISP_OBJECTS[$theobjectindex]) == NULL)  // look up object
		  	report_error("missing object (it may have been deleted)");
		  
		   
		  
		  $ret = 'sym:nil'; 													// what is they did (object) missing out anything to do?
		  	
		  for($i=1; $i< sizeof($obj); $i++)
		  {
			  if(is_string($obj[$i]) && substr($obj[$i], 0, 4) == 'sym:')				// flat property call
			  {
				$ret = $_LISP_OBJECTS[$theobjectindex]['properties'][substr($obj[$i], 4)] or 
					report_error(_LISP_printstring($theobject, false) . " has no such property [" . substr($obj[$i], 4) . "]");	
			  }
			  elseif(is_array($obj[1])) 											// method call (object (double 44))
			  {
					$stack_LISP_THIS = $_LISP_THIS;
					$_LISP_THIS = $_LISP_OBJECTS[$theobjectindex]['index'];
					$markclass = $the_class 	= $_LISP_OBJECTS[$theobjectindex]['class'];
					$the_method_call 	= $obj[$i][0];								// should be like 'sym:double'
					
					if(substr($the_method_call, 0, 4) != 'sym:') 
						report_error("bad method call, expected a symbol");
					$the_method_name    = substr($the_method_call, 4);

					for(;;)
					{
						$the_method = $_LISP_CLASSES[$the_class]['methods'][$the_method_name];
						if($the_method != NULL) break;
						if($the_class == 'root') report_error("method ($the_method_name ... ) not found for class $markclass.");
						$the_class = $_LISP_CLASSES[$the_class]['extends'];
					}
																		// $the_method is now something like (function (x) (times 2 x))
					$the_actual_call = $obj[$i];        				// copy array its something like (double 44)
					$the_actual_call[0] = $the_method; 					// replace head with function definition ((function (x) (times 2 x)) 44)
					$ret = _LISP_eval_list($the_actual_call);
					$_LISP_THIS = $stack_LISP_THIS;
					
				  } // end of could be object
			}
			$stackwarning--;
			return $ret;
	}
	elseif(!is_array($obj[0]))		                             // If head is not a list it must be string, number or other - all bad.
	{
		report_error("Bad function call.");									
	}
	elseif(($obj[0][0] == 'sym:function') || ($obj[0][0] == 'sym:macro') )     // It's a list, better check that the head is 'function' or 'macro'
	{
		$thefunction = $obj[0]; 			                                   // head is a list so treat as an anonymous function
		$thisisamacro = ($obj[0][0] == 'sym:macro');							// I'll clean this up later, 20 May 2014 - CD
		
		// PUSH local variables...
		// We either have a list of symbols - (a b c) - bind each to args...
		// or a list containg a list containing a symbol -((x)) - bind x to list of args...
		
		$local_vars = $thefunction[1]; // nil or (a b c) or ((x))
		if($local_vars == 'sym:nil')
		   $local_vars = array();
		
		$holdvarstacktop = $_LISP_VAR_TOP; // hold stack
		
		if(is_array($local_vars[0]))
		{
			
			
			 // Its like ((symbol)) in which case symbol is bound to a list of all args
			$symbol = $local_vars[0][0];
			
			if(is_array($symbol) || $symbol == "sym:nil" || substr($symbol, 0, 4) != 'sym:') 
			{
				$current_code = $current_code[0];
				report_error("eval_list()  just noticed a bad or missing variable, expecting something like ((symbol)), in the definition of ");
			}
			$symbolname = substr($symbol, 4);
			
			pushVar($symbolname); // this is name of var as in (x) or (_x)
			
			$varlist = array();
			$suppress_eval = ($symbolname[0]=='_'); // don't evaluate args if name begins with undescore added 20th May 2014 - CD
			
			for($i = 1; $i<sizeof($obj); $i++)
			{
				if($suppress_eval)					// by which we mean the argname started with underscore as in (function ((_x)) ...)
				{
					$varlist[] = $current_code = _LISP_evalobject($obj[$i]);
				}
				else
				{
					$varlist[] = $current_code = $obj[$i];
				}
			}
			
			$_LISP_VARS[$symbolname] = $varlist;
			
		}
		else
		{
			/*
				If a local variable starts with an underscore  then it is NOT evaluated on entry
			*/
			$i=0;
			foreach($local_vars as $var)
			{
				if(is_array($var) || $var == "sym:nil" || substr($var, 0, 4) != 'sym:')
				{
					$current_code = $current_code[0];
					report_error("eval_list()  just noticed a bad variable (nil or t) in the definition of ");
				}
				$varname = substr($var, 4);
				pushVar($varname);
				if($varname[0] == '_')
					$_LISP_VARS[substr($local_vars[$i], 4)] = $current_code = $obj[$i + 1];
				else
					$_LISP_VARS[substr($local_vars[$i], 4)] = $current_code = _LISP_evalobject($obj[$i + 1]);
				$i++;
			}
			/*ASSIGN local variables
			for($i=0; $i<sizeof($local_vars); $i++)
				$_LISP_VARS[substr($local_vars[$i], 4)] = _LISP_evalobject($obj[$i + 1]);
			*/
		}
		
		if($obj[0][0] == 'sym:macro')
		{
			// first evaluate each part and assemble the macro expansion
			$theexpandedmacro = array();
			for($i=2; $i< sizeof($thefunction); $i++)
			{
				$theexpandedmacro[] = $current_code = _LISP_evalobject($current_code = $thefunction[$i]);
			}
			// now evaluate the expanded macro
			for($i=0; $i< sizeof($theexpandedmacro); $i++)
			{
				$ret = $current_code = _LISP_evalobject($current_code = $theexpandedmacro[$i]);
			}
		}
		else // function
		{		
			// evaluate the function itself
			for($i=2; $i< sizeof($thefunction); $i++)
				$ret = $current_code = _LISP_evalobject($current_code = $thefunction[$i]);
		}	
			
		popVars($holdvarstacktop);
		
		$stackwarning--;
		return $ret;
	}
	else													//we get here when we have something like ((convsym "plus") 2 2)
	{
		$obj[0] = _LISP_eval_list($obj[0]);					// replace head with evaluation and recurse
		$ret = _LISP_eval_list($obj);
		$stackwarning--;
		return $ret;
	}
}
		
// Prints an object into a LISP parseable string, strings are therefore quoted
function _LISP_printstring($obj, $quote_it) 
{
	global $_LISP_OBJECTS;
	if(is_array($obj))
	{
		if(sizeof($obj) == 0)
		{
			return 'nil';
		}
		else
		{
			/*$ret= '( ';
			foreach($obj as $item)
			{
				$ret .= _LISP_printstring($item, $quote_it);
				$ret .= ' ';
			}
			$ret .= ')';*/
			foreach($obj as $item)
			{
				$tmp[] = _LISP_printstring($item, $quote_it); 
			}
			return '(' . implode(' ', $tmp) . ')';
			
			//return $ret;
		}
	}
	else
	{
		switch(substr($obj, 0, 4))
		{
			case 'str:':if($quote_it)
					    {
							return '"'. substr($obj,4) . '"'; // was addslashes do not know why...
						}
						else
						{
							return substr($obj,4);
						}
			case 'obj:': $index = substr($obj,4);					// could override here...
			             $class = $_LISP_OBJECTS[$index]['class'];
						 return "(object $index)";
			default:     return substr($obj,4);
		}
	}
}

// Prints an object, no quotes on strings... 
function _LISP_princ($obj) 
{
	global $_LISP_OBJECTS;
	if(is_array($obj))
	{
		if(sizeof($obj) == 0)
		{
			return 'nil';
		}
		else
		{
			foreach($obj as $item)
			{
				$tmp[] = _LISP_princ($item, $quote_it); 
			}
			return '(' . implode(' ', $tmp) . ')';
		}
	}
	else
	{
			
		switch( substr( $obj,0,4))
		{	
			case 'obj:': $index = substr($obj,4);					// could override here...
			             $class = $_LISP_OBJECTS[$index]['class'];
						 return "(object $index)";
			default: return substr($obj,4);	
		}
	}
}

// Prints an object, with quotes on strings... 
function _LISP_printq($obj) 
{
	global $_LISP_OBJECTS;
	if(is_array($obj))
	{
		if(sizeof($obj) == 0)
		{
			return 'nil';
		}
		else
		{
			foreach($obj as $item)
			{
				$tmp[] = _LISP_printq($item, $quote_it); 
			}
			return '(' . implode(' ', $tmp) . ')';
		}
	}
	else
	{
			
		switch( substr( $obj,0,4))
		{	
			case 'obj:': $index = substr($obj,4);					// could override here...
			             $class = $_LISP_OBJECTS[$index]['class'];
						 return "(object $index)";
			case 'str:': return '"' . addslashes(substr($obj,4)) . '"';
			default: return substr($obj,4);	
		}
	}
}

function format_multiline_string($s)
{
	// The string starts with a newline, so we'll format and indent it properly
	$lines = split("\n", preg_replace("/\t/", '    ', $s)); // not sure this is working but we'll see
	// now find the number of spaces in the SECOND line before text starts...
	$indent = 0;
	while($lines[1][$indent] == ' ')
		$indent++;
	//.. and that's how much we lop off each line...
	for($i = 0; $i < sizeof($lines); $i++)
		$lines[$i] = substr($lines[$i], $indent);
	return implode($lines, "\r");
}

function _LISP_parse_string($expr, $sep, $escape) // we no longer escape strings....
{ 
  global $current_code;
  $current_code = "sym:$expr";
  
  for($i=1;;)
  {
  	$c = $expr[$i];

 	 if($c  == "")
 	 {
 	 	report_error( "parse string, unexpected end of string " );
 	 }
 	 elseif($c == $sep)
 	 {
		// expand tabs to 4 spaces
		$string = preg_replace("/([\t])/", '    ', $string);
		
		/* handle newlines in strings
		   Source code may be indented, we remove leading indented white space.
		*/
		if(strstr($string, chr(10)))
		{
			$splitstring = split("\n", $string);
			$count = 0;
			for($j=0; $j < strlen($splitstring[1]) && $splitstring[1][$j] == ' '; $j++)
				$count++;
			for($k=0; $k< sizeof($splitstring); $k++)
				for($l=0; $l< $count && $splitstring[$k][0] == ' '; $l++)
					$splitstring[$k] = substr($splitstring[$k], 1);
			$string = implode($splitstring, "\n");	
		}
 	 	return array($string, substr($expr, ++$i));	
 	 }
 	 else
 	 {
 	 	$string .= $c;
 	 }
 	 $i++;
  }
  
}

function _PHP_FUNC_log($obj)
{
	_LISP_log(_LISP_get_string($obj, 1, 1));
}


function _LISP_log($mess)
{
	$fp = fopen('thp.log', 'a');
	fwrite($fp, date('M d H:i:s ') . $mess . "\n");
	fclose($fp);
}

function _PHP_FUNC_load($obj)
{
	return _LISP_load(_LISP_get_string($obj, 1, 1));
}


function _LISP_load($filename) // internal load function, we'll almost always use the LISP call.
{
	
	// ### logic here could do with a tidy to handle missing source files...
	if(!file_exists($filename))
		_LISP_log("Load $filename - file not found, looking for parsed version.");
	
	if(file_exists($filename . ".parsed") && filemtime($filename . ".parsed") > filemtime($filename))
	{
	   $filename .= ".parsed";
	}
	
	if(!file_exists($filename))
	{
		_LISP_log("Load $filename - file not found.");
		report_error("Load $filename - file not found.");
	}
	
	$expr     		= file_get_contents($filename);
	
	
	if(substr($expr,0,11) == "#!parsedthp")
	{
	   $parsed_expr = unserialize(substr($expr,11)); // we have a parsed file...
	   _LISP_log("Load $filename - using pre-parsed version");
	}
	else
	{
	   $parsed_expr 	= _LISP_parse($expr);         // the file isn't parsed, so parse it!
	   $serialized_expr = serialize($parsed_expr);    // create a PHP readable version of the code 
	   $outfilename     = $filename . ".parsed";      // (loads in hundredths rather than several secs)
	   $fp              = fopen($outfilename, "w");
	   fwrite($fp, "#!parsedthp" . $serialized_expr);
	   fclose($fp);
	   _LISP_log("Load $filename - saved new pre-parsed version");
	}

      
	foreach($parsed_expr as $thing)
	{
		$value = _LISP_evalobject($thing);
	}
	
	return $value;
	
}

function _LISP_parse($source) // ###CURRENTLY returns list of parsed elements, will change this to (do [elems]...)
{
	global $current_code;
	
	pushEvalstack(array("sym:parse", "str:$source"));
	
    $current_code = "sym:$source";
	$html = htmlspecialchars($source);
	$ret = array();
    $len = strlen($source);
	$i=0;
	$escape = 1;
	$no_escape = 0;

	while($i < $len) // while we have some left
	{
		// skip whitesace
		while(preg_match ('/[\r\t\n ]/',$source[$i]))  $i++; 
		
		//get char
		$char = $source[$i];
		
		if($char == "")
		{
			popEvalStack();
			return $ret;	
		}
		
		$specialcharacters = array();

		
		while(preg_match('/[\r\t\n\'\`\, ]/', $char)) // skip leading space and catch quotes, backquotes and commas
		{
			if($char == "'" || $char == "`" || $char=",")
			{
			    array_push($specialcharacters, $char); //stack up special characters
			}
			$char = $source[++$i];
		}
		
		if($char == '"' ) // It's a string! hand over to parse string... we no longer use backquotes as separators
		{
			$result = _LISP_parse_string(substr($source, $i), $char, $no_escape); // Do not escape, so \" reolves to " 
			$item = 'str:' . $result[0];
			$source = $result[1];
			$i=0;
			
		}
		else if($char == ")")
			report_error("parse, unexpected ) near and after:");
		else if($char == "(")  // It's a list!
		{
			$list = '';
			$nest = 1;
            $i++;
            /* 
            	skip whitespace for a bit... 
            	this is to catch comments, which parse to nothing, we need to be sure that
            	a comment block starts with "(comment" - see below
            */
            while(preg_match ('/[\r\t\n ]/',$source[$i]))  $i++;
            
            // OK now get the plaintext of the list...
			while(true)
			{
				$char = $source[$i];
				if($char == '"') //keep going until we hit closing quote, or end of source, which would be an error
				{
					while(true)
					{
						$list .= $char; 		//swallow char
						$char=$source[++$i];	//move on
						if($char == NULL) report_error("Parse, unexpected end of string while getting plaintext of list [$list] character we failed on is [$char]"); // ran out of string.
						if($char == '"')          // hit ending quote
						{
							$list .= $char;		//swallow closing quote
							break;
						}
					}
				}
				else if($char == "(")
				{
					$nest++;
					$list .= $char;
				}
				else if($char == ")")
				{
					if(--$nest == 0)
					{
						$i++; 
						break;
					}
					else
						$list .= $char;
				}
				else if($char == "")
				{
					$current_code = $list;
					report_error("parse, unterminated list (");
				}
				else
					$list.=$char;
				$i++;
			}
			
			/* 
				We now have the plaintext of the list, 
				if it starts with "(comment " then we 
				can ignore it, so mark as such. 
			 */
			
			if(strtolower(substr($list, 0, 7)) == 'comment')
				$item = 'COMMENT';
			else
			{
				$item = _LISP_parse($list);
				
				/*
					If the list is of the form (interpolate 'sep 'string)
					then we convert the string into an si*: for interpolation
					at runtime.
				*/
				
				if(is_array($item) && sizeof($item) && $item[0] == 'sym:interpolate')
				{
					$sep 	= $item[1][4]; 			// should be single char
					$str 	= substr($item[2], 4);     // should be a string
					$item 	= "si{$sep}:$str"; 
				}
			}
		}
		else
		{	
			$sym = $char;
			$i++;
			$done = false;
			while(!$done)
			{
				$char = $source[$i];
				if($char == "" || $char == ")" || $char == "(" || $char=="\"" || preg_match('/[\r\t\n ]/', $char)) break;
				
				$sym .= $char;
				$i++;
			
			}
			// this could be a number or a symbol lets check
			if(is_numeric($sym))
				$item = 'num:' . ($sym + 0);
			else
			{	if(!preg_match('/[a-zA-Z]/', $sym)) report_error("parse, symbol does not start with alpha character: \"$sym\"");
				$item = 'sym:' . $sym;
			}
		}
		
		if(!empty($specialcharacters)) // catch quote backquote and comma...
		{
			while($char = array_pop($specialcharacters))
			{   
			    switch($char)
			    {
			    	case "'": $item = array('sym:quote', $item); break;
			    	case "`": $item = array('sym:backquote', $item); break;
			    	case ",": $item = array('sym:comma', $item); break;
			    	default: die("INTERNAL ERROR in parse: bad special character");
			    }
			}
			$ret[] = $item;
		}
		elseif($item != 'COMMENT')
		{
			$ret[] = $item;
		}
	}
	 popEvalStack();
	return $ret;
}

function _LISP_get_number($obj, $index, $eval) // returns actual PHP number, not LISP number
{
	global $current_code;
	if($eval)
		$x = $current_code = _LISP_evalobject($obj[$index]);
	else
		$x = $current_code = $obj[$index];
	if(($type=substr($x, 0, 4)) == 'num:') return substr($x, 4); 	
	if(is_array($x))
	{
		report_error("expected a number, got a list");
	}
	if(is_null($x)) return 0;
	if($x == 'sym:nil') return 0;
	if(is_numeric($r=substr($x, 4))) return $r;
    report_error("expected a number, got a $type" );
}

function _LISP_get_bool($obj, $index, $eval) // returns PHP value, not LISP value
{
	global $current_code;
	
	if($eval)
		$x = $current_code = _LISP_evalobject($obj[$index]);
	else
		$x = $current_code = $obj[$index];
		
	if(is_null($x)) return false; // means that NULL is NIL
	if(sizeof($x) == 0) return false;
	if($x == 'sym:nil') return false;
	if(is_array($x)) return true;
	if(strlen($x) == 4) return false; // means that empty string is like nil

	return true;
}

function _PHP_FUNC_true($obj)
{
	return(_LISP_get_bool($obj, 1, 1))? 'sym:t' : 'sym:nil';
}

function _LISP_get_string($obj, $index, $eval) // returns PHP string
{
	global $current_code;
	if($eval)
		$x = $current_code = _LISP_evalobject($obj[$index]);
	else
		$x = $current_code = $obj[$index];
	
	// check if it is a list
	if(is_array($x)) report_error('got a list, wanted a string ');
	// check nil
	if($x == 'sym:nil' ) return '';
	switch(substr($x, 0, 4))
	{
		case 'sym:':
		case 'str:':
		case 'num:': return substr($x, 4); break;
		
		default: report_error("expected a string (or a number)");
	}
	
}

function _LISP_check_string($x) // get a string from an atom 
{
	if(is_array($x)) report_error('got a list, wanted a string ');
	if($x == 'sym:nil' ) return '';
	switch(substr($x, 0, 4))
	{
		case 'sym:':
		case 'str:':
		case 'num:': return substr($x, 4); break;
		
		default: report_error("expected a string (or a number)");
	}
}

function _LISP_get_varname($obj, $index, $eval)
{
	global $current_code;
	if($eval)
		$x = $current_code = _LISP_evalobject($obj[$index]);
	else
		$x = $current_code = $obj[$index];
	/*
		Here are the rules, you cannot use nil or t 
	*/
    
    if(substr($x, 0, 4) != 'sym:' || $x == 'sym:nil' || $x == 'sym:t') report_error( "bad variable name, " );
	return substr($x, 4);
}

function _LISP_get_symbol($obj, $index, $eval)
{
	global $current_code;
	if($eval)
		$x = $current_code = _LISP_evalobject($obj[$index]);
	else
		$x = $current_code = $obj[$index];
		
	if(is_null($x) || substr($x, 0, 4) != 'sym:') 
		report_error( "expected a symbol");
	return substr($x, 4);
}

function _LISP_get_list($obj, $index, $eval)
{
	global $current_code;
	if($eval)
		$x = $current_code = _LISP_evalobject($obj[$index]);
	else
		$x = $current_code = $obj[$index];
		
	if(is_array($x)) return $x;
	if($x == 'sym:nil') 
		return array();
	
	report_error('expected a list');

}

/* OBJECTS */

/* Once an onject has been created its properties and methods are accessed thus:

	(myobject propertyname) 	- returns the property called "propertyname"
	(myobject (mymethod x y))   - calls the method with the args x and y
	
	You cannot set properties directly instead you meed to define methods to do so, see
	the code below.
	
*/

/*
	Define a class:
	---------------
	(class [classname]	- name of class, evaluated, hence usually something like 'myclass
	       [extends]    - name of parent class, evaluated, use 'root if there is no other parent
	       [properties] - value list, evaluated either '(a b c) to set a, b and c to nil or
	                      '((a 1)(b 2)(c 3)) to initialise or a mixture '(a (b 2) c)
	                      NOTES
	                      1: You *cannot* add properties to object at runtime, you can only use
	                      the ones defined by the class (or it's parents).
	                      2: Each class inherits parents properties.
	       [methods]   	- list of ([name] [function]'s e.g.
	       				  '(
	       				  	(seta (function (x) (setthis a x)))
	       				  	(setb (function (x) (setthis a x)))
	       				  	(geta (function () (this a)))
	       				  	(setab (functions (x y) (setthis a x)(setthis b y))))
	)
	
*/

function _PHP_FUNC_class($obj)
{
	global $_LISP_CLASSES;
	$name 				= _LISP_get_symbol($obj, 1, 1);
	$extends 			= _LISP_evalobject($obj[2]);
	if($extends == 'sym:nil')
		$extends = 'root';
	else
		$extends = substr($extends, 4);
		
	$lispproperties 	= _LISP_get_list($obj, 3, 1);  // (a b (c 1) (d (plus 2 2)) ...) 
	$lispmethods   		= _LISP_get_list($obj, 4, 1);  // ((a (function (a b) (plus a b))) (b ...) etc)
	
	$methods = array();
	foreach($lispmethods as $lispitem)
	{
		$key = _LISP_get_symbol($lispitem, 0, 0);
		$code = _LISP_get_list($lispitem, 1, 0);
		$methods[$key] = $code;
	}
	// inherit properties from lower classes
	$properties = $_LISP_CLASSES[$extends]['properties'];
	foreach($lispproperties as $lispitem)
	{
		if(is_array($lispitem))
		{
			$key = _LISP_get_symbol($lispitem, 0, 0);
			$val = _LISP_evalobject($lispitem[1]);
			$properties[$key] = $val;
		}
		elseif(substr($lispitem, 0, 4) == 'sym:')
		{
			$key = substr($lispitem, 4);
			$val = "sym:nil";
			$properties[$key] = $val;
		}
	}
	
	
	// Now check that extends is valid
	if($_LISP_CLASSES[$extends] == NULL)
	{
		report_error("class, cannot extend '$extends', it does not exist ");
	}
	if($_LISP_CLASSES[$name] != NULL)
	{
		report_error("class, class already exists");
	}
	
	$_LISP_CLASSES[$name] = array( 'name' 	 	=> $name,
                                   'extends' 	=> $extends,
                                   'properties' => $properties,
                                   'methods' 	=> $methods);
 
	return 'sym:t';
}


/*
	Create an object
	----------------
	(setq myobject (new 'myclass))
	
	NOTE no constructor function is called, this is under development and will surely be aded in due course.
	I'll probably use the device of having a method with the same nameas the class to handle this. TODO!
*/

function _PHP_FUNC_new($obj) //(new 'class 'arg ...)
{	
	global $_LISP_CLASSES, $_LISP_OBJECTS, $_LISP_THIS;
	static $index=0;
	$class = _LISP_get_string($obj, 1, 1);
	if($_LISP_CLASSES[$class] == NULL) report_error("new, no such class as $class");
	$_LISP_OBJECTS[++$index] = array ( 'class' => $class, 'index'=> $index, 'properties' =>  $_LISP_CLASSES[$class]['properties']);
	
	
	// the consructor function, if any, is a method with the same name as its class
	
	if($the_constructor = $_LISP_CLASSES[$class]['methods'][$class])
	{
	
		$stack_lisp_this 		= $_LISP_THIS;
		$_LISP_THIS   			= $index;
	
		array_shift($obj); 							// drop the (new ...) part NOTE this is a destructive function that returns nothing useful - we apply it for side effects only
		$obj[0] 				= $the_constructor; 
		
		$ret                = _LISP_evalobject($obj);
		$_LISP_THIS         = $stack_lisp_this;
	}
	return ('obj:' . $index);
}

function _PHP_FUNC_object($obj) // (object [index]) return object from a given index or nil if there is none.
{
	global $_LISP_OBJECTS;
	$index = _LISP_get_number($obj, 1, 1);
	if($_LISP_OBJECTS[($index)])
		return 'obj:' . $index;
	else
		return 'sym:nil';
}


function _PHP_FUNC_delete($obj) // (delete [object])
{
	global $_LISP_OBJECTS;
	$should_be_an_object = _LISP_evalobject($obj[1]);
	if(substr($should_be_an_object, 0, 4) != 'obj:') report_error("delete, not an object!");
	$index = substr($should_be_an_object, 4);
	
	// should call a destructor here.
	
	$_LISP_OBJECTS[$index] = NULL;
	return 'sym:t';
}


/*
	You can only call (this ..) from within an object method. 
	It has two uses:
	
	(this a) 			- returns the property a of the object whose scope one is in.
	 
	(this (seta 44))	- calls the method (seta ..) of this object, thus allowing one method to
	                      call another.
	
*/
function _PHP_FUNC_this($obj)
{
	global $_LISP_THIS, $_LISP_OBJECTS, $_LISP_CLASSES;
	
	
	if($_LISP_THIS == NULL) report_error("this, outside object scope");
	
	//$_LISP_THIS = $_LISP_OBJECTS[$_LISP_THIS['index']]; // reload a copy in case it has changed TODO fix this messy code!!!
	$evalitem= $obj[1];
	if(substr($evalitem, 0, 4) == 'sym:') // symbol lookup
	{
		$ret = $_LISP_OBJECTS[$_LISP_THIS]['properties'][$thename = substr($evalitem, 4)] or 	report_error("this, property $thename not found");
		return $ret;
	}
	elseif(is_array($evalitem))
	{
		$markclass = $the_class = $_LISP_OBJECTS[$_LISP_THIS]['class'];
		$the_method_call 	= $obj[1][0];					// should be 'sym:[methodname]'
		if(substr($the_method_call, 0, 4) != 'sym:') report_error("this, bad method call, expected a symbol");
		$the_method_name    = substr($the_method_call, 4);
		for(;;)
		{
			$the_method = $_LISP_CLASSES[$the_class]['methods'][$the_method_name];
			if($the_method != NULL) break;
			if($the_class == 'root') report_error("this, method ($the_method_name ...) not found for class $markclass.");
			$the_class = $_LISP_CLASSES[$the_class]['extends'];
		}
		$the_actual_call = $obj[1]; // copy array.
		$the_actual_call[0] = $the_method; // replace head with function definition
		$the_result = _LISP_eval_list($the_actual_call);
		return $the_result;
	}
	else
		report_error("this, expected symbol or list");
}

function _PHP_FUNC_super($obj)
{
	global $_LISP_THIS, $_LISP_OBJECTS, $_LISP_CLASSES, $_LISP_SUPER;
	
	if($_LISP_THIS == NULL) report_error("super, outside object scope");
	
	if(is_array($obj[1])) // expecting (super (somefunction))
	{
		
		$markclass = $the_class = $_LISP_OBJECTS[$_LISP_THIS]['class'];
		$_LISP_SUPER++;
		for($i=0; $i < $_LISP_SUPER; $i++)
		{
			if(($the_class = $_LISP_CLASSES[$the_class]['extends']) == NULL)
				report_error("super, this is the root class!");
		}
		
		$the_method_call 	= $obj[1][0];					// should be 'sym:[methodname]'
		if(substr($the_method_call, 0, 4) != 'sym:') report_error("super, bad method call, expected a symbol");
		$the_method_name    = substr($the_method_call, 4);
		for(;;)
		{
			$the_method = $_LISP_CLASSES[$the_class]['methods'][$the_method_name];
			if($the_method != NULL) break;
			if($the_class == 'root') report_error("super, (x $_LISP_SUPER) method $the_method_name not found for class $markclass.");
			$the_class = $_LISP_CLASSES[$the_class]['extends'];
		}
		$the_actual_call = $obj[1]; // copy array.
		$the_actual_call[0] = $the_method; // replace head with function definition
		
		
		$the_result = _LISP_eval_list($the_actual_call);
		$_LISP_SUPER--;
		return $the_result;
	}
	else
		report_error("super, expected a list");
}

/*
	Get objects class - (classname)
*/
function _PHP_FUNC_classname($obj) // can only be used inside object, best use the default method (objname (classname))
{
	global $_LISP_THIS, $_LISP_OBJECTS;
	if($_LISP_THIS == NULL) report_error("classname, outside object scope");
	$ret = 'sym:' . $_LISP_OBJECTS[$_LISP_THIS]['class'];
	return $ret;
}

function _PHP_FUNC_objectindex($obj) // can only be used inside object, best use the default method (objname (objectindex))
{
	global $_LISP_THIS, $_LISP_OBJECTS;
	if($_LISP_THIS == NULL) report_error("objectindex, outside object scope");
	$ret = 'num:' . $_LISP_THIS;
	return $ret;
}


/*
	Get objects properties - (properties)
*/
function _PHP_FUNC_properties($obj) // can only be used inside object, , best use the default method (objname (properties))
{
	global $_LISP_THIS, $_LISP_OBJECTS;
	if($_LISP_THIS == NULL) report_error("properties, outside object scope");
	
	$ret = array();
	foreach($_LISP_OBJECTS[$_LISP_THIS]['properties'] as $name => $value)
	{
		$ret[] = array("sym:$name", $value)	;
	}

	return $ret;
}

/*
	Get objects methods - (methods)
*/


/*
   	Set a property, can only be called from within an object's method.
   	(setthis a x)
*/
function _PHP_FUNC_setthis($obj)
{
	global $_LISP_THIS, $_LISP_OBJECTS;
	if($_LISP_THIS == NULL) report_error("setthis, outside object scope");
	$name  = _LISP_get_symbol($obj, 1, 0); 		// note we do not eval symbol name (like setq)
	$value = _LISP_evalobject($obj[2]);		   	// that's the value
	if(!key_exists($name, $_LISP_OBJECTS[$_LISP_THIS]['properties'])) // can't set property if it doesn't exist.
		report_error("setthis, property $name not found");
	$_LISP_OBJECTS[$_LISP_THIS]['properties'][$name]=$value;
	

	
	return $value;
}

function _PHP_FUNC_dumpobjects ($obj)
{	
	global $_LISP_OBJECTS;
	echo "<pre>";
	print_r($_LISP_OBJECTS);
	echo "</pre>";
}

function _PHP_FUNC_dumpclasses ($obj)
{	
	global $_LISP_CLASSES;
	echo "<pre>";
	print_r($_LISP_CLASSES);
	echo "</pre>";
	return 'sym:t';
}


/* 
	Dump all variables, they are returned as a list of elements 
	(([name1] [value1])([name2] [value2]) ... )
	
	The first two elements will be (t t) and (nil nil).
	
	Useful code to make this readable would be:
	
	(foreach (dumpvars) thing 
            (terpri thing))
            
    Interestingly the iterator variable 'thing will show it's proper value (i.e. the value
    it had outside the (foreach ...) loop since (dumpvars) is evaluated *before* 'foreach
    starts assigning a sequence of values to 'thing.
    
    Note also that 'thing is local to the scope of the 'foreach block and will revert to
    it's previous value as soon as the loop completes.
            
*/

 function _PHP_FUNC_dumpvars($obj)
 {
 	global $_LISP_VARS;
 	foreach($_LISP_VARS as $name => $value)
 	{
 		$ret[] = array('sym:' . $name, $value);
 	}
 	return $ret;
 }
 
 


/* MATHS */

function _PHP_FUNC_plus($obj) 	// (plus x y) or (plus x y z a b c...) = sum of args.
{
	$ret = 0;
	for($i=1; $i< sizeof($obj); $i++)
		$ret += _LISP_get_number($obj, $i, 1);
	return "num:$ret"; 
}

function _PHP_FUNC_abs($obj) 	// (abs x) = absolute value of arg
{
	return 'num:' . abs(_LISP_get_number($obj, 1, 1)); 
}

function _PHP_FUNC_int($obj) 	// (int x) = integer value of arg
{
	return 'num:' . intval(_LISP_get_number($obj, 1, 1)); 
}

function _PHP_FUNC_inc($obj)	// (inc x) = x + 1
{
	return 'num:' . (_LISP_get_number($obj, 1, 1) + 1); 
}

function _PHP_FUNC_sqrt($obj)	// (sqrt x) = square root of x - no error trapping
{
	return 'num:' . sqrt(_LISP_get_number($obj, 1, 1)); 
}

function _PHP_FUNC_dec($obj)
{
	return 'num:' . (_LISP_get_number($obj, 1, 1) - 1); 
}

function _PHP_FUNC_minus($obj)
{
	$a = _LISP_get_number($obj, 1, 1);
	$b = _LISP_get_number($obj, 2, 1);
	$c = $a - $b;
	return "num:$c"; 
}

function _PHP_FUNC_times($obj)
{
	$a = _LISP_get_number($obj, 1, 1);
	$b = _LISP_get_number($obj, 2, 1);
	$c = $a * $b;
	return "num:$c"; 
}

function _PHP_FUNC_divide($obj)
{
	$a = _LISP_get_number($obj, 1, 1);
	$b = _LISP_get_number($obj, 2, 1);
	$c = $a / $b;
	return "num:$c"; 
}

function _PHP_FUNC_crossproduct($obj) 	// (crossproduct a b) each vector must have three elements
{
	$a = _LISP_get_list($obj, 1, 1);
	$b = _LISP_get_list($obj, 2, 1);
	$x = _LISP_get_number($a,1,0)*_LISP_get_number($b,2,0) - _LISP_get_number($a,2,0)*_LISP_get_number($b,1,0);
	$y = _LISP_get_number($a,2,0)*_LISP_get_number($b,0,0) - _LISP_get_number($a,0,0)*_LISP_get_number($b,2,0);
	$z = _LISP_get_number($a,0,0)*_LISP_get_number($b,1,0) - _LISP_get_number($a,1,0)*_LISP_get_number($b,0,0);
	return array("num:$x","num:$y","num:$z"); 
}

function _PHP_FUNC_addvector($obj) 	// (addvector a b) each vector must have three elements
{
	$a = _LISP_get_list($obj, 1, 1);
	$b = _LISP_get_list($obj, 2, 1);
	$x = _LISP_get_number($a,0,0)+_LISP_get_number($b,0,0);
	$y = _LISP_get_number($a,1,0)+_LISP_get_number($b,1,0);
	$z = _LISP_get_number($a,2,0)+_LISP_get_number($b,2,0);
	return array("num:$x","num:$y","num:$z"); 
}

function _PHP_FUNC_timesvector($obj) 	// (timesvector v n) each vector must have three elements
{
	$v = _LISP_get_list($obj, 1, 1);
	$n = _LISP_get_number($obj, 2, 1);
	$x = _LISP_get_number($v,0,0)*$n;
	$y = _LISP_get_number($v,1,0)*$n;
	$z = _LISP_get_number($v,2,0)*$n;
	return array("num:$x","num:$y","num:$z"); 
}



function _PHP_FUNC_rotate($obj) 	// (rotate point viewmatrix) point is three elements, viewmatrix is vector of three points (3x3)
{
	$point = _LISP_get_list($obj, 1, 1); // (x y z)
	$viewm = _LISP_get_list($obj, 2, 1); // ((x y z)(x y z)(x y z))
	$viewx = _LISP_get_list($viewm, 0, 0); // (x y z)
	$viewy = _LISP_get_list($viewm, 1, 0); // (x y z)
	$viewz = _LISP_get_list($viewm, 2, 0); // (x y z)
	
	$x = _LISP_get_number($point, 0, 0) * _LISP_get_number($viewx, 0, 0) +
	     _LISP_get_number($point, 1, 0) * _LISP_get_number($viewy, 0, 0) +
	     _LISP_get_number($point, 2, 0) * _LISP_get_number($viewz, 0, 0);
	     
	$y = _LISP_get_number($point, 0, 0) * _LISP_get_number($viewx, 1, 0) +
	     _LISP_get_number($point, 1, 0) * _LISP_get_number($viewy, 1, 0) +
	     _LISP_get_number($point, 2, 0) * _LISP_get_number($viewz, 1, 0);
	
	$z = _LISP_get_number($point, 0, 0) * _LISP_get_number($viewx, 2, 0) +
	     _LISP_get_number($point, 1, 0) * _LISP_get_number($viewy, 2, 0) +
	     _LISP_get_number($point, 2, 0) * _LISP_get_number($viewz, 2, 0);
	     
	return array("num:$x","num:$y","num:$z"); 
}
	     

function _PHP_FUNC_vectorlength($obj) 	// (vectorlength '(1 2 3 ...)) any length of vector
{
	$lyst = _LISP_get_list($obj, 1, 1);
	for($i=0; $i< sizeof($lyst); $i++)
		$tot += ($n = _LISP_get_number($lyst, $i, 0)) * $n;
	$ret=sqrt($tot);	
	return "num:$ret"; 
}

function _PHP_FUNC_unitvector($obj) 	// (unitvector '(1 2 3 ...))  any length of vector
{
	$lyst = _LISP_get_list($obj, 1, 1);
	for($i=0; $i< sizeof($lyst); $i++)
		$tot += ($n = _LISP_get_number($lyst, $i, 0)) * $n;
	$divisor=sqrt($tot);
	for($i=0; $i< sizeof($lyst); $i++)
	{
		$n = _LISP_get_number($lyst, $i, 0) / $divisor;
		$ret[] = "num:$n";
	}	
	return $ret; 
}




function _PHP_FUNC_min($obj)
{
	$a = _LISP_get_number($obj, 1, 1);
	$b = _LISP_get_number($obj, 2, 1);
	if($a < $b) return "num:$a"; else return "num:$b";
}

function _PHP_FUNC_max($obj)
{
	$a = _LISP_get_number($obj, 1, 1);
	$b = _LISP_get_number($obj, 2, 1);
	if($a > $b) return "num:$a"; else return "num:$b";
}

function _PHP_FUNC_rand($obj)
{
	if(sizeof($obj) ==2 )
	{
		// old behaviour to match ilisp
		$limit = _LISP_get_number($obj, 1, 1);		
		return 'num:' . rand(0,$limit);
	}
	elseif(sizeof($obj) == 3)
	{
		// (rand 1 4) returns number between 1 and 4
		return 'num:' . rand(_LISP_get_number($obj, 1, 1),_LISP_get_number($obj, 2, 1));
	}
	else
	{
		report_error("(rand ..) expected 1 or two args");
	}
}

// diagnostic function, returns number of arguments
function _PHP_FUNC_arglength($obj)
{
	return 'num:' . (sizeof($obj)-1);
}



function _PHP_FUNC_dechex($obj)
{
	return 'str:'. str_pad( dechex(_LISP_get_number($obj, 1, 1)), _LISP_get_number($obj, 2, 1), '0', STR_PAD_LEFT) ;
}

function _PHP_FUNC_hexdec($obj)
{
	return 'num:'. hexdec(_LISP_get_string($obj, 1, 1));
}


/* STRINGS */

function _PHP_FUNC_chr($obj)
{
	return 'str:' . chr(_LISP_get_number($obj, 1, 1));
}

function _PHP_FUNC_strcat($obj)
{
	$ret = '';
	for($i=1; $i< sizeof($obj); $i++)
		$ret .= _LISP_get_string($obj, $i, 1);
	return "str:$ret"; 
}

function _PHP_FUNC_symcat($obj)
{
	$ret = '';
	for($i=1; $i< sizeof($obj); $i++)
		$ret .= _LISP_get_symbol($obj, $i, 1);
	return "sym:$ret"; 
}

function _PHP_FUNC_split($obj)
{
	$sep = _LISP_get_string($obj, 1, 1);
	$s   = _LISP_get_string($obj, 2, 1);
	$ret = array();
	$ex = explode($sep, $s);
	
	foreach( $ex as $item )
	{
		$ret[] = 'str:' . $item;
	}
	return $ret;
}

function _PHP_FUNC_convsym($obj) // make a string into a symbol
{
	$name = _LISP_get_string($obj, 1, 1);
	return "sym:$name";
}

function _PHP_FUNC_preg_replace($obj)
{
	return 'str:'. preg_replace(_LISP_get_string($obj,1,1),_LISP_get_string($obj,2,1),_LISP_get_string($obj,3,1));
}

function _PHP_FUNC_preg_match($obj)
{
	if(preg_match(_LISP_get_string($obj,1,1),_LISP_get_string($obj,2,1)))
		return 'sym:t';
	else
		return 'sym:nil';	
}


function _PHP_FUNC_type($obj) // get the type of a object
{
	$thing = _LISP_evalobject($obj[1]);
	if(is_array($thing))
	{
		if(sizeof($thing) == 0)
			return 'str:nil';
		else
			return 'str:list';
	}
	else
		if($thing == 'sym:nil')
			return 'str:nil';
		else
			return 'str:' . substr($thing, 0, 3);
}

function _PHP_FUNC_print($obj)
{
	for($i=1; $i < sizeof($obj); $i++)
	{
		echo _LISP_princ(_LISP_evalobject($obj[$i]));
	}
	return 'sym:t';
}

function _PHP_FUNC_printq($obj)
{
	for($i=1; $i < sizeof($obj); $i++)
	{
		echo _LISP_printq(_LISP_evalobject($obj[$i]));
	}
	return 'sym:t';
}

function _PHP_FUNC_terpri($obj)
{
	for($i=1; $i < sizeof($obj); $i++)
	{
		echo _LISP_princ(_LISP_evalobject($obj[$i]));
	}
	echo "<br>\n";
	return 'sym:t';
}

function _PHP_FUNC_terpriq($obj)
{
	for($i=1; $i < sizeof($obj); $i++)
	{
		echo _LISP_printq(_LISP_evalobject($obj[$i]));
	}
	echo "<br>\n";
	return 'sym:t';
}

function _PHP_FUNC_substr($obj)
{
	
	switch(sizeof($obj))
	{
		case 4: 
		        $string = _LISP_get_string($obj, 1, 1);
				$start  = _LISP_get_number($obj, 2, 1);
				$len  = _LISP_get_number($obj, 3, 1);
				
				return 'str:' . substr($string, $start, $len);
				
		case 3: $string = _LISP_get_string($obj, 1, 1);
				$start  = _LISP_get_number($obj, 2, 1);
				return 'str:' . substr($string, $start);
				
		default: report_error('substr, expected 2 or 3 arguments');
		         break;
	}
}

function _PHP_FUNC_trim($obj)
{
	return 'str:' . trim(_LISP_get_string($obj, 1, 1));
}

function _PHP_FUNC_strtr($obj)
{
	$s = _LISP_get_string($obj, 1, 1);
	$f  = _LISP_get_string($obj, 2, 1);
	$t  = _LISP_get_string($obj, 3, 1);

	return 'str:' . strtr($s, $f, $t);
}

function _PHP_FUNC_strtoupper($obj)
{
	return 'str:' . strtoupper(_LISP_get_string($obj, 1, 1));
}

function _PHP_FUNC_strtolower($obj)
{
	return 'str:' . strtolower(_LISP_get_string($obj, 1, 1));
}

function _PHP_FUNC_addslashes($obj)
{
	return 'str:' . addslashes(_LISP_get_string($obj, 1, 1));
}

function _PHP_FUNC_stripslashes($obj)
{
	return 'str:' . stripslashes(_LISP_get_string($obj, 1, 1));
}

function _PHP_FUNC_strlen($obj)
{
	return 'num:' . strlen(_LISP_get_string($obj, 1, 1));
}

function _PHP_FUNC_file_get_contents($obj)
{
	return 'str:'. file_get_contents(_LISP_get_string($obj, 1, 1));
}

function _PHP_FUNC_getcode($obj)
{
	global $_LISP_code;
	return $_LISP_code;
}


/* HTML */


function _PHP_FUNC_time()
{
    return 'num:'. time();
}

function _PHP_FUNC_date($obj) // the basic PHP date call, use "M d H:i:s " for log files.
{
	return 'str:' . date(_LISP_get_string($obj, 1, 1));
}

function _PHP_FUNC_setcookie($obj)
{
    $s = sizeof($obj);
    $name       =    _LISP_get_string($obj, 1, 1);
    if($s < 3) return (setcookie($name))?'sym:t':'sym:nil';
    $value      =    _LISP_get_string($obj, 2, 1);
    if($s < 4) return (setcookie($name, $value))?'sym:t':'sym:nil';
    $expires    =    _LISP_get_number($obj, 3, 1);
    if($s < 5) return (setcookie($name, $value, $expires))?'sym:t':'sym:nil';
    $path       =    _LISP_get_string($obj, 4, 1);
    if($s < 6) return (setcookie($name, $value, $expires, $path))?'sym:t':'sym:nil';
    $domain     =    _LISP_get_string($obj, 5, 1);
    if($s < 7) return (setcookie($name, $value, $expires, $path, $domain))?'sym:t':'sym:nil';
    
    report_error("setcookie expected 1 to 5 args.");
}

function _PHP_FUNC_getcookie($obj)
{
    $name = _LISP_get_string($obj, 1, 1);
    return "str:" . $_COOKIE[$name];
}

function _PHP_FUNC_getcookies($obj)
{ 
	foreach($_COOKIE as $key => $value)
	{
		$ret[] = array('sym:'.$key, 'str:'.$value);
	}
	return $ret; 
}


function _PHP_FUNC_html($obj)
{
	
	$ret = "<!DOCTYPE html>
<!--[if lt IE 7 ]><html class=\"ie ie6\" lang=\"en\"> <![endif]-->
<!--[if IE 7 ]><html class=\"ie ie7\" lang=\"en\"> <![endif]-->
<!--[if IE 8 ]><html class=\"ie ie8\" lang=\"en\"> <![endif]-->
<!--[if (gte IE 9)|!(IE)]><!--><html lang=\"en\"> <!--<![endif]-->";
    
	for($i=1;$i<sizeof($obj);$i++)
	{
		$block .= (_LISP_princ(_LISP_evalobject($obj[$i])) . "\n");
	}
	$block = indent($block);
	$ret .= "$block\n</html>\n";
	return "str:$ret";
}


function _PHP_FUNC_header($obj)
{
	header( _LISP_get_string($obj, 1, 1));
	return 'sym:t';
}

function _PHP_FUNC_urlencode($obj)
{
	return 'str:' . urlencode(_LISP_get_string($obj, 1, 1));
}

function _PHP_FUNC_urldecode($obj)
{
	return 'str:' . urldecode(_LISP_get_string($obj, 1, 1));
}

function _PHP_FUNC_htmlspecialchars($obj)
{
	return 'str:' . htmlspecialchars(_LISP_get_string($obj, 1, 1));
}

function _PHP_FUNC_htmlentities($obj)
{
	return 'str:' . htmlentities(_LISP_get_string($obj, 1, 1));
}

function _PHP_FUNC_html_entity_decode ($obj)
{
	return 'str:' . html_entity_decode(_LISP_get_string($obj, 1, 1));
}

function last_line_length($s)
{
	$ss = split("\n", $s);
	return strlen($ss[sizeof($ss) -1]); // the length of the last line of text..
}

function _PHP_FUNC_htmlblock($obj)
{
	$name = _LISP_get_string($obj, 1, 1);
	$params = $obj[2];
	$ret = "<$name";
	$have_line_breaks = false;
	
	
	foreach($params as $param)
	{
		$thename[] = $tmp1 = substr($param[0], 4);
		$namelen   = max($namelen, strlen($tmp1));
		$thevalue[]  = $tmp = htmlspecialchars(substr(_LISP_evalobject($param[1]), 4));
		if(strstr($tmp, chr(10)))
			$have_line_breaks = true;
	}
	
	
	if($have_line_breaks)
	{
		$paramindent = strlen($ret);
			
		for($item = 0; $item < sizeof($thename); $item++)
		{
			if($item != 0)
			{	
				$ret .= "\n" . str_repeat(' ', $paramindent);
			}
			
			
			$ret .=  ' ' . str_pad($thename[$item], $namelen + 1) . "= \"";
			if(strstr($thevalue[$item], chr(10)))
			{
				$the_indent = last_line_length($ret);
				$valuelinestmp = split("\n", $thevalue[$item]);
				foreach($valuelinestmp as $valueline)
					if($valueline != ' ')
						$valuelines[] = $valueline;
				$ret .= $valuelines[0] . "\n"; // first line just goes on..
				for($i =1; $i < sizeof($valuelines); $i++)
				{
					if($valuelines[$i] != '')
					{
						$ret .= str_repeat(' ', $the_indent) . $valuelines[$i];
						if($i < sizeof($valuelines) -1)
							$ret .= "\n";
					}
				}
				$ret .= "\"";
			}
			else
				$ret .= "{$thevalue[$item]}\"";
		}
		$ret .= ">";
	}
	else
	{
		for($item = 0; $item < sizeof($thename); $item++)
		{
			$ret .= " {$thename[$item]}=\"{$thevalue[$item]}\"";
		}
		$ret .= ">";
	}
	
	for($i=3;$i<sizeof($obj);$i++)
	{
		$block .=  _LISP_princ(_LISP_evalobject($obj[$i]));
		
		if($i<sizeof($obj)-1)
			$block .= "\n";
	}
	
	//$ret is now "<[tag] [params...]>\n"
	
	$outblock = indent($block);
	
	$ret .= "$outblock\n</$name>\n";
	
	return "str:$ret";
}

function _PHP_FUNC_htmlblock_no_indent($obj)
{
	$name = _LISP_get_string($obj, 1, 1);
	$params = $obj[2];
	$ret = "<$name";
	$have_line_breaks = false;
	
	
	foreach($params as $param)
	{
		$thename[] = $tmp1 = substr($param[0], 4);
		$namelen   = max($namelen, strlen($tmp1));
		$thevalue[]  = $tmp = htmlspecialchars(substr(_LISP_evalobject($param[1]), 4));
		if(strstr($tmp, chr(10)))
			$have_line_breaks = true;
	}
	
	
	if($have_line_breaks)
	{
		$paramindent = strlen($ret);
			
		for($item = 0; $item < sizeof($thename); $item++)
		{
			if($item != 0)
			{	
				$ret .= "\n" . str_repeat(' ', $paramindent);
			}
			
			
			$ret .=  ' ' . str_pad($thename[$item], $namelen + 1) . "= \"";
			if(strstr($thevalue[$item], chr(10)))
			{
				$the_indent = last_line_length($ret);
				$valuelinestmp = split("\n", $thevalue[$item]);
				foreach($valuelinestmp as $valueline)
					if($valueline != ' ')
						$valuelines[] = $valueline;
				$ret .= $valuelines[0] . "\n"; // first line just goes on..
				for($i =1; $i < sizeof($valuelines); $i++)
				{
					if($valuelines[$i] != '')
					{
						$ret .= str_repeat(' ', $the_indent) . $valuelines[$i];
						if($i < sizeof($valuelines) -1)
							$ret .= "\n";
					}
				}
				$ret .= "\"";
			}
			else
				$ret .= "{$thevalue[$item]}\"";
		}
		$ret .= ">";
	}
	else
	{
		for($item = 0; $item < sizeof($thename); $item++)
		{
			$ret .= " {$thename[$item]}=\"{$thevalue[$item]}\"";
		}
		$ret .= ">";
	}
	
	for($i=3;$i<sizeof($obj);$i++)
	{
		$block .=  _LISP_princ(_LISP_evalobject($obj[$i]));
		
		//if($i<sizeof($obj)-1) $block .= "\n";
	}
	
	$block = preg_replace("/\\\n/", '', $block);
	
	//$ret is now "<[tag] [params...]>\n"
	
	//$outblock = indent($block); //disable because no indent
	
	$ret .= "$block</$name>\n";
	
	return "str:$ret";
}



function _PHP_FUNC_htmltag($obj) //(htmlag 'name ((name val)) ) -> <name name="value"..>
{
    $name = _LISP_get_string($obj, 1, 1);
	$params = $obj[2];
	$ret = "<$name";
	$have_line_breaks = false;
	
	
	foreach($params as $param)
	{
		$thename[] = $tmp1 = substr($param[0], 4);
		$namelen   = max($namelen, strlen($tmp1));
		$thevalue[]  = $tmp = htmlspecialchars(substr(_LISP_evalobject($param[1]), 4));
		if(strstr($tmp, chr(10)))
			$have_line_breaks = true;
	}
	
	
	if($have_line_breaks)
	{
		$paramindent = strlen($ret);
			
		for($item = 0; $item < sizeof($thename); $item++)
		{
			if($item != 0)
			{	
				$ret .= "\n" . str_repeat(' ', $paramindent);
			}
			
			
			$ret .=  ' ' . str_pad($thename[$item], $namelen + 1) . "= \"";
			if(strstr($thevalue[$item], chr(10)))
			{
				$the_indent = last_line_length($ret);
				$valuelinestmp = split("\n", $thevalue[$item]);
				foreach($valuelinestmp as $valueline)
					if($valueline != ' ')
						$valuelines[] = $valueline;
				$ret .= $valuelines[0] . "\n"; // first line just goes on..
				for($i =1; $i < sizeof($valuelines); $i++)
				{
					if($valuelines[$i] != '')
					{
						$ret .= str_repeat(' ', $the_indent) . $valuelines[$i];
						if($i < sizeof($valuelines) -1)
							$ret .= "\n";
					}
				}
				$ret .= "\"";
			}
			else
				$ret .= "{$thevalue[$item]}\"";
		}
		$ret .= "/>";
	}
	else
	{
		for($item = 0; $item < sizeof($thename); $item++)
		{
			$ret .= " {$thename[$item]}=\"{$thevalue[$item]}\"";
		}
		$ret .= "/>";
	}
	
	return "str:$ret\n";
}

function _PHP_FUNC_get_CGI_arg($obj) // (get_CGI_arg <name>) - get arg
{
	$argname = _LISP_get_string($obj, 1, 1);
	$arg = $_POST["$argname"];
	if($arg == "") $arg = $_GET["$argname"];
	return 'str:' . stripslashes($arg);
}

function _PHP_FUNC_CGI_request($obj)
{
	//return $_REQUEST;
	
	foreach($_REQUEST as $name=>$value)
		$ret[] = array('str:'.$name, 'str:'. $value);
	return $ret;
}

function count_leading_spaces($s)
{
	$i=0;
	$ret = 0;
	while($s[$i++] == ' ')
		$ret++;
		
	echo "string=[$s], leadingspaces=$ret<br>\n";
	return $ret;
}

function indent($block)
{
	$lines = split("\n", $block);
	$nlines = sizeof($lines);
	$gotleadingspacecount = false;
	for($i=0; $i<$nlines; $i++)
	{
		$line = $lines[$i];
		
		if(trim($line) == "")
			continue;
		
			
		@$outblock .= "\n" . '  ' . $line;
		
	}
	return $outblock;
}

function get_CGI_arg($tag)
{
	if(($ret = $_POST[$tag]) == '')
		$ret = $_GET[$tag];
	return stripslashes($ret);
}

function _PHP_FUNC_mail($obj) // (mail 'to 'subject 'message 'from)
{
	$to 		= _LISP_get_string($obj, 1, 1);
	$subject	= _LISP_get_string($obj, 2, 1);
	$message    = _LISP_get_string($obj, 3, 1);
	$from       = _LISP_get_string($obj, 4, 1);
	
	if(mail($to, $subject, $message, "From: $from"))
		return 'sym:t';
	else
		return 'sym:nil';
}

function htmlmail($from, $to, $subject, $plaintext, $html)
{	
	
	$headers="From: $from\n"; 
	$headers .= "Cc: Charlie Dancey <charliedancey@gmail.com>\n";
	
	//specifyMIMEversion1.0 
	
	$headers.="MIME-Version: 1.0\n";
	
	//uniqueboundary 
	$boundary=uniqid("HTMLMAIL"); 
	
	$headers.="Content-Type: multipart/alternative; 
	    boundary=$boundary\n\n"; 
	

	$headers.="This is a MIME encoded message.\n\n"; 
	
	//plaintextversionofmessage 
	$headers.="--$boundary\n". 
	"Content-Type: text/plain;charset=UTF-8\n\n";
	$headers.= strip_tags(str_replace("<br>", "\n", $plaintext)); 
	
	//HTML versionofmessage 
	$headers.="\n\n--$boundary\n". 
	"Content-Type: text/html;charset=UTF-8\n\n";
	$headers .= wordwrap($html, 70);
	
	$headers.= "\n\n--$boundary--\n";
	
	//send message 
	return mail($to,$subject,"",$headers); 
}

function _PHP_FUNC_htmlmail($obj)
{
	$from 		= _LISP_get_string($obj, 1, 1);
	$to			= _LISP_get_string($obj, 2, 1);
	$subject    = _LISP_get_string($obj, 3, 1);
	$plaintext  = _LISP_get_string($obj, 4, 1);
	$html       = _LISP_get_string($obj, 5, 1);
	htmlmail( $from, $to, $subject, $plaintext, $html );
	return 'sym:t';
}




/*
	SQL
	---
*/

function _PHP_FUNC_mysql_connect($obj) // (connect 'server 'user 'pass)
{
	global $LISP_db_link, $_LISP_VARS;
	$server = _LISP_get_string($obj, 1, 1);
	$user 	= _LISP_get_string($obj, 2, 1);
	$pass   = _LISP_get_string($obj, 3, 1);
	//echo "[$server] [$user] [$pass]<br>\n";
	return ($LISP_db_link = mysql_connect($server , $user, $pass))? 'sym:t' : 'sym:nil';
}

function _PHP_FUNC_mysql_connect2_beta($obj) // (connect 'server 'user 'pass)
{
	global $LISP_db_link, $_LISP_VARS;
	return mysql_connect(	_LISP_get_string($obj, 1, 1),
	                     	_LISP_get_string($obj, 2, 1), 
	                     	_LISP_get_string($obj, 3, 1));
}
	
function _PHP_FUNC_usedb($obj) // (usedb 'name) 
{
	global $_LISP_VARS;
	$thedbname = _LISP_get_string($obj, 1, 1);
	$_LISP_VARS['_DATABASE_NAME'] = "str:$thedbname";
	mysql_select_db($thedbname) or report_error("usedb, could not select database $thedbname");
	return "sym:t";
}

function _PHP_FUNC_databasename($obj) // (databasname 'name) 
{
	global $_LISP_VARS;
	return $_LISP_VARS['_DATABASE_NAME'];
}

function _PHP_FUNC_uniqid($obj) // (uniqid) 
{
	return uniqid('str:');
}

function _database_error($message, $query)
{
	global $current_code;
	$extra = _LISP_printstring($current_code, true);
	throw new Exception( serialize(array('sym:error', "str:MySQL", "str:$message",  "str:$extra")));
	// how this was
	//echo("\n<br><table style='font: 13px arial'><tr><td valign=top><img src='images/bugicon.png' align='absmiddle'></td><td><b><big>This wasn't meant to happen!</big></b></td></tr><tr><td valign=top></td><td><b>SQL Error: $message \n</b><br><br><font color='#333333'><code>$query</code><br><br></font></td></tr><tr><td valign=top></td><td><i>We apologise for this temporary loss of service which has almost certainly been caused by the sort of typing error that would be totally insignificant but for the fact that this is <b>code</b>.<br><br>Why not take this opportunity to have a break?</i><br><br><small>Charlie Dancey - 07919 653467 - please have your credit card details ready.</small></td></tr></table>\n");
	//die("");
}

function _PHP_FUNC_select($obj) // (select 'query) // returns list of results
{
	global $LISP_db_link;
	$query  = "select ";
	for($i=1; $i<sizeof($obj); $i++)
	$query  .= _LISP_get_string($obj, $i, 1);
	
	$n=mysql_num_rows($r=mysql_query($query));
	
	if($s=mysql_error())
		_database_error($s, $query);

	$ret = array();
	while($n--)
	{
		$row = mysql_fetch_array($r);
		$thisrow = array();
		foreach($row as $key=>$value)
		{
			if(!is_int($key))
			{
				$thisrow[] = array('sym:'.$key, 'str:'.$value);
			}
		}	
		$ret[] = $thisrow;
	}
	return $ret;
}

function _PHP_FUNC_describe($obj) // (describe  'table) // returns list of results
{
	global $LISP_db_link;
	$query  = "describe " . _LISP_get_string($obj, 1, 1);
	
	$n=mysql_num_rows($r=mysql_query($query));
	
	if($s=mysql_error())
		_database_error($s, $query);

	$ret = array();
	while($n--)
	{
		$row = mysql_fetch_array($r);
		$thisrow = array();
		foreach($row as $key=>$value)
		{
			if(!is_int($key))
			{
				$thisrow[] = array('sym:'.$key, 'str:'.$value);
			}
		}	
		$ret[] = $thisrow;
	}
	return $ret;
}

function _PHP_FUNC_mysql_query($obj) // (describe  'table) // returns list of results
{
	global $LISP_db_link;
	for($i=1; $i<sizeof($obj); $i++)
	$query  .= _LISP_get_string($obj, $i, 1);
	
	$n=mysql_num_rows($r=mysql_query($query));
	
	if($s=mysql_error())
		_database_error($s, $query);

	$ret = array();
	while($n--)
	{
		$row = mysql_fetch_array($r);
		$thisrow = array();
		foreach($row as $key=>$value)
		{
			if(!is_int($key))
			{
				$thisrow[] = array('sym:'.$key, 'str:'.$value);
			}
		}	
		$ret[] = $thisrow;
	}
	return $ret;
}

function _PHP_FUNC_mysql_update($obj) 
{
	global $LISP_db_link;
	$query = "update ";
	
	for($i=1; $i<sizeof($obj); $i++)
	$query  .= _LISP_get_string($obj, $i, 1);
	
	mysql_query($query);
	
	if($s=mysql_error())
		_database_error($s, $query);
		
	return 'sym:t';

}

function _PHP_FUNC_sql_decode_blob($obj)
{
	return base64_decode(stripslashes($_LISP_get_string($obj, 1, 1)));
}

function _PHP_FUNC_sql_encode_file($obj) // read a file and encode it for insertion.
{	
	$filename = _LISP_get_string($obj, 1, 1);
	if($filename != "")
	{
		if(file_exists($filename))
		{
			$fsize = filesize($filename);
			$blob = addslashes(base64_encode(fread($fp = fopen($filename, "r"), $fsize)));
			fclose($fp);
			return 'str:' . $blob;
		}
		else
			return "File not found ($filename)";
	}
	else
	return "Empty filename";
}


/* 
	CORE
	----
*/

function _PHP_FUNC_fun($obj) // (fun [name] [args] [body...]) Note that what this *really* does is (setq [name] (cons 'function (cdr (cdr $obj)))) no checks
{
	global $_LISP_VARS;
	$sym = _LISP_get_varname($obj, 1, 0);	// check [name] is valid
	array_shift($obj);						// drop head ([name] [args] [body]
	$obj[0] = 'sym:function';				// = (function [args] [body...]
	$_LISP_VARS[$sym] = $obj;
	return $obj;
}

function _PHP_FUNC_dumpsym($obj)
{
	global $_LISP_VARS;
	$ret = array();
	if(sizeof($_LISP_VARS))
	foreach ($_LISP_VARS as $name=>$value)
	{
		$ret[] = array('sym:'. $name, $value);
	}
	return $ret;
}

function _PHP_FUNC_list_internal_functions($obj) // return a list of defined functions
{
	$ret = array();
	$allfunctions  = get_defined_functions();
	$userfunctions = $allfunctions['user'];
    sort($userfunctions);
	foreach($userfunctions as $function)
	{
		if(substr($function,0,10) == "_php_func_")
		{
		 	$ret[] = 'str:' . substr($function,10);
		}
	}
	return $ret;
}

function _PHP_FUNC_list_user_functions($obj)
{	
	global $_LISP_VARS;
	$ret = array();
	foreach($_LISP_VARS as $name=>$value)
	{
		if(is_array($value) && $value[0] == "sym:function")
			$ret[] = 'str:'.$name;
	}
	return $ret;
}

function _PHP_FUNC_dumpsymtypes($obj)
{
	global $_LISP_VARS;
	$ret = array();
	if(sizeof($_LISP_VARS))
	foreach ($_LISP_VARS as $name=>$value)
	{
		$ret[] = array('sym:'. $name, 'str:'.$value);
	}
	return $ret;
}

function _PHP_FUNC_eval($obj)
{
	$ret = 'sym:nil';
	for($i=1; $i<sizeof($obj); $i++)
	{
		$arg = _LISP_evalobject($obj[$i]);
		$ret = _LISP_evalobject($arg);

	}
	return $ret;
}

function _PHP_FUNC_quote($obj)
{
	return $obj[1];
}

function _PHP_FUNC_comma($obj)
{
	return _LISP_evalobject($obj[1]);
}

function _PHP_FUNC_backquote($obj) // operates on first arg only
{
	return backquote_aux($obj[1]);
}

function backquote_aux($thing)
{
	if((!is_array($thing) || sizeof($thing) == 0) || $thing == 'sym:nil')
	{
		return $thing;
	}
	else // it is a list
	{
		if($thing[0] == 'sym:comma')
		{
			return _LISP_evalobject($thing[1]);
		}
		else
		{
			foreach($thing as $item)
			{
				$ret[] = backquote_aux($item);
			}
			return $ret;
		}
	}
}

function _PHP_FUNC_exec($obj)
/* calls the PHP exec function, 
   input is a single string which is the command line
   output is a list, first element is the error code, the following elements are each line of output as a string..
   
   Example: (exec "ls -l")
   
   Note that commands happen in the THP directory, so CD commads have no effect unless commands are chained as in:
   (exec "cd CM2; ls -l")
   
   Added October 2012. Charlie Dancey.
 */ 
{
    $command = _LISP_get_string($obj, 1, 1);
    $rawoutput  = array();
    $ret = array();
    exec($command, $rawoutput, $worked);
    $ret[] = 'num:' . $worked;
    for($i = 0; $i < sizeof($rawoutput); $i++)
    {
        $ret[] = 'str:' . $rawoutput[$i];
    }
    return $ret;    
    
}

function _PHP_FUNC_local($obj) // (local (x y) <expr>...) or (local ((x 2)(y 4)...) <expr>...
{
	global $_LISP_VARS, $_LISP_VAR_TOP;
	$ret = 'sym:nil';
	$args = $obj[1];
	if(!is_array($args)) exit("local expected a list of local variables: got $args");
	$holdvarstacktop = $_LISP_VAR_TOP; // hold stack
	foreach($args as $arg)
	{
		if(is_array($arg)) // look for (name value)
		{
			$var = $arg[0];
			if(is_array($var) || substr($var, 0, 4) != 'sym:' || $var == 'sym:nil' || $var == 'sym:t')
				report_error('local bad variable name ');
			$varname = $varname = substr($var, 4);
			pushVar($varname);
			$_LISP_VARS[$varname] = _LISP_evalobject($arg[1]);   // assign new value
		}
		else
		{
			$var = $arg;
			if(is_array($var) || substr($var, 0, 4) != 'sym:' || $var == 'sym:nil' || $var == 'sym:t')
				report_error('local bad variable name ');
			$varname = $varname = substr($var, 4);
			$stack[$varname] = $_LISP_VARS[$varname];     // PUSH old value
			$_LISP_VARS[$varname] = 'sym:nil';            // set to nil
		}	
	}
	
	// evaluate stuff
	for($i=2; $i< sizeof($obj); $i++)
		$ret = _LISP_evalobject($obj[$i]);
		
	// POP local variables
	popVars($holdvarstacktop);
	
	return $ret;
}

function _PHP_FUNC_setq($obj)
{
	global $_LISP_VARS;
	return ($_LISP_VARS[_LISP_get_varname($obj, 1, 0)] = _LISP_evalobject($obj[2]));
}

function _PHP_FUNC_set($obj)
{
	global $_LISP_VARS;
	return ($_LISP_VARS[_LISP_get_varname($obj, 1, 1)] = _LISP_evalobject($obj[2]));
}

function _PHP_FUNC_parse($obj)
{
	return _LISP_parse(_LISP_get_string($obj, 1, 1));
}

function _PHP_FUNC_servername($obj)
{
	return 'str:' . $_SERVER['SERVER_NAME'];
}

 
/* 
	ITERATORS AND BLOCKS 
	--------------------
*/
	
$LISP_BREAK = 0;

function _PHP_FUNC_catch($obj)
{
	global $_LISP_VAR_TOP, $_LISP_THIS, $_LISP_SUPER;
	$holdvarstacktop 	= $_LISP_VAR_TOP; // Mark position in varstack in case we get a throw
	$holdlispthis 		= $_LISP_THIS;
	$holdlispsuper      = $_LISP_SUPER;
	
	try
	{
		for($i = 1; $i< sizeof($obj); $i++)
		{
			$ret = _LISP_evalobject($obj[$i]);
		}
		return $ret;
	}
	catch( Exception $e)
	{
		// Pop variable stack so we return to original context.
		popVars( $holdvarstacktop );
		
		// restore object context
		$_LISP_THIS 	= $holdlispthis;
		$_LISP_SUPER 	= $holdlispsuper;
		$caught			= unserialize($e->getMessage());
		
		// if this is an error we'll throw it again...
		if($caught[0] == 'sym:error')
		    throw($e);
		
		return $caught;
	}
}

// this one catches EVERYTHING...

function _PHP_FUNC_catcherror($obj)
{
	global $_LISP_VAR_TOP, $_LISP_THIS, $_LISP_SUPER;
	$holdvarstacktop 	= $_LISP_VAR_TOP; // Mark position in varstack in case we get a throw
	$holdlispthis 		= $_LISP_THIS;
	$holdlispsuper      = $_LISP_SUPER;
	
	try
	{
		for($i = 1; $i< sizeof($obj); $i++)
		{
			$ret = _LISP_evalobject($obj[$i]);
		}
		return $ret;
	}
	catch( Exception $e)
	{
		// Pop variable stack so we return to original context.
		popVars( $holdvarstacktop );
		
		// restore object context
		$_LISP_THIS 	= $holdlispthis;
		$_LISP_SUPER 	= $holdlispsuper;
		$caught			= unserialize($e->getMessage());
		
		return $caught;
	}
}

/* Throw is a clever little beast, it uses PHP5's Exception Message field to hold a serialised
   version of whetever we are throwing.
   I have not tested what happens if we throw really BIG stuff (there may a limit on the size of message)
   but this allows us to throw *any* LISP object back in a container actually designed for strings.
   
   (throw '(error "not a good thing")) - this will NOT be caught by catch, but wll be caught by (catcherror ...)
 */
function _PHP_FUNC_throw($obj)
{
	$throwmessage = serialize(_LISP_evalobject($obj[1]));
	throw new Exception($throwmessage);
}

function _PHP_FUNC_break($obj)
{
	global $LISP_BREAK, $LISP_BREAK_VALUE;
	$LISP_BREAK = 1;
	return $LISP_BREAK_VALUE = _LISP_evalobject($obj[1]);
}

function _PHP_FUNC_do($obj)  
{
	/* 
		Useful one this, it simply evaluates its arguments.
		Handy for combining several chunks of code into a
		single unit, often combined with (if ...) as in:
		
		(if (testistrue)
		    (do
		    	(this)
		    	(that)
		    	(theother))
		    (do
		    	(something else)
		    	(and another thing)))
		
	*/
	
	$ret = 'sym:nil';
	for($i=1; $i<sizeof($obj); $i++)
		$ret = _LISP_evalobject($obj[$i]);
	return $ret;	
}

function _PHP_FUNC_while($obj)//(while [test] [action]... )
{
	while(_LISP_evalobject($obj[1]) != 'sym:nil')
	{
		for($i=2; $i<sizeof($obj); $i++)
			$ret=_LISP_evalobject($obj[$i]);
	}	
	return $ret;	
}

function _PHP_FUNC_foreach($obj)
{
	/*
	   (foreach lyst item
	            (do_something_with item))
	*/
	
	global $LISP_BREAK, $LISP_BREAK_VALUE, $_LISP_VARS, $_LISP_VAR_TOP;
	
	$ret = 'sym:nil';
	$list = _LISP_evalobject($obj[1]);
	if($list == 'sym:nil') $list=array();
	
	if(!is_array($list)) report_error("(foreach ...) expected a list [$list].");
	
	$holdvarstacktop = $_LISP_VAR_TOP;
	$sym = _LISP_get_symbol($obj, 2, 0); 
	
	
	$savebreak      = $LISP_BREAK;  			// push any existing BREAK...
	$savebreakvalue = $LISP_BREAK_VALUE;
	$LISP_BREAK = 0;       						// ... and zero it.
	
	pushVar($sym);             				// push old value of our symbol..
	foreach($list as $item)
	{
		$_LISP_VARS[$sym] = $item;							// bind new value
		for($i=3; $i<sizeof($obj); $i++)
		{
			$ret = _LISP_evalobject($obj[$i]);    				// evaluate expressions
			if($LISP_BREAK)
			{
			    $ret = $LISP_BREAK_VALUE;
			    break 2;
			}
		}
	}
	popVars($holdvarstacktop);
	$LISP_BREAK 		= $savebreak;  				// pop old BREAK
	$LISP_BREAK_VALUE 	= $savebreakvalue;
	
return $ret;	
}

/* 
	LISTS
	-----
*/


function _PHP_FUNC_chunk($obj) // needs a test and a fix
{
	$inputlist = _LISP_get_list($obj, 1, 1);
	$chunksize = _LISP_get_number($obj, 2, 1);
	$ret = array();
	$i = -1;
	$j = 0;
	foreach($inputlist as $item)
	{
	    if(!($j % $chunksize))
	    {
	    	$ret[++$i] = array();
			$j = 0;
	    }
		$ret[$i][$j++] = $item;
	}
	
	return $ret;
}



function _PHP_FUNC_car($obj)
{
	$thing = _LISP_get_list($obj, 1, 1);
	if(sizeof($thing) == 0) 
		return 'sym:nil';
	return $thing[0];
}

function _PHP_FUNC_last($obj)
{
	$list = _LISP_get_list($obj, 1, 1);
	if(sizeof($list) == 0) return 'sym:nil';
	return $list[sizeof($list) - 1];
}

function _PHP_FUNC_cdr($obj)
{
	$thing = _LISP_get_list($obj, 1, 1);
	array_shift($thing);
	return $thing;
}

function _PHP_FUNC_cons($obj)
{
	$car = _LISP_evalobject($obj[1]);
	$cdr = _LISP_get_list($obj, 2, 1);
	if($cdr == 'sym:nil')
		return array($car);
	array_unshift($cdr, $car);
	return $cdr;
}

function _PHP_FUNC_remove($obj) // (remove lyst thing) remove all occurences of 'thing from 'lyst
{
	$lyst 	= _LISP_get_list($obj, 1, 1); 
	$thing  = _LISP_evalobject($obj[2]); // could be anything
	for($i=0; $i<sizeof($lyst); $i++)
	{
		if($lyst[$i] == $thing)
			unset($lyst[$i]);
	}
	// now we have an array with buggered keys...better fix them
	return array_values($lyst);
}
		

function _PHP_FUNC_append($obj)
{
	$tail = _LISP_evalobject($obj[2]);
	$lyst = _LISP_get_list($obj, 1, 1);
	if($lyst == 'sym:nil')
		return array($tail);
	$lyst[]=$tail;
	return $lyst;
}


function _PHP_FUNC_list($obj)
{
	$ret = array();
	for($i=1; $i<sizeof($obj); $i++)
	{
		$ret[] = _LISP_evalobject($obj[$i]);
	}
	return $ret;
}

function _PHP_FUNC_kwote($obj)
{
	$item = _LISP_evalobject($obj[1]);
	
	return array('sym:quote', $item);
}

function _PHP_FUNC_nth($obj)
{
	$item = _LISP_evalobject($obj[1]);
	$index = _LISP_get_number($obj, 2, 1);
	if(is_array($item))
	{
		if($index < 0 || $index > sizeof($item)-1)
		  return 'sym:nil';
		else
		  return ($nth = $item[$index]);
	}
	if($item[0] == 's')
	{
		
		if($index < 0 || $index > strlen($item)-1)
		  return 'sym:nil';
		else
		  return 'str:' . $item[$index+4];
	}
	if($item == 'sym:nil')
	{
		return $item;
	}
	else
	{
		report_error("(nth ...) - expected a list or a string");
	}
}

function _PHP_FUNC_with($obj) 
{
	/*
		(with ((key1 value1)(key2 value2)...) <expr> ....) - stack key's then bind to properties, evaluate, and pop stack.
		
		Neat cheat here, we simply evaluate the first arg, 
		then pass the whole thing onto _PHP_FUNC_local()!
		
		Oh yes we can.
	*/
	
	$obj[1] = _LISP_evalobject($obj[1]);
	return _PHP_FUNC_local($obj);
}

function _PHP_FUNC_explode($obj)
{
	$string = _LISP_get_string($obj, 1, 1);
	if($string == 'nil') return 'sym:nil';
	$ret    = array();
	$len = strlen($string);

	for($i=0; $i<$len; $i++)
	{
		$ret[] = 'str:' . $string[$i];
	}
	
	return $ret;
}

function _PHP_FUNC_implode($obj) //  CD December 2010 (implode lyst) or (implode lyst glue)
{
	$lyst = _LISP_evalobject($obj[1]); 		// should be an array
	$glue = _LISP_evalobject($obj[2]); 		// if missing will be nil
	if($lyst == 'sym:nil') $lyst=array();	// special case if we have 'nil'
	if(!is_array($lyst)) report_error('(implode lyst [glue]) expected a list ');
	foreach($lyst as $item)
	{
		$tmp[] = _LISP_check_string($item); 
	}
	if($glue == 'sym:nil') 
		return 'str:' . implode($tmp);
	else
		if($tmp)
			return 'str:' . implode(_LISP_check_string($glue), $tmp);
		else
			return 'str:';
}

function _PHP_FUNC_reverse($obj)
{
	$thing = _LISP_evalobject($obj[1]);
	if($thing == 'sym:nil') return array();
	if(is_array($thing))
	{
		return array_reverse($thing); // we don't care about keys...
	}
	
	// we can also reverse strings and symbols
	if(substr($thing, 0, 1) == 's')
		return substr($thing, 0, 4) . strrev(substr($thing, 4));
	
	report_error('(reverse ...) expected a list or a string '); // could extend to reversing numbers but why??
		
}

function _PHP_FUNC_member($obj)
{
	$thing 				= _LISP_evalobject($obj[1]);
	$should_be_a_list 	= _LISP_evalobject($obj[2]);
	if($should_be_a_list == 'sym:nil') return 'sym:nil';
	if(!is_array($should_be_a_list)) report_error(' (member ...) expected second argument to be a list ');
	//print_r ($thing);
	//echo "<br>\n";
	//print_r ($should_be_a_list);
	//echo "<br>\n";
	return in_array($thing, $should_be_a_list)?'sym:t':'sym:nil';
}

function _PHP_FUNC_sort($obj)
{
	
	$thelist = _LISP_get_list($obj, 1, 1);
	$theorder  = _LISP_get_bool($obj, 2, 1); // t = desc
	$theway  =   _LISP_get_bool($obj, 3, 1); // t = numeric nil=string
	if(!is_array($thelist))
		if($thelist == 'sym:nil')
			return 'sym:nil';
		else
			report_error('(sort ...) expected a list ');
	foreach($thelist as $item)
	{
		if(is_array($item)) report_error('sort does not like nested lists ');
		$type[]  = substr($item, 0, 4);
		$value[] = substr($item, 4);
	}
	array_multisort($value, ($theorder)?SORT_DESC:SORT_ASC , ($theway)?SORT_NUMERIC:SORT_STRING, $type);
	for($i=0; $i< sizeof($value); $i++)
		$ret[] = $type[$i] . $value[$i];
	return $ret;
}

function _PHP_FUNC_concat($obj)
{
	$ret = array();
	for($i=1; $i< sizeof($obj); $i++)
		$ret = array_merge($ret , _LISP_get_list($obj, $i, 1));
	return $ret;
}

function _PHP_FUNC_replace($obj) // (replace <list> <index> <newvalue>)
{
	$list  = _LISP_get_list($obj, 1, 1);
	$index = _LISP_get_number($obj, 2, 1);
	$value = _LISP_evalobject($obj[3]);
	
	if($index < 1) report_error("replace, bad index $index");
	if($index > sizeof($list)) report_error("replace, bad index $index (too high)");
	$list[$index-1] = $value;
	return $list;
}

function _PHP_FUNC_keyexists($obj) // (keyexists plist key) not to be confused with item below
{
	$plist = _LISP_get_list($obj, 1, 1); // ((k1 val)(k2 val)...)
	$key   = _LISP_evalobject($obj[2]);  // k2 - could be anything
	for($i=0; $i<sizeof($plist); $i++)
	{
		if($plist[$i][0] == $key) return 'sym:t'; // should check for list really...
	}
	return 'sym:nil';
}

function _PHP_FUNC_keysort($obj) // (keysort keys values) sorts values by keys ascending FIXED Dec 2011
{
	$keylist = _LISP_get_list($obj, 2, 1);
	$vallist = _LISP_get_list($obj, 1, 1);
	
	for($i=0; $i<sizeof($keylist); $i++)
		$keys[] = _LISP_get_number($keylist, $i, 0);
		
	//print_r($vallist);
	
	if(array_multisort($keys, SORT_ASC, $vallist))
		return $vallist;
	else
	{
		print_r($list1);
		echo "<br>\n";
		print_r($newlist);
		report_error('keysort, non-matching lists');
	}
}

function _PHP_FUNC_intersect($obj)
{
	return array_intersect(_LISP_get_list($obj, 1, 1), _LISP_get_list($obj, 2, 1));
}

function _PHP_FUNC_exclude($obj) //all of 1 that is not in 2
{
	return array_diff(_LISP_get_list($obj, 1, 1), _LISP_get_list($obj, 2, 1));
}

function _PHP_FUNC_mapcar($obj)
{
	$func   = _LISP_evalobject($obj[1]);
	//$func 	= 'sym:' . _LISP_get_string($obj, 1, 1);
	
	$list 	= _LISP_get_list($obj, 2, 1);
	
	
	$len 	= sizeof($list);
	$ret 	= array();
	for($i=0; $i<$len; $i++)
	{
	
		$thisfunc = array($func, $list[$i]);
		
	
		
		$ret[$i] = _LISP_evalobject($thisfunc); // need to use thisfunc so we can pass by reference
	}	
	return $ret;
}

function _PHP_FUNC_length($obj) // (length list)
{
	return 'num:' . sizeof(_LISP_get_list($obj, 1, 1));
}

function _PHP_FUNC_apply($obj) // (apply 'fun 'arglist)
{
	$func = 'sym:'. _LISP_get_string($obj, 1, 1);
	$list = _LISP_get_list($obj, 2, 1);
	$expr = array();
	$expr[0] = $func;
	for($i=0; $i<sizeof($list); $i++)
	  $expr[] = $list[$i];
	return _LISP_evalobject($expr);
}

/* LOGIC FUNCTIONS */

function _PHP_FUNC_equal($obj)
{
	return _LISP_equal(_LISP_evalobject($obj[1]), _LISP_evalobject($obj[2]));
	
}

function _LISP_equal($a, $b) // ###needs some work since nil == ()
{
	if(is_array($a))
	{
		if($a == $b) return 'sym:t'; else return 'sym:nil';
	}
	elseif(substr($a, 4) == substr($b, 4))
		return 'sym:t'; 
	else 
		return 'sym:nil';
}

function _PHP_FUNC_lt($obj)
{
	$a = _LISP_get_number($obj, 1, 1);
	$b = _LISP_get_number($obj, 2, 1);
	if($a < $b) return 'sym:t'; else return 'sym:nil';
}

function _PHP_FUNC_leq($obj)
{
	$a = _LISP_get_number($obj, 1, 1);
	$b = _LISP_get_number($obj, 2, 1);
	if($a <= $b) return 'sym:t'; else return 'sym:nil';
}

function _PHP_FUNC_gt($obj)
{
	$a = _LISP_get_number($obj, 1, 1);
	$b = _LISP_get_number($obj, 2, 1);
	if($a > $b) return 'sym:t'; else return 'sym:nil';
}

function _PHP_FUNC_strgt($obj)
{
	$a = _LISP_get_string($obj, 1, 1);
	$b = _LISP_get_string($obj, 2, 1);
	if($a > $b) return 'sym:t'; else return 'sym:nil';
}

function _PHP_FUNC_geq($obj)
{
	$a = _LISP_get_number($obj, 1, 1);
	$b = _LISP_get_number($obj, 2, 1);
	if($a >= $b) return 'sym:t'; else return 'sym:nil';
}

function _PHP_FUNC_if($obj)
{
	$test = _LISP_evalobject($obj[1]);
	if($test == 'sym:nil' || (is_array($test) && sizeof($test) == 0))
	{
		return _LISP_evalobject($obj[3]);
	}
	else
	{
		return _LISP_evalobject($obj[2]);
	}
}

function _PHP_FUNC_or($obj)
{
	$ret = 'sym:nil';
	for($i=1; $i<sizeof($obj); $i++)
	{
		$ret = _LISP_evalobject($obj[$i]);
		if($ret != "sym:nil" || (is_array($test) && sizeof($test) ))
				return $ret;
	}			
	return $ret;
}

function _PHP_FUNC_and($obj)
{
	$ret = 'sym:nil';
	for($i=1; $i<sizeof($obj); $i++)
	{
		$ret = _LISP_evalobject($obj[$i]);
		if($ret == "sym:nil" || (is_array($ret) && sizeof($ret) == 0 ))
				return $ret;
	}			
	return $ret;
}

function _PHP_FUNC_not($obj)
{
	return(_LISP_get_bool($obj, 1, 1))? 'sym:nil' : 'sym:t';
}

function _PHP_FUNC_nullp($obj) // alias for not
{
	return _PHP_FUNC_not($obj);
}

function _PHP_FUNC_atomp($obj)
{
	$thing = _LISP_evalobject($obj[1]);
	if((!is_array($thing) || sizeof($thing) == 0) || $thing == 'sym:nil')
		return 'sym:t';
	else
		return 'sym:nil';	
}

function _PHP_FUNC_listp($obj)
{
	$thing = _LISP_evalobject($obj[1]);
	if(is_array($thing) || $thing == 'sym:nil')
		return 'sym:t';
	else
		return 'sym:nil';	
}

function _PHP_FUNC_switch($obj)
{
	$test = _LISP_evalobject($obj[1]);
	$ret = "sym:nil";
	for($i=2; $i<sizeof($obj); $i++)
	{
		if(!is_array($obj[$i])) report_error("switch, expected a list clause");
		
		//echo "SWITCH:" . $test . "=" . _LISP_evalobject($obj[$i][0]) . "?<br>";
		
		if($obj[$i][0] == 'sym:default' || (_LISP_equal(_LISP_evalobject($obj[$i][0]), $test)) == 'sym:t')
		{
		    //echo "MATCH<br>";
			for($j=1; $j<sizeof($obj[$i]); $j++)
			{
				$ret = _LISP_evalobject($obj[$i][$j]);
			}
			return $ret;
		}
	}
	return $ret;
}

function _PHP_FUNC_cond($obj)
{
	global $symnil, $listnil;
	$ret = $symnil;
	for($i=1; $i<sizeof($obj); $i++)
	{
		$clause = $obj[$i];             // something like ((equal x 1)(do something)...)
		if(!is_array($clause))	report_error('cond expected a list clause'); 
		$test = _LISP_evalobject($clause[0]);
		
		if($test != $symnil && $test != $listnil)
		{
			for($j=1; $j<sizeof($clause); $j++)
				$ret = _LISP_evalobject($clause[$j]);		
			break;
		}
	}
	return $ret;
}

/* 
	---------------
	START EXECUTION 
	---------------
 */
 
$load = get_cgi_arg('load');

// TODO - this fails silently (and blankly) if the filename $load is not empty but the file named is not available.

if($load == '') // no file loaded so use command line
{
	$source = urldecode(get_cgi_arg('source'));
	$current_code = "sym:PARSE"; // for debugging, prints out with report_error()

	echo "
<html>
  <head>
    <title>THP (lisp (with a lithp))</title>
    <style>
        body {font: 12px menlo, monaco; color: rgb(102, 193, 197); background: rgb(48, 60, 90);}
        pre  {font-size: 13px}
        textarea {font: 9pt menlo,monaco; border: none; background: rgb(38, 102, 111); color: white; width: 97%; resize:vertical; padding: 5px;}
        hr {height: 3px; background: rgb(38, 102, 111); border: none; width: 97%; align: left; margin: 10px 0px ; }
        form {margin: 0px; padding: 0px;}
    </style>
    <script>
        function go()
        {
         document.getElementById('rawsource').style.color='#000';
         document.forms[0].source.value=encodeURIComponent(document.forms[0].rawsource.value); 
         document.forms[0].submit();   
        }
    </script>
  </head>
  <body>
    <span style='font: 13px verdana;'><b>THP</b> <small>(lisp (with a lithp))</small><br><br></span>
		<form action='thp.php' method='POST'>
		    <input type='hidden' name='source'>
			<textarea name='rawsource' id='rawsource' rows=10 cols=80 autocorrect='off' autocapitalize='off' autocomplete='off' spellcheck='false' onblur='go();'>$source</textarea><br>
			<input type='button' onclick = 'go();' value='OK'>
		</form>
		<hr>\n";
	try
	{
	
		$_LISP_code   = _LISP_parse($source);
	
		foreach($_LISP_code as $expr)
		{
			$result=_LISP_evalobject($expr);
		}
		$out = htmlspecialchars(_LISP_printstring($result, true));
	}
	catch( Exception $e )
	{
		$caught = unserialize($e->getMessage());
		if($caught[0] == 'sym:error')
		{
			$line=sizeof($caught[3])-1;
			foreach($caught[3] as $stackitem)
			{
				$stackdump .= "$line: " . _LISP_printq($stackitem) . "\n";
				$line--;
			}
			$out = _LISP_get_string($caught, 1, 0) . "\n" . trim(_LISP_get_string($caught, 2, 0)) .  "\nEval Stack:\n" . $stackdump . "\n";
			_LISP_log($out);
		}
		else
		{
			$out = "### ERROR Something threw [" . _LISP_printstring(unserialize($e->getMessage())) . "] but there was nothing to catch it"  ;
		}
	}
	echo "\n<hr><pre>$out</pre><br>\n</body>\n</html>\n";
}
else
{
	try
	{
		$_LISP_code = _LISP_parse("(foreach (parse (file_get_contents '$load)) item (eval item))");
		_LISP_evalobject($_LISP_code[0]);
	}
	catch( Exception $e )
	{
		$caught = unserialize($e->getMessage());
		if($caught[0] == 'sym:error')
		{
			$line=sizeof($caught[3])-1;
			foreach($caught[3] as $stackitem)
			{
				$stackdump .= "$line: " . _LISP_printq($stackitem) . "<br>\n";
				$line--;
			}
			$out = _LISP_get_string($caught, 1, 0) . "\n" . trim(_LISP_get_string($caught, 2, 0)) .  "\nEval Stack:\n" . $stackdump . "\n";
			_LISP_log($out);
		}
		else
		{
			$out = "### ERROR Something threw [" . _LISP_printstring(unserialize($e->getMessage())) . "] but there was nothing to catch it"  ;
		}
		echo $out;
	}	
}
?>