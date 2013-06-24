#!/usr/bin/php
<?php
#This script prints out the notes of your zone with the option to print to a file.
#The credentials are read in from a configuration file in the same directory.
#The file is named credentials.cfg in the format:

#Usage: %php znr.php  [-z]
#Options:
#-h, --help		Show this help message and exit
#-z, --zones		Output all zones
#-l, --limit		Set the maximum number of notes to retrieve
#-f, --file		File to output list to

#Get options from command line
$shortopts .= "f:"; 
$shortopts .= "z:"; 
$shortopts .= "l:";  
$shortopts .= "h"; 
$longopts  = array(
			"file::",	
			"zones::",
			"limit::",   
			"help",);	
$options = getopt($shortopts, $longopts);
#Set file to -f
$opt_file .= $options["f"]; 
$opt_limit .= $options["l"]; 
$opt_zone .= $options["z"]; 

#Print help menu
if (is_bool($options["h"])) {
	print "\tAPI integration requires paramaters stored in config.ini\n\n";
        print "\tOptions:\n";
        print "\t\t-h, --help\t\t Show the help message and exit\n";
        print "\t\t-l, --limit\t\t Set the maximum number of notes to retrieve\n";
        print "\t\t-n, --file\t\t Set the file to output\n";
        print "\t\t-Z, --zone_name\t\t Name of zone\n\n";
        exit;}
		
# Parse ini file (can fail)
#Set the values from file to variables or die
$ini_array = parse_ini_file("config.ini") or die;
$api_cn = $ini_array['cn'] or die("Customer Name required in config.ini for API login\n");
$api_un = $ini_array['un'] or die("User Name required in config.ini for API login\n");
$api_pw = $ini_array['pw'] or die("Password required in config.ini for API login\n");	

# Prevent the user from proceeding if they have not entered -n or -z
if($opt_zone == "")
{
	print "You must enter \"-z [example.com]\"\n";
	exit;
}

#If the file is to be written to file, start ob
if(is_string($options["f"])){ob_start();}

# Log into DYNECT
# Create an associative array with the required arguments
$api_params = array(
			'customer_name' => $api_cn,
			'user_name' => $api_un,
			'password' => $api_pw);
$session_uri = 'https://api2.dynect.net/REST/Session/'; 
$decoded_result = api_request($session_uri, 'POST', $api_params,  $token);	

#Set the token
if($decoded_result->status == 'success')
	{$token = $decoded_result->data->token;}
else
{
	#Print the result if it's an error
	foreach($decoded_result->msgs as $message)
		{print $message->LVL.": ".($message->ERR_CD != '' ? '('.$message->ERR_CD.') ' : '').$message->SOURCE." - ".$message->INFO."\n\n";}
	exit;
}


# Zone URI & Empty Params	
$session_uri = 'https://api2.dynect.net/REST/Zone/'; 
$api_params = array (''=>'');
$decoded_result = api_request($session_uri, 'GET', $api_params,  $token);	


# Print nodes to user 
print "=======Nodes=======\n";
$session_uri = 'https://api2.dynect.net/REST/NodeList/'. $opt_zone . '/'; 
$api_params = array (''=>'');
$decoded_result = api_request($session_uri, 'GET', $api_params,  $token);	
foreach($decoded_result->data as $nodein)
	{print $nodein. "\n";}

#If -l is set then send then limit. If it is not, assume no limit.
if($opt_limit!=0)
        {$api_param = array('zone' => $opt_zone, 'limit' => $opt_limit);}
else
        {$api_param = array('zone' => $opt_zone);}

#Print out the zone, type, time and note to the user
$session_uri = "https://api2.dynect.net/REST/ZoneNoteReport";
$decoded_result = api_request($session_uri, 'POST', $api_param, $token);
print "\n=====Zone Name=====\n$opt_zone\n\n";
foreach ($decoded_result->data as $zoneIn)
{
        print "=======Type========\n" . $zoneIn->user_name . "\n";
        print "=====Timestamp=====\n" . $zoneIn->timestamp . "\n";
        print "=======Note========\n" . $zoneIn->note . "\n";
}


#If -f is set, send the output to the file
if(is_string($options['f']))
{
	$output = ob_get_contents();
	ob_end_flush();
	$fp = fopen($opt_file,"w");
	fwrite($fp,$output);
	fclose($fp);
}

# Logging Out
$session_uri = 'https://api2.dynect.net/REST/Session/'; 
$api_params = array (''=>'');
$decoded_result = api_request($session_uri, 'DELETE', $api_params,  $token);	
#Print result if error occurs
if($decoded_result->status != 'success')
{
	foreach($decoded_result->msgs as $message)
		{print $message->LVL.": ".($message->ERR_CD != '' ? '('.$message->ERR_CD.') ' : '').$message->SOURCE." - ".$message->INFO."\n";}
}

# Function that takes zone uri, request type, parameters, and token.
# Returns the decoded result
function api_request($zone_uri, $req_type, $api_params, $token)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  # TRUE to return the transfer as a string of the return value of curl_exec() instead of outputting it out directly.
	curl_setopt($ch, CURLOPT_FAILONERROR, false); # Do not fail silently. We want a response regardless
	curl_setopt($ch, CURLOPT_HEADER, false); # disables the response header and only returns the response body
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Auth-Token: '.$token)); # Set the token and the content type so we know the response format
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $req_type);
	curl_setopt($ch, CURLOPT_URL, $zone_uri); # Where this action is going,
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($api_params));
	
	$http_result = curl_exec($ch);
	$decoded_result = json_decode($http_result); # Decode from JSON as our results are in the same format as our request
	
	if($decoded_result->status != 'success')
		{$decoded_result = api_fail($token, $decoded_result);}  	
	
	return $decoded_result;
}

#Expects 2 variable, first a reference to the API key and second a reference to the decoded JSON response
function api_fail($api_keyref, $api_jsonref) 
{
	#loop until the job id comes back as success or program dies
	while ( $api_jsonref->status != 'success' ) {
        	if ($api_jsonref->status != 'incomplete') {
                       foreach($api_jsonref->msgs as $msgref) {
                                print "API Error:\n";
                                print "\tInfo: " . $msgref->INFO . "\n";
                                print "\tLevel: " . $msgref->LVL . "\n";
                                print "\tError Code: " . $msgref->ERR_CD . "\n";
                                print "\tSource: " . $msgref->SOURCE . "\n";
                        };
                        #api logout or fail
			$session_uri = 'https://api2.dynect.net/REST/Session/'; 
			$api_params = array (''=>'');
			$decoded_result = api_request($session_uri, 'DELETE', $api_params,  $token);	
                        exit;
                }
                else {
                        sleep(5);
                        $session_uri = "https://api2.dynect.net/REST/Job/" . $api_jsonref->job_id ."/";
			$api_params = array (''=>'');
			$decoded_result = api_request($session_uri, 'GET', $api_params,  $token);	
               }
        }
        return $api_jsonref;
}


?>


