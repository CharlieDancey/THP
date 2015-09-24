# THP
A moderately cool LISP interpreter designed, mainly, for delivering web pages.

This is a LISP interpreter written in PHP.
It is an interpreter only, there is no compiler.

It writes very nice HTML, and I use it for projects that would usually be typical PHP/MySQL projects.

The rationale behind THP is that PHP is awfully verbose and tiresome for many of these jobs, and while writing an interpreter
in a language that is already interpreted has obvious performance issues, the benefit is a more rapid and expressive coding 
environment.

THP code development is rapid and simple, though it uses a lot more server processor cycles to get the job done.

THP provides a comfortable level of abstraction from a lot of the bare bones work you have to do in PHP.


Dependencies
------------
Requires PHP version 5


Basic Usage
-----------
1. Place THP in a convenient location on your web server, I'll assume it is at the root for these examples

2. To run THP interactively got to http://yourServer/thp.php then enter your code in the textarea and hit the OK button (or just TAB out of the textarea) to see the result.

Try
	(plus 2 2)
	
or
	(terpri "Hello, World!")
	
3. To run THP from a LISP source file you have created in a text editor, make the file, give it a .thp extension (for good manners). Lets say something like:

		(setq n 0)
		(while (lt (setq n (inc n))) 1000
			   (print n)
			   (print " "))
	       
...save this into the same directory as test.thp and then point you browser at:

http://yourServer/thp.php?load=test.thp

..and you'll see your numbers printed out.



