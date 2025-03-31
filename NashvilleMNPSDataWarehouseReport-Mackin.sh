#!/bin/bash
# NashvilleMNPSDataWarehouseReport.sh
# James Staub, Nashville Public Library

import datetime
import sys

# Determine the date of the report record activity (either from arg1 or yesterday)
# Check if a date argument is provided
if len(sys.argv) > 1:
    date_str = sys.argv[1]
    try:
        date = datetime.datetime.strptime(date_str, '%Y-%m-%d')
    except ValueError:
        print("Invalid date format. Please use YYYY-MM-DD.")
        sys.exit(1)
else:
# Use yesterday's date if no argument is provided
    date = datetime.datetime.now() - datetime.timedelta(days=1)
# set date for Mackin filename
date_mmddyyyy = date.strftime('%m_%d_%Y')
# set date for output filename
date_yyyymmdd = date.strftime('%Y-%m-%d')

# Read the SQL file
with open('NashvilleMNPSDataWarehouseReport-Mackin.sql', 'r') as file:
    sql = file.read()

# Replace placeholders with the actual date
sql = sql.replace('DATEPLACEHOLDERMMDDYYYY', date_mmddyyyy)
sql = sql.replace('DATEPLACEHOLDERYYYYMMDD', date_yyyymmdd)

# Write the modified SQL - incorporating custom date - to a new file
with open('NashvilleMNPSDataWarehouseReport-Mackin-Date-Specific.sql', 'w') as file:
    file.write(sql)

# Run the modified SQL file through sqlite3
import os
os.system(f"sqlite3 ../data/ic2carlx_mnps_staff.db < NashvilleMNPSDataWarehouseReport-Mackin-Modified.sql")