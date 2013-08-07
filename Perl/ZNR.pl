#!/usr/bin/perl
#This script sends the notes of your zone to a CSV file. Inputting a zone is required. 
#You can set a limit of notes to display and epoch time if preferred.
#The credentials are read in from a configuration file in the same directory.
#The file is named config.cfg in the format:

#[Dynect]
#cn: customer_name
#un: user_name
#pw: password

#Usage: %perl ZNR.pl -z example.com [-l 10] [-e] [-f zone_notes.csv]
#This will print to the file zone_notes.csv to a CSV file with a limit of 10 notes and epoch time.

# Options
# -h --help			Show the help message and exit
# -z --zone			Search for zone report with zone name
# -l --limit	 		The maximum number of notes to retrieve
# -e --epoch			Use epoch time instead of a formatted time
# -f --file			Set file name. Default: notes_[zonename].csv

use warnings;
use strict;
use Config::Simple;
use Getopt::Long;
use LWP::UserAgent;
use JSON;
use Text::CSV;
use POSIX qw( strftime );

#Import DynECT handler
use FindBin;
use lib "$FindBin::Bin/DynECT";  # use the parent directory
require DynECT::DNS_REST;

#Get Options
my $opt_list=0; #Initalized to check if limit is set
my $opt_file=""; #Initalized to see check against opt_file being set
my $opt_zone=""; #Initalized to see check against opt_zone being set
my $opt_help;
my $opt_epoch;
my $notelist;
my %api_param;

GetOptions(
	'help' => \$opt_help,
	'epoch' => \$opt_epoch,
	'limit=i' => \$opt_list,
	'file=s' => \$opt_file,
	'zone=s' =>\$opt_zone,
);

#Printing help menu
if ($opt_help) {
	print "\tOptions:\n";
	print "\t\t-h, --help\t\t Show the help message and exit\n";
	print "\t\t-e, --epoch\t\t Use epoch time instead of a formatted time\n";
	print "\t\t-f, --file\t\t Set file name. Default: notes_[zonename].csv\n";
	print "\t\t-l, --limit\t\t Set the maximum number of notes to retrieve (Newest first)\n";
	print "\t\t-z, --zone\t\t Name of zone (Required)\n\n";
	exit;
}

#Checking if -z is set
elsif ($opt_zone eq "")
{
	print "Zonename needs to be set. Use \"-z [zonename]\"\n";
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
my $dynect = DynECT::DNS_REST->new;
$dynect->login( $apicn, $apiun, $apipw) or
	die $dynect->message;

# Setting up new csv file
$opt_file = "notes_$opt_zone.csv" unless($opt_file ne ""); #Set filename if -f else, use default
my $fh;
my $csv = Text::CSV->new ( { binary => 1, eol => "\n" } ) or die "Cannot use CSV: ".Text::CSV->error_diag ();
open $fh, ">", $opt_file  or die "new.csv: $!";
print "Writing CSV file to: $opt_file\n";

# Setting header information
$csv->print($fh, [ "User", "Type", "When", "Note"]);


#If -l is set then send then limit. If it is not, assume no limit.
if($opt_list!=0)
	{%api_param = (zone => $opt_zone, limit => $opt_list);}
else
	{%api_param = (zone => $opt_zone);}
$dynect->request( "/REST/ZoneNoteReport", 'POST',  \%api_param) or die $dynect->message;

#Print out the zone, type, time and note to the user/file
foreach my $zoneIn (@{$dynect->result->{'data'}})
{
	my $user = $zoneIn->{'user_name'};
	my $type = $zoneIn->{'type'};
	my $time = $zoneIn->{'timestamp'};
	my $note = $zoneIn->{'note'};
	chomp $note;
	$time = strftime("%b %d, %Y (%H:%M - UTC)", gmtime($time)) unless($opt_epoch); #Set formatted time unless -e
	$csv->print ($fh, [ $user, $type, $time, $note ] );
}

# Close csv file
close $fh or die "$!";
print "CSV file write sucessful.\n";

#API logout
$dynect->logout;
