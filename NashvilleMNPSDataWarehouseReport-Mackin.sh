#!/bin/bash
# NashvilleMNPSDataWarehouseReport.sh
# James Staub, Nashville Public Library

# Read the configuration file
mackinUser=$(awk -F "=" '/mackinUser/ {print $2}' ../config.pwd.ini | tr -d ' ' | sed 's/^"\(.*\)"$/\1/')
mackinPassword=$(awk -F "=" '/mackinPassword/ {print $2}' ../config.pwd.ini | tr -d ' ' | sed 's/^"\(.*\)"$/\1/')
mackinErrorEmailRecipients=$(awk -F "=" '/mackinErrorEmailRecipients/ {print $2}' ../config.pwd.ini | tr -d ' ' | sed 's/^"\(.*\)"$/\1/')

# Function to send error emails
send_error_email() {
    local error_message="$1"
    local subject="MNPS MackinVIA Report: $error_message"
    
    # Echo the error to console regardless of email recipients
    echo "$error_message"
    
    # If no recipients are configured, just return
    if [ -z "$mackinErrorEmailRecipients" ]; then
        return
    fi
    
    # Send a single email to all recipients
    echo "$error_message" | mail -s "$subject" "$mackinErrorEmailRecipients"
}

# Function to format date_mackin based on date - before or after Aug 5 2025 which is when Mackin switched filename scheme
format_date_mackin() {
    local input_date="$1"
    local formatted_date
    # If date is on or after August 5, 2025, use %Y%m%d format, otherwise use %m_%d_%Y
    if [[ "$input_date" > "2025-08-04" ]]; then
        formatted_date=$(date -d "$input_date" +'%Y%m%d')
    else
        formatted_date=$(date -d "$input_date" +'%m_%d_%Y')
    fi
    echo "$formatted_date"
}

