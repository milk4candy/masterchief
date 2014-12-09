#! /usr/bin/python

import os
import sys

script_path =  os.path.dirname(os.path.abspath(__file__))
lib_dir = script_path + '/lib'

sys.path.append(lib_dir)

import pam

p = pam.pam()

if p.authenticate(sys.argv[1], sys.argv[2]):
    sys.exit(0)
else:
    sys.exit(1)
