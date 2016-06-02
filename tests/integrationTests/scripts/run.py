#!/usr/bin/python2

import sys
import os
import glob
import subprocess

import lib

# Ensure the correct paths for loading modules are recognized by
# Python. We require both the test packages themselves (which are
# are stored outside of the repository), as well as the "lib"
# module residing within the repository in the scripts directory.

test_suite_install_dir = "__INSTALL__FILLIN__HSS_INSTALL_DIR__/test_suite/integrationTests"

print "TEST SUITE INSTALLATION: "+test_suite_install_dir
sys.path.insert(0, test_suite_install_dir)

# Load all test packages, which will populate the dictionary in
# the "lib" module.
import tests

# If we were given an argument, run only the test with that name.
# Otherwise, run every test.
if len(sys.argv) == 1:
    lib.run_all()
else:
    lib.run_test(sys.argv[1])