# Check if second argument includes -localfile flag
if [ $# -gt 1 ] && [[ "$2" == *"-localfile"* ]]; then
    # If -localfile flag is present, use the first argument as the date
    date_str=$1
    date=$(date -d "$date_str" +'%Y-%m-%d' 2>/dev/null)
    if [ $? -ne 0 ]; then
        error_msg="Invalid date format. Please use YYYY-MM-DD."
        send_error_email "$error_msg"
        exit 1
    fi

    # Set date format for -localfile filenames
    date_mackin=$(format_date_mackin "$date")
    # set date for output filename
    date_connected=$(date -d "$date" +'%Y-%m-%d')

    # Define the file paths (assuming files are already local)
    retrieved_file_students="../data/mackin/Nashville daily VIA report_$date_mackin.csv"
    retrieved_file_staff="../data/mackin/Nashville daily VIA report_staff_$date_mackin.csv"
else
    # Determine the date of the report record activity (either from arg1 or yesterday)
    if [ $# -gt 0 ]; then
        date_str=$1
        date=$(date -d "$date_str" +'%Y-%m-%d' 2>/dev/null)
        if [ $? -ne 0 ]; then
            error_msg="Invalid date format. Please use YYYY-MM-DD."
            send_error_email "$error_msg"
            exit 1
        fi
    else
        date=$(date -d "yesterday" +'%Y-%m-%d')
    fi

    # set date for Mackin filename
    date_mackin=$(format_date_mackin "$date")
    # set date for output filename
    date_connected=$(date -d "$date" +'%Y-%m-%d')

    # Check if the file exists on the remote server
    sshpass -p "$mackinPassword" sftp -q "$mackinUser"@sftp.mackin.com:Reports/*"$date_mackin"* >/dev/null 2>&1
    if [ $? -ne 0 ]; then
        error_msg="No file found for the date $date_mackin on the remote server. Exiting."
        send_error_email "$error_msg"
        exit 1
    fi

    # Retrieve the STAFF AND STUDENT report files from Mackin
    sshpass -p "$mackinPassword" sftp -q "$mackinUser"@sftp.mackin.com:Reports/*"$date_mackin"* ../data/mackin/

    # Define the file paths
    retrieved_file_students="../data/mackin/Nashville daily VIA report_$date_mackin.csv"
    retrieved_file_staff="../data/mackin/Nashville daily VIA report_staff_$date_mackin.csv"
fi

# Check if both files exist
if [ ! -f "$retrieved_file_students" ]; then
    error_msg="Error: Student report file not found at $retrieved_file_students . Exiting."
    send_error_email "$error_msg"
    exit 1
fi

if [ ! -f "$retrieved_file_staff" ]; then
    error_msg="Error: Staff report file not found at $retrieved_file_staff . Exiting."
    send_error_email "$error_msg"
    exit 1
fi

# Validate the USER_ID column in the student report file; can be email or student ID [the latter is very rare]
invalid_record=$(awk -F, 'NR > 1 && ($3 !~ /^[^@]+@[^@]+\.[^@]+$/ && $3 !~ /^190[0-9]{6}$/) {print "Invalid record at line " NR ": " $0; exit 1}' "$retrieved_file_students")
if [ -z "$invalid_record" ]; then
    echo "Data validation passed: All rows in the student report have a valid email address in USER_ID."
else
    error_msg="Error: Invalid email address or ID found in USER_ID column of the student report at $retrieved_file_students. Problematic record: $invalid_record"
    send_error_email "$error_msg"
    exit 1
fi

# Validate the USER_ID column in the staff report file; can be email or staff ID [the latter is very rare]
invalid_record=$(awk -F, 'NR > 1 && ($3 !~ /^[^@]+@[^@]+\.[^@]+$/ && $3 !~ /^[0-9]{6,7}$/) {print "Invalid record at line " NR ": " $0; exit 1}' "$retrieved_file_staff")
if [ -z "$invalid_record" ]; then
    echo "Data validation passed: All rows in the staff report have a valid email address in USER_ID."
else
    error_msg="Error: Invalid email address or ID found in USER_ID column of the staff report at $retrieved_file_staff. Problematic record: $invalid_record"
    send_error_email "$error_msg"
    exit 1
fi

# STAFF: Read the SQL file
sql=$(<NashvilleMNPSDataWarehouseReport-Mackin-Staff.sql)
# STAFF: Replace placeholders with the actual date
sql=${sql//DATEPLACEHOLDERMMDDYYYY/$date_mackin}
sql=${sql//DATEPLACEHOLDERYYYYMMDD/$date_connected}
# STAFF: Write the modified SQL - incorporating custom date - to a new file
echo "$sql" > NashvilleMNPSDataWarehouseReport-Mackin-Staff-Date-Specific.sql
# STAFF: Run the modified SQL file through sqlite3
sqlite3 ../data/ic2carlx_mnps_staff.db < NashvilleMNPSDataWarehouseReport-Mackin-Staff-Date-Specific.sql

# STUDENTS: Read the SQL file
sql=$(<NashvilleMNPSDataWarehouseReport-Mackin-Students.sql)
# STUDENTS: Replace placeholders with the actual date
sql=${sql//DATEPLACEHOLDERMMDDYYYY/$date_mackin}
sql=${sql//DATEPLACEHOLDERYYYYMMDD/$date_connected}
# STUDENTS: Write the modified SQL - incorporating custom date - to a new file
echo "$sql" > NashvilleMNPSDataWarehouseReport-Mackin-Students-Date-Specific.sql
# STUDENTS: Run the modified SQL file through sqlite3
sqlite3 ../data/ic2carlx_mnps_students.db < NashvilleMNPSDataWarehouseReport-Mackin-Students-Date-Specific.sql

# Check if output files were created
STAFF_OUTPUT_FILE="../data/LibraryServices-Checkouts-MackinVIA-staff-$date_connected.csv"
STUDENT_OUTPUT_FILE="../data/LibraryServices-Checkouts-MackinVIA-student-$date_connected.csv"

if [ ! -f "$STAFF_OUTPUT_FILE" ]; then
    error_msg="Error: Staff output file was not created at $STAFF_OUTPUT_FILE . Exiting."
    send_error_email "$error_msg"
    exit 1
fi

if [ ! -f "$STUDENT_OUTPUT_FILE" ]; then
    error_msg="Error: Student output file was not created at $STUDENT_OUTPUT_FILE . Exiting."
    send_error_email "$error_msg"
    exit 1
fi

SOURCE_FILE="../data/LibraryServices-Checkouts-MackinVIA-*-$date_connected.csv"
DEST_DIR="/home/mnps.org/data"
# Set permissions for the files
chown :mnps.org $SOURCE_FILE
chmod 660 $SOURCE_FILE
# Move the files
mv $SOURCE_FILE $DEST_DIR/