#!/bin/sh

""":"
# bash code here; finds a suitable python interpreter and execs this file.
# prefer unqualified "python" if suitable:
python -c 'import sys; sys.exit(not (0x020700b0 < sys.hexversion < 0x03000000))' 2>/dev/null \
    && exec python "$0" "$@"
for pyver in 2.7; do
    which python$pyver > /dev/null 2>&1 && exec python$pyver "$0" "$@"
done
echo "No appropriate python interpreter found." >&2
exit 1
":"""

from __future__ import with_statement

import cmd
import codecs
import ConfigParser
import csv
import getpass
import optparse
import os
import platform
import sys
import traceback
import warnings
import webbrowser
from StringIO import StringIO
from contextlib import contextmanager
from glob import glob
from uuid import UUID
from webhook import processWebhookTransaction, openCassandraSession

if sys.version_info[0] != 2 or sys.version_info[1] != 7:
    sys.exit("\nCQL Shell supports only Python 2.7\n")

UTF8 = 'utf-8'
CP65001 = 'cp65001'  # Win utf-8 variant

description = "CQL Shell for Apache Cassandra"
version = "5.0.1"

readline = None
try:
    # check if tty first, cause readline doesn't check, and only cares
    # about $TERM. we don't want the funky escape code stuff to be
    # output if not a tty.
    if sys.stdin.isatty():
        import readline
except ImportError:
    pass

CQL_LIB_PREFIX = 'cassandra-driver-internal-only-'

CASSANDRA_PATH = os.path.join(os.path.dirname(os.path.realpath(__file__)), '..')
CASSANDRA_CQL_HTML_FALLBACK = 'https://cassandra.apache.org/doc/cql3/CQL-2.2.html'

if os.path.exists(CASSANDRA_PATH + '/doc/cql3/CQL.html'):
    # default location of local CQL.html
    CASSANDRA_CQL_HTML = 'file://' + CASSANDRA_PATH + '/doc/cql3/CQL.html'
elif os.path.exists('/usr/share/doc/cassandra/CQL.html'):
    # fallback to package file
    CASSANDRA_CQL_HTML = 'file:///usr/share/doc/cassandra/CQL.html'
else:
    # fallback to online version
    CASSANDRA_CQL_HTML = CASSANDRA_CQL_HTML_FALLBACK

# On Linux, the Python webbrowser module uses the 'xdg-open' executable
# to open a file/URL. But that only works, if the current session has been
# opened from _within_ a desktop environment. I.e. 'xdg-open' will fail,
# if the session's been opened via ssh to a remote box.
#
# Use 'python' to get some information about the detected browsers.
# >>> import webbrowser
# >>> webbrowser._tryorder
# >>> webbrowser._browser
#
if len(webbrowser._tryorder) == 0:
    CASSANDRA_CQL_HTML = CASSANDRA_CQL_HTML_FALLBACK
elif webbrowser._tryorder[0] == 'xdg-open' and os.environ.get('XDG_DATA_DIRS', '') == '':
    # only on Linux (some OS with xdg-open)
    webbrowser._tryorder.remove('xdg-open')
    webbrowser._tryorder.append('xdg-open')

# use bundled libs for python-cql and thrift, if available. if there
# is a ../lib dir, use bundled libs there preferentially.
ZIPLIB_DIRS = [os.path.join(CASSANDRA_PATH, 'lib')]
myplatform = platform.system()
is_win = myplatform == 'Windows'

# Workaround for supporting CP65001 encoding on python < 3.3 (https://bugs.python.org/issue13216)
if is_win and sys.version_info < (3, 3):
    codecs.register(lambda name: codecs.lookup(UTF8) if name == CP65001 else None)

if myplatform == 'Linux':
    ZIPLIB_DIRS.append('/usr/share/cassandra/lib')

if os.environ.get('CQLSH_NO_BUNDLED', ''):
    ZIPLIB_DIRS = ()


def find_zip(libprefix):
    for ziplibdir in ZIPLIB_DIRS:
        zips = glob(os.path.join(ziplibdir, libprefix + '*.zip'))
        if zips:
            return max(zips)   # probably the highest version, if multiple

cql_zip = find_zip(CQL_LIB_PREFIX)
if cql_zip:
    ver = os.path.splitext(os.path.basename(cql_zip))[0][len(CQL_LIB_PREFIX):]
    sys.path.insert(0, os.path.join(cql_zip, 'cassandra-driver-' + ver))

third_parties = ('futures-', 'six-')

for lib in third_parties:
    lib_zip = find_zip(lib)
    if lib_zip:
        sys.path.insert(0, lib_zip)

