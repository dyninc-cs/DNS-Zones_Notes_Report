#! /usr/bin/env python
'''
    This script prints out the notes of your zone with the option to print to a file.

    The credentials are read in from a configuration file in the same directory.

    The file is named credentials.cfg in the format:

    [Dynect]
    user: user_name
    customer: customer_name
    password: password

    Usage: %python znr.py [-z]

    Options
        -h, --help              show the help message and exit
        -z ZONE_NAME, --zone_name=ZONE_NAME
                                search for zone report with zone name
        -l LIMIT, --limit=LIMIT
                                the maximum number of notes to retrieve
        -f FILE, --file=FILE    File to output to

    The library is available at:
    https://github.com/dyninc/Dynect-API-Python-Library
'''
import sys
import ConfigParser
from optparse import OptionParser
from DynectDNS import DynectRest

# Create an instance of the api reference library to use
dynect = DynectRest()

def login(cust, user, pwd):
    '''
    This method will do a dynect login

    @param cust: customer name
    @type cust: C{str}

    @param user: user name
    @type user: C{str}

    @param pwd: password
    @type pwd: C{str}

    @return: The function will exit the script on failure on login
    @rtype: None

    '''

    arguments = {
            'customer_name': cust,
            'user_name': user,
            'password': pwd,
    }

    response = dynect.execute('/Session/', 'POST', arguments)

    if response['status'] != 'success':
        sys.exit("Incorrect credentials")
    elif response['status'] == 'success':
        print "Logged In"

def zoneNote(limit, zones, file=None):
    '''
    This method will print the zone notes report,
    with the option to print to a file.

    @param limit: The maximum number of notes to be retrieved
    @type limit: C{int}
    
    @param zones: name of zone
    @type zones: C{str}

    @param zones: file to print to
    @type zone: C{str}

    @return:
    @rytpe:

    '''
    
    # Arguments for POST statement
    zoneName = {
            'zone' : zones,
            'limit': limit,
    }
    

    response = dynect.execute('/REST/ZoneNoteReport/', 'POST', zoneName)
    if response['status'] != 'success':
        sys.exit("Zone Report Failed to Report")
   
    # Data from the ZoneNoteReoprt
    zoneData = response['data']
    
    ending = '/' + zones + '/'

    getNode = dynect.execute('/REST/NodeList' + ending, 'GET')
    print 
    print "NODE:"
    print "================"
    nodes = getNode['data']

    for node in nodes:
        print node
    
    # Getting header for zone report. 
    zone = zoneData[0]

    # Writing to a file.
    f = None
    if file != None:
        try:
            f = open(file, 'w')
        except:
            f = None
            print "Unable to open file for writing"
    
    if f != None:
        f.write("\nZone Name: \n")
        f.write(zone['zone'])
        f.write("\n\nType: \n")
        f.write(zone['type'])
        f.write("\n\nNotes: \n")
        f.write(zone['note'])
        f.write("\n\nTimestamp: \n")
        f.write(zone['timestamp'])
    
    
    # Screen output 
    print
    print 'Zone Note Report:'
    print '================'
    print
    print 'Zone Name'
    print zone['zone']
    print
    for zones in zoneData:
        print 'Type:'
        print '================'
        print zones['type']
        print
        print 'Timestamp:      '
        print '================'
        print zones['timestamp']
        print
        print 'Notes: '
        print '================'
        print zones['note']
        print

usage = "Usage: %python znr.py [-z] [options]"
parser = OptionParser(usage=usage)
parser.add_option("-z", "--zone_name", action="store", dest="zone_name", default=False, help="Search for zone report with zone name")
parser.add_option("-l", "--limit", dest="limit", default=10, type="int", help="The maximum number of notes to be retrieved")
parser.add_option("-f", "--file", dest="file", help="File to output to")
(options, args) = parser.parse_args()

# Making sure the user uses the -z flag.
if options.zone_name == None:
    parser.error("You must specify a zone name with the -z flag")

# Reading in the DynECT user credentials
config = ConfigParser.ConfigParser()
try:
    config.read('credentials.cfg')
except:
    sys.exit("Error Reading Config File")

login(config.get('Dynect', 'customer', 'none'), config.get('Dynect', 'user', 'none'), config.get('Dynect', 'password', 'none'))

# Main options calls the function with the correct parameters
if options.zone_name:
    zoneNote(options.limit, options.zone_name, options.file)

# Log out, to be polite
dynect.execute('/Session/', 'DELETE')
