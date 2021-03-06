<?php

# Assertions:
#     - Source file exists and is a valid *.pde file
#     - Source file uses only core libraries
#     - Source file does NOT have an *.pde extension
#     - Core libraries are already compiled in build/core

# Where is this included?

function dothat($filename, $cmd)
{
	exec($cmd, $out, $ret); 
	$return_val = false;
	if($ret)
	{
		cleanDir($filename);
		$return_val = true;
	}
	return array("error" => $return_val, "cmd" => $cmd, "output" => $out);
}

function doit($cmd, &$out, &$ret)
{
	 
}


function config_output($output, $filename,  &$lines, &$output_string)
{
	$output_string = "";
	$lines = array();
	foreach($output as $i)
	{
		$fat1 = "build/".$filename.":";
		$fat2 = "build/core/";
		$i = str_replace($fat1, "", $i);
		$i = str_replace($fat2, "", $i);
		
		$i = str_replace("tempfiles/".$filename.":", "", $i)."\n";
		// $i = $i."\n<br />";
		$output_string .= $i;
		$colon = strpos($i, ":");
		$number = intval(substr($i, 0, $colon));
		$j = 0;
		for($j = 0; $j < $colon ; $j++)
		{
			if(!(strpos("1234567890", $i{$j}) === FALSE))
				break;
		}
		if(!($colon === FALSE) && $j < $colon)
		{
			$lines[] = $number;
		}
		
	}
}
function do_compile($filename,  $LIBBSOURCES, $LIBB)
{
	$path = "tempfiles/";
	$BUILD_PATH = "build/";
	$SOURCES_PATH = $BUILD_PATH."core/";
	$CLANG_INCL_PATH = "clang/include";
	
	$filename = $path.$filename;

	// General flags. Theese are common for all projects. Should be moved to a higher-level configuration.
	// Got these from original SConstruct. Get a monkey to check them?
	$CPPFLAGS = "-ffunction-sections -fdata-sections -fno-exceptions -funsigned-char -funsigned-bitfields -fpack-struct -fshort-enums -Os";
	$LDFLAGS = "-Os -Wl,--gc-sections";

	// die($LIBB);

	// This is temporary too :(
	$CPPFLAGS .= " -I".$BUILD_PATH."variants/standard";

	// Append project-specific stuff.
	$CPPFLAGS .= " -mmcu=atmega328p -DARDUINO=100 -DF_CPU=16000000L";
	$LDFLAGS .= " -mmcu=atmega328p";

	// Where to places these? How to compile them?
	$SOURCES = $SOURCES_PATH."wiring_shift.o ".$SOURCES_PATH."wiring_pulse.o ".$SOURCES_PATH."wiring_digital.o ".$SOURCES_PATH."wiring_analog.o ".$SOURCES_PATH."WInterrupts.o ".$SOURCES_PATH."wiring.o ".$SOURCES_PATH."Tone.o ".$SOURCES_PATH."WMath.o ".$SOURCES_PATH."HardwareSerial.o ".$SOURCES_PATH."Print.o ".$SOURCES_PATH."WString.o ".$SOURCES_PATH."IPAddress.o ".$SOURCES_PATH."Stream.o";

	$CLANG_FLAGS = "-fsyntax-only -Os -I".$CLANG_INCL_PATH." -I".$BUILD_PATH."variants/standard -I".$SOURCES_PATH." -D__AVR_ATmega328P__ -DARDUINO=100 -DF_CPU=16000000L -Wno-unknown-attributes -Wno-attributes -Wno-missing-declarations -Wno-deprecated-writable-strings";
	
	// Handle object files from libraries. Different CFLAGS? HELP!
	// Different error code, depending where it failed?

	$output = dothat($filename, "./preprocess.py $filename 2>&1");
	if($output["error"])
		return $output;

	exec("clang $LIBB $CLANG_FLAGS $filename.cpp 2>&1", $compiler_output, $ret);	
	exec("avr-g++ $LIBB $CLANG_INCL_PATH $CPPFLAGS -c -o $filename.o $filename.cpp -I".$SOURCES_PATH." 2>&1", $compiler_output2, $ret); // *.cpp -> *.o
	if($compiler_output == "" && $compiler_output2 != "")
		$compiler_output = $compiler_output2;
	
	$compiler_success = !$ret;
	if($compiler_success)
	{
		$output = dothat($filename, "avr-gcc $LDFLAGS -o $filename.elf $filename.o $SOURCES $LIBBSOURCES -lm 2>&1"); // *.o -> *.elf
		if($output["error"])
			return $output;
		$output = dothat($filename, "objcopy -O ihex -R .eeprom $filename.elf $filename.hex 2>&1"); // *.elf -> *.hex
		if($output["error"])
			return $output;
		$output = dothat($filename, "avr-size --target=ihex $filename.elf | awk 'FNR == 2 {print $1+$2}' 2>&1"); // We should be checking this.
		if($output["error"])
			return $output;
		$size = $output["output"][0];
		$output["size"] = $size;
	}
	$output["compiler_success"] = $compiler_success;
	$output["compiler_output"] = $compiler_output;
	cleanDir($filename);
	return $output;
}