warnings.filterwarnings("ignore", r".*blist.*")
try:
    import cassandra
except ImportError, e:
    sys.exit("\nPython Cassandra driver not installed, or not on PYTHONPATH.\n"
             'You might try "pip install cassandra-driver".\n\n'
             'Python: %s\n'
             'Module load path: %r\n\n'
             'Error: %s\n' % (sys.executable, sys.path, e))

from cassandra.auth import PlainTextAuthProvider
from cassandra.cluster import Cluster
import json

session = openCassandraSession()
for line in sys.stdin:
	if line == "true\n":
		break
	data = json.loads(line)
	transaction = data['args']
	transTime = transaction['time']
	try:
		transFrom = transaction['from']
	except:
		transFrom = "Admin"
	transTo = transaction['to']
	try:
		transRecieved = transaction['recieved']
	except:
		transRecieved = transaction['value']
	try:
		transSent = transaction['send']
	except:
		transSent = transaction['recieved']
	try:
		transTax = transaction['tax']
	except:
		transTax = 0
	transEvent = data['event']
	transHash = data['transactionHash']
	transBlock = str(data['blockNumber'])
	transId = transTime + transHash
	addrJson = "{'from':'" + transFrom + "','to':'" + transTo + "'}"
	#valueJson = "{'recevied':" + transRecieved + ",'sent':" + transSent + ",'tax':" + transTax + "}"

	print transTime + " - Added transaction " + transHash + " from block " + transBlock
	
	# insert the correspondance table between the transaction hash and the address
	cqlInsertHash = "INSERT INTO trans_by_addr (hash, addr) VALUES ('{}', {})".format(transHash, addrJson)
	print(cqlInsertHash)
	session.execute(cqlInsertHash)
	# Check if the transaction is in the webshop_transactions
	cqlcommand = "SELECT hash, store_id, store_ref, delegate , toTimestamp(now()) AS stamp FROM webshop_transactions WHERE hash='{}'".format(transHash)
	rows = session.execute(cqlcommand)
	if len(rows)>0:
	    row = rows[0]
	    if 'store_id' in row: # this is a webshop transaction
	        store_id = row['store_id']
	        store_ref = row['store_ref']
	        attempt_date = row['stamp']-10800000
	    
	        if 'delegate' in row: 
	            # this is a delegated webshop transaction
	            cqlcommand = "INSERT INTO transactions (hash, block, recieved, sent, tax, time, type, addr_from, addr_to, status, store_id, store_ref, tr_attempt_nb, tr_attempt_date, delegate) VALUES ('{}', '{}', {}, {}, {}, '{}', '{}', '{}','{}', 1,'{}','{}',0,{}) IF NOT EXISTS".format(transHash, transBlock, transRecieved, transSent, transTax, transTime, transEvent, transFrom, transTo, store_id, store_ref, attempt_date, row['delegate'] )
	        
	        else:
	            # this is a webshop transaction without delegation
	            cqlcommand = "INSERT INTO transactions (hash, block, recieved, sent, tax, time, type, addr_from, addr_to, status, store_id, store_ref, tr_attempt_nb, tr_attempt_date) VALUES ('{}', '{}', {}, {}, {}, '{}', '{}', '{}','{}', 1, '{}','{}',0) IF NOT EXISTS".format(transHash, transBlock, transRecieved, transSent, transTax, transTime, transEvent, transFrom, transTo, store_id, store_ref, attempt_date)
	            
	    elif 'delegate' in row: 
	        # this is a delegated transaction
	        cqlcommand = "INSERT INTO transactions (hash, block, recieved, sent, tax, time, type, addr_from, addr_to, status, delegate) VALUES ('{}', '{}', {}, {}, {}, '{}', '{}', '{}','{}', 0, '{}')".format(transHash, transBlock, transRecieved, transSent, transTax, transTime, transEvent, transFrom, transTo, row['delegate'])
	else:
	    #transaction not linked to a webshop nor delegated
	    cqlcommand = "INSERT INTO transactions (hash, block, recieved, sent, tax, time, type, addr_from, addr_to, status) VALUES ('{}', '{}', {}, {}, {}, '{}', '{}', '{}','{}', 0) IF NOT EXISTS".format(transHash, transBlock, transRecieved, transSent, transTax, transTime, transEvent, transFrom, transTo)
	
	print(cqlcommand)
	session.execute(cqlcommand)
	
	# Clear the webshop_transactions
	cqlcommand = "DELETE FROM  webshop_transactions WHERE hash='{}'".format(transHash)
	rows = session.execute(cqlcommand)

# send webhook for the newly inserted transactions	
processWebhookTransaction(True)
	
	
