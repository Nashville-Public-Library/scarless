#!/bin/bash
# NashvilleMNPSDataWarehouseReport.sh
# James Staub, Nashville Public Library

# Read the configuration file
mackinUser=$(awk -F "=" '/mackinUser/ {print $2}' ../config.pwd.ini | tr -d ' ' | sed 's/^"\(.*\)"$/\1/')
mackinPassword=$(awk -F "=" '/mackinPassword/ {print $2}' ../config.pwd.ini | tr -d ' ' | sed 's/^"\(.*\)"$/\1/')

# Determine the date of the report record activity (either from arg1 or yesterday)
if [ $# -gt 0 ]; then
    date_str=$1
    date=$(date -d "$date_str" +'%Y-%m-%d' 2>/dev/null)
    if [ $? -ne 0 ]; then
        echo "Invalid date format. Please use YYYY-MM-DD."
        exit 1
    fi
else
    date=$(date -d "yesterday" +'%Y-%m-%d')
fi

# set date for Mackin filename
date_mmddyyyy=$(date -d "$date" +'%m_%d_%Y')
# set date for output filename
date_yyyymmdd=$(date -d "$date" +'%Y-%m-%d')

# Retrieve the STAFF AND STUDENT report files from Mackin
sshpass -p "$mackinPassword" sftp "$mackinUser"@sftp.mackin.com:Reports/*"$date_mmddyyyy"* ../data/mackin/

# STAFF: Read the SQL file
sql=$(<NashvilleMNPSDataWarehouseReport-Mackin-Staff.sql)
# STAFF: Replace placeholders with the actual date
sql=${sql//DATEPLACEHOLDERMMDDYYYY/$date_mmddyyyy}
sql=${sql//DATEPLACEHOLDERYYYYMMDD/$date_yyyymmdd}
# STAFF: Write the modified SQL - incorporating custom date - to a new file
echo "$sql" > NashvilleMNPSDataWarehouseReport-Mackin-Staff-Date-Specific.sql
# STAFF: Run the modified SQL file through sqlite3
sqlite3 ../data/ic2carlx_mnps_staff.db < NashvilleMNPSDataWarehouseReport-Mackin-Staff-Date-Specific.sql

# STUDENTS: Read the SQL file
sql=$(<NashvilleMNPSDataWarehouseReport-Mackin-Students.sql)
# STUDENTS: Replace placeholders with the actual date
sql=${sql//DATEPLACEHOLDERMMDDYYYY/$date_mmddyyyy}
sql=${sql//DATEPLACEHOLDERYYYYMMDD/$date_yyyymmdd}
# STUDENTS: Write the modified SQL - incorporating custom date - to a new file
echo "$sql" > NashvilleMNPSDataWarehouseReport-Mackin-Students-Date-Specific.sql
# STUDENTS: Run the modified SQL file through sqlite3
sqlite3 ../data/ic2carlx_mnps_students.db < NashvilleMNPSDataWarehouseReport-Mackin-Students-Date-Specific.sql