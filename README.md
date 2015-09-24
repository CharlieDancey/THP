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
Place THP in a convenient location on your web server, I'll assume it is at the root for these examples

To run THP interactively got to http://yourServer/thp.php then enter your code in the textarea and hit the OK button (or just TAB out of the textarea) to see the result.

Try
		
		(plus 2 2)
	
or
		
		(terpri "Hello, World!")
	
To run THP from a LISP source file you have created in a text editor, make the file, give it a .thp extension (for good manners). Lets say something like:

		(setq n 0)
		(while (lt (setq n (inc n))) 1000
			   (print n)
			   (print " "))
	       
...save this into the same directory as test.thp and then point you browser at:

http://yourServer/thp.php?load=test.thp

..and you'll see your numbers printed out.

Creating Web pages
------------------

A basic web page script in THP looks like this:

		(print	
			(html
				(htmlblock 'head ()
					(htmlblock 'title () "My first THP page"))
				(htmlblock 'body ()
				    "Hello, World!")))
				    
A more useful web page script in THP, saved as "testform.thp", looks like this:

		
		(comment "Anything in a (comment ...) block is discarded by the parser, and vanishes to nothing when the code is run
		          I mean it's not even NULL, it just vapourises completely like it wasn't even there.
		          Oh, and strings (like this one) can span multiple lines.")
		
		(comment "Read the previoiusly submitted value - (get_CGI_arg ..) can read POST or GET values, or even cookies.")          
		(setq lastEnteredValue (get_CGI_arg 'theValue))
		
		
        (comment "To deliver a web page we get THP to print it out...")		
		(print
			(html 
				(htmlblock 'head ()
					(htmlblock 'title () "My more useful page"))

				(htmlblock 'body ()
				    
				    (comment "Display the data entered last time in a DIV to show that we can. NOTE how string interpolation works here...")
				    (htmlblock 'div () "The last value typed was: |lastEnteredValue|")
				    
				    (comment "We need to set the form's action attribute to thp.php")
					(htmlblock 'form ((action "thp.php"))
					  
					    (comment "We need to tell THP to load our script again...")
					    (htmltag 'input ((type 'hidden)
					                     (name 'load)
					                     (value 'testform.thp)))
					                     
					    (comment "Provide a text input to enter data into and name it theValue so we can read it")                 
						(htmltag 'input ((type 'text)
						                 (name 'theValue)))
						
						(comment "A simple submit button")                 
						(htmltag 'input ((type 'submit)(value 'OK)))))))
					