function parse_headers($code)
{
	$matches = "";
	$code = explode("\n", $code);
	$headers = array();
	foreach ($code as $i)
		if(preg_match('/^\s*#\s*include\s*[<"]\s*(.*)\.h\s*[>"]/', $i, $matches))
			$headers[] = $matches[1];
	return $headers;
}

function add_libraries($LIBS_PATH, $headers)
{
	$LIBBSOURCES = "";
	$allowed=array("o");
	foreach ($headers as $i)
	{
		try {
			$it = new RecursiveDirectoryIterator($LIBS_PATH."$i/");
			foreach(new RecursiveIteratorIterator($it) as $file) 
			{
			    if(in_array(substr($file, strrpos($file, '.') + 1),$allowed))
				{
			        // echo $file ."\n";
					$LIBBSOURCES .= "$file ";
			    }
			}
		} catch (Exception $e)
		{
		    // return array("success"=> false, "text" => "Library Not Found: $i", "cmd" => 'Caught exception: '.$e->getMessage()."\n", "lines" => array(0));
		}
		
	}
	return array("success"=> true, "output"=>$LIBBSOURCES);
}

function add_paths($LIBS_PATH, $headers)
{
	$LIBB = "";
	// $extras = iterate_dir(getenv("ARDUINO_LIBS_DIR"));
	// foreach($extras as $extra)
	// {
	// 	$LIBB .=" -I".getenv("ARDUINO_LIBS_DIR").$extra;
	// }
	foreach ($headers as $dirname)
	{
		if(file_exists($LIBS_PATH.$dirname))
			$LIBB.=" -I".$LIBS_PATH.$dirname;
	}

	// $extras = iterate_dir(getenv("ARDUINO_EXTRA_LIBS_DIR"));
	// foreach($extras as $extra)
	// {
	// 	$LIBB .=" -I".getenv("ARDUINO_EXTRA_LIBS_DIR").$extra;
	// }
	return $LIBB;
}

function cleanDir($filename)
{
	
	if(file_exists($filename)) unlink($filename);	
	if(file_exists($filename.".o")) unlink($filename.".o");	
	if(file_exists($filename.".cpp")) unlink($filename.".cpp");	
	if(file_exists($filename.".elf")) unlink($filename.".elf");	
	// Remeber to suggest a cronjob, in case something goes wrong...
	// find $path -name $filename.{o,cpp,elf,hex} -mtime +1 -delete
}

function iterate_dir($directory)
{
	$dir = opendir($directory);
	$iter = readdir($dir);
	$array = array();
	while(!($iter === FALSE))
	{
		$array[] = $iter;
		// echo $dir."<br />";
		$iter = readdir();
	}
	for($i = 0; $i <= count($array); $i++ )
	{
		if($array[$i] == "." || $array[$i] == "..")
			unset($array[$i]);
	}

	sort($array);
	closedir($dir);
	return $array;
}

?>
