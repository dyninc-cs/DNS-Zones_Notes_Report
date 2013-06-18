#!/usr/bin/perl
#This script prints out the notes of your zone with the option to print to a file.
#The credentials are read in from a configuration file in the same directory.
#The file is named credentials.cfg in the format:

#[Dynect]
#user: user_name
#customer: customer_name
#password: password

#Usage: %perl ZNR.pl  [-z]

#Options
#-h, --help			Show the help message and exit
#-z --zone_name	ZONE_NAME	Search for zone report with zone name
#-l --limit	LIMIT 		The maximum number of notes to retrieve
#-f --file	FILE    	File to output to
use warnings;
use strict;
use Data::Dumper;
use XML::Simple;
use Config::Simple;
use Getopt::Long qw(:config no_ignore_case);
use LWP::UserAgent;
use JSON;

#Get Options
my $opt_list=0;
my $opt_zone;
my $opt_file="";
my $opt_help;
my $notelist;

GetOptions(
	'help' => \$opt_help,
	'limit=i' => \$opt_list,
	'file=s' => \$opt_file,
	'zone_name=s' =>\$opt_zone,
);

#Printing help menu
if ($opt_help) {
	print "\tAPI integration requires paramaters stored in config.cfg\n\n";

	print "\tOptions:\n";
	print "\t\t-h, --help\t\t Show the help message and exit\n";
	print "\t\t-l, --limit\t\t Set the maximum number of notes to retrieve\n";
	print "\t\t-n, --file\t\t Set the file to output\n";
	print "\t\t-Z, --zone_name\t\t Name of zone\n\n";
	exit;
}

#Create config reader
my $cfg = new Config::Simple();

# read configuration file (can fail)
$cfg->read('config.cfg') or die $cfg->error();

#dump config variables into hash for later use
my %configopt = $cfg->vars();
my $apicn = $configopt{'cn'} or do {
	print "Customer Name required in config.cfg for API login\n";
	exit;
};

my $apiun = $configopt{'un'} or do {
	print "User Name required in config.cfg for API login\n";
	exit;
};

my $apipw = $configopt{'pw'} or do {
	print "User password required in config.cfg for API login\n";
	exit;
};

#API login
my $session_uri = 'https://api2.dynect.net/REST/Session';
my %api_param = ( 
	'customer_name' => $apicn,
	'user_name' => $apiun,
	'password' => $apipw,
);

#API Login
my $api_request = HTTP::Request->new('POST',$session_uri);
$api_request->header ( 'Content-Type' => 'application/json' );
$api_request->content( to_json( \%api_param ) );

my $api_lwp = LWP::UserAgent->new;
my $api_result = $api_lwp->request( $api_request );

my $api_decode = decode_json ( $api_result->content ) ;
my $api_key = $api_decode->{'data'}->{'token'};

#Set param to empty
%api_param = ();
$session_uri = "https://api2.dynect.net/REST/NodeList/$opt_zone";
$api_decode = &api_request($session_uri, 'GET', %api_param);
#Print out the nodes in the zone
$notelist .= "=======Nodes=======\n";
foreach my $nodeIn (@{$api_decode->{'data'}})
{
	#Print each node	
	$notelist .= "$nodeIn\n";
}

#If -l is set then send then limit. If it is not, assume no limit.
if($opt_list!=0)
	{%api_param = (zone => $opt_zone, limit => $opt_list);}
else
	{%api_param = (zone => $opt_zone);}
$session_uri = "https://api2.dynect.net/REST/ZoneNoteReport";
$api_decode = &api_request($session_uri, 'POST', %api_param); 


#Print out the zone, type, time and note to the user/file
$notelist .= "\n=====Zone Name=====\n$opt_zone\n\n";
foreach my $zoneIn (@{$api_decode->{'data'}})
{
	$notelist .= "=======Type========\n" . $zoneIn->{'user_name'} . "\n";
	$notelist .= "=====Timestamp=====\n" . $zoneIn->{'timestamp'} . "\n";
	$notelist .= "=======Note========\n".$zoneIn->{'note'} . "\n";
}

#Print the notes to the user and write to file if user has set a file name
print $notelist;
&write_file( $opt_file, \$notelist) unless ($opt_file eq "");

#api logout
%api_param = ();
$session_uri = 'https://api2.dynect.net/REST/Session';
&api_request($session_uri, 'DELETE', %api_param); 



#Writes to file using the filename and the string built file.
sub write_file{
	my( $opt_file, $notelist_ref ) = @_ ;
	open( my $fh, ">$opt_file" ) || die "can't create $opt_file $!" ;
	print $fh $$notelist_ref ;
}

#Accepts Zone URI, Request Type, and Any Parameters
sub api_request{
	#Get in variables, send request, send parameters, get result, decode, display if error
	my ($zone_uri, $req_type, %api_param) = @_;
	$api_request = HTTP::Request->new($req_type, $zone_uri);
	$api_request->header ( 'Content-Type' => 'application/json', 'Auth-Token' => $api_key );
	$api_request->content( to_json( \%api_param ) );
	$api_result = $api_lwp->request($api_request);
	$api_decode = decode_json( $api_result->content);
	#print $api_result->content . "\n";
	$api_decode = &api_fail(\$api_key, $api_decode) unless ($api_decode->{'status'} eq 'success');
	return $api_decode;
}

#Expects 2 variable, first a reference to the API key and second a reference to the decoded JSON response
sub api_fail {
	my ($api_keyref, $api_jsonref) = @_;
	#set up variable that can be used in either logic branch
	my $api_request;
	my $api_result;
	my $api_decode;
	my $api_lwp = LWP::UserAgent->new;
	my $count = 0;
	#loop until the job id comes back as success or program dies
	while ( $api_jsonref->{'status'} ne 'success' ) {
		if ($api_jsonref->{'status'} ne 'incomplete') {
			foreach my $msgref ( @{$api_jsonref->{'msgs'}} ) {
				print "API Error:\n";
				print "\tInfo: $msgref->{'INFO'}\n" if $msgref->{'INFO'};
				print "\tLevel: $msgref->{'LVL'}\n" if $msgref->{'LVL'};
				print "\tError Code: $msgref->{'ERR_CD'}\n" if $msgref->{'ERR_CD'};
				print "\tSource: $msgref->{'SOURCE'}\n" if $msgref->{'SOURCE'};
			};
			#api logout or fail
			$api_request = HTTP::Request->new('DELETE','https://api2.dynect.net/REST/Session');
			$api_request->header ( 'Content-Type' => 'application/json', 'Auth-Token' => $$api_keyref );
			$api_result = $api_lwp->request( $api_request );
			$api_decode = decode_json ( $api_result->content);
			exit;
		}
		else {
			sleep(5);
			my $job_uri = "https://api2.dynect.net/REST/Job/$api_jsonref->{'job_id'}/";
			$api_request = HTTP::Request->new('GET',$job_uri);
			$api_request->header ( 'Content-Type' => 'application/json', 'Auth-Token' => $$api_keyref );
			$api_result = $api_lwp->request( $api_request );
			$api_jsonref = decode_json( $api_result->content );
		}
	}
	$api_jsonref;
}

