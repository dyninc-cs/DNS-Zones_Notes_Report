#!/usr/bin/php
<?php
#This script writes the notes of your zone to a CSV file. You can limit the number of notes requested.
#The credentials are read in from a configuration file in the same directory.
#The file is named config.ini.

#Usage: %php znr.php  [-z]
#Options:
#-h, --help		Show this help message and exit
#-z, --zones		Output all zones
#-l, --limit		Set the maximum number of notes to retrieve

#Get options from command line
$shortopts .= "z:"; 
$shortopts .= "l:";  
$shortopts .= "h"; 
$longopts  = array(
			"zones::",
			"limit::",   
			"help",);	
$options = getopt($shortopts, $longopts);

$opt_limit .= $options["l"]; 
$opt_zone .= $options["z"]; 
date_default_timezone_set('UTC'); #Sets timezone to UTC for datetime

#Print help menu
if (is_bool($options["h"])) {
	print "\tAPI integration requires paramaters stored in config.ini\n\n";
        print "\tOptions:\n";
        print "\t\t-h, --help\t\t Show the help message and exit\n";
        print "\t\t-l, --limit\t\t Set the maximum number of notes to retrieve\n";
        print "\t\t-z, --zone_name\t\t Name of zone\n\n";
        exit;}
		
# Parse ini file (can fail)
#Set the values from file to variables or die
$ini_array = parse_ini_file("config.ini") or die;
$api_cn = $ini_array['cn'] or die("Customer Name required in config.ini for API login\n");
$api_un = $ini_array['un'] or die("User Name required in config.ini for API login\n");
$api_pw = $ini_array['pw'] or die("Password required in config.ini for API login\n");	

# Prevent the user from proceeding if they have not entered -z
if($opt_zone == "")
{
	print "You must enter \"-z [example.com]\"\n";
	exit;
}

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

# Setting file name and opening file for writing
$opt_file = "notes_$opt_zone.csv";
$fp = fopen($opt_file, 'w') or die;
print "Writing CSV file to: $opt_file\n";

#If -l is set then send then limit. If it is not, assume no limit.
if($opt_limit!=0)
        {$api_param = array('zone' => $opt_zone, 'limit' => $opt_limit);}
else
        {$api_param = array('zone' => $opt_zone);}

$session_uri = "https://api2.dynect.net/REST/ZoneNoteReport";
$decoded_result = api_request($session_uri, 'POST', $api_param, $token);
foreach ($decoded_result->data as $zoneIn)
{
        $user = $zoneIn->user_name;
	$type = $zoneIn->type;
        $note =  $zoneIn->note;
	$note = rtrim($note);
        $time =  $zoneIn->timestamp;
	$dt = new DateTime("@$time"); # Set epoch time to datetime
	$time=  $dt->format('M d, Y (H:i - \U\T\C)'); # Format date
	fputcsv($fp, array($user, $type, $time, $note)); #Send evertying in the array to the csv
}

fclose($fp);
print "CSV file write sucessful.";

# Logging Out
$session_uri = 'https://api2.dynect.net/REST/Session/'; 
$api_params = array (''=>'');
$decoded_result = api_request($session_uri, 'DELETE', $api_params,  $token);	

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
function api_fail($token, $api_jsonref) 
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


