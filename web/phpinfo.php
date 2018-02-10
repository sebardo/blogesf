<?php


	function normalizePath($path)
{
    $parts = array();// Array to build a new path from the good parts
    $path = str_replace('\\', '/', $path);// Replace backslashes with forwardslashes
    $path = preg_replace('/\/+/', '/', $path);// Combine multiple slashes into a single slash
    $segments = explode('/', $path);// Collect path segments
    $test = '';// Initialize testing variable
    foreach($segments as $segment)
    {
        if($segment != '.')
        {
            $test = array_pop($parts);
            if(is_null($test))
                $parts[] = $segment;
            else if($segment == '..')
            {
                if($test == '..')
                    $parts[] = $test;

                if($test == '..' || $test == '')
                    $parts[] = $segment;
            }
            else
            {
                $parts[] = $test;
                $parts[] = $segment;
            }
        }
    }
    return implode('/', $parts);
}


	echo normalizePath("/var/www/vhosts/englishsummerfun.com/blog.englishsummerfun.com/app/../web/uploads/../../uploads") . "<br/>";

	$uploadDir = normalizePath("/var/www/vhosts/englishsummerfun.com/blog.englishsummerfun.com/app/../web/uploads/../../uploads") . "/tmp/assets/images/main/1781349214/originals/";

	$result = @mkdir($uploadDir, 0777, true);
        if (!$result) {
            $error = error_get_last();
            echo "No se ha podido crear el arbol de directorios: " . $uploadDir . ". ERROR: {$error['message']}";
        }


    phpinfo();