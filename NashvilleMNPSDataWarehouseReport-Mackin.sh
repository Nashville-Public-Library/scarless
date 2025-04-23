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

# Check if the file exists on the remote server
sshpass -p "$mackinPassword" sftp "$mackinUser"@sftp.mackin.com:Reports/*"$date_mmddyyyy"* >/dev/null 2>&1
if [ $? -ne 0 ]; then
    echo "No file found for the date $date_mmddyyyy on the remote server. Exiting."
    exit 1
fi

# Retrieve the STAFF AND STUDENT report files from Mackin
sshpass -p "$mackinPassword" sftp "$mackinUser"@sftp.mackin.com:Reports/*"$date_mmddyyyy"* ../data/mackin/

# Define the file paths
retrieved_file_students="../data/mackin/Nashville daily VIA report_$date_mmddyyyy.csv"
retrieved_file_staff="../data/mackin/Nashville daily VIA report_staff_$date_mmddyyyy.csv"

# Check if both files exist
if [ ! -f "$retrieved_file_students" ]; then
    echo "Error: Student report file not found. Exiting."
    exit 1
fi

if [ ! -f "$retrieved_file_staff" ]; then
    echo "Error: Staff report file not found. Exiting."
    exit 1
fi

# Validate the USER_ID column in the student report file
if awk -F, 'NR > 1 && $3 !~ /^[^@]+@[^@]+\.[^@]+$/ {exit 1}' "$retrieved_file_students"; then
    echo "Data validation passed: All rows in the student report have a valid email address in USER_ID."
else
    echo "Error: Invalid email address found in USER_ID column of the student report. Exiting."
    exit 1
fi

# Validate the USER_ID column in the staff report file
if awk -F, 'NR > 1 && $3 !~ /^[^@]+@[^@]+\.[^@]+$/ {exit 1}' "$retrieved_file_staff"; then
    echo "Data validation passed: All rows in the staff report have a valid email address in USER_ID."
else
    echo "Error: Invalid email address found in USER_ID column of the staff report. Exiting."
    exit 1
fi

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

SOURCE_FILE="../data/LibraryServices-Checkouts-MackinVIA-*-$date_yyyymmdd.csv"
DEST_DIR="/home/mnps.org/data"
# Set permissions for the files
chown :mnps.org $SOURCE_FILE
chmod 660 $SOURCE_FILE
# Move the files
mv $SOURCE_FILE $DEST_DIR/