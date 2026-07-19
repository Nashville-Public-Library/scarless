#!/bin/bash
# NashvilleMNPSDataWarehouseReport-OverDrive.sh
# James Staub, Nashville Public Library with significant assistance from JetBrains Junie
#
# USAGE:
# ./NashvilleMNPSDataWarehouseReport-OverDrive.sh [date] [-localfile] [-verbose] [-no-email]
#
# DESCRIPTION:
#   This script retrieves OverDrive Unique Users User Detail reports via Marketplace.
#   It transforms the data by matching the OverDrive User ID (patronguid) with Carl.X data
#   stored in sqlite3 databases, and outputs CSV files for MNPS.

# Read the configuration file
mackinErrorEmailRecipients=$(awk -F "=" '/NashvilleMNPS/ {print $2}' ../config.pwd.ini | tr -d '[:space:]' | sed 's/^"\(.*\)"$/\1/')

# Initialize flags
use_local=false
verbose=false
no_email=false

# Simple argument parsing
for arg in "$@"; do
    if [[ "$arg" == "-localfile" ]]; then
        use_local=true
    elif [[ "$arg" == "-verbose" ]]; then
        verbose=true
    elif [[ "$arg" == "-no-email" ]]; then
        no_email=true
    elif [[ "$arg" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
        date_str="$arg"
    fi
done

# Function to send error emails
send_error_email() {
    local error_message="$1"
    local subject="MNPS OverDrive Report Error: $error_message"
    echo "$error_message"
    if [ "$no_email" = true ]; then
        return
    fi
    if [ -n "$mackinErrorEmailRecipients" ]; then
        echo "$error_message" | mail -s "$subject" "$mackinErrorEmailRecipients"
    fi
}

# Determine the date
if [ -n "$date_str" ]; then
    date=$(date -d "$date_str" +'%Y-%m-%d' 2>/dev/null)
    if [ $? -ne 0 ]; then
        send_error_email "Invalid date format: $date_str. Use YYYY-MM-DD."
        exit 1
    fi
else
    date=$(date -d "yesterday" +'%Y-%m-%d')
fi

# Format dates
date_safe=$(date -d "$date" +'%Y%m%d')
date_output=$(date -d "$date" +'%Y-%m-%d')

# Ensure data directory exists
mkdir -p ../data/overdrive/

REPORT_FILE="../data/overdrive/OverDrive_Report_$date_safe.csv"

if [ "$use_local" = true ]; then
    echo "Using local file: $REPORT_FILE"
    if [ ! -f "$REPORT_FILE" ]; then
        send_error_email "Local file not found: $REPORT_FILE"
        exit 1
    fi
else
    echo "Downloading OverDrive report for $date..."
    PHP_VERBOSE=""
    if [ "$verbose" = true ]; then PHP_VERBOSE="-verbose"; fi
    
    php NashvilleMNPSDataWarehouseReport-OverDrive.php "$date" $PHP_VERBOSE
    if [ $? -ne 0 ]; then
        send_error_email "Failed to download OverDrive report for $date."
        exit 1
    fi
fi

# Run SQL transformations
echo "Running SQL transformations..."

# STUDENTS
sql_student=$(<NashvilleMNPSDataWarehouseReport-OverDrive-Students.sql)
sql_student=${sql_student//DATEPLACEHOLDERYYYYMMDD/$date_safe}
sql_student=${sql_student//DATEPLACEHOLDER_ISO/$date_output}
sql_student=${sql_student//DATEPLACEHOLDER/$date_safe}

echo "$sql_student" > NashvilleMNPSDataWarehouseReport-OverDrive-Students-Date-Specific.sql
sqlite3 ../data/ic2carlx_mnps_students.db < NashvilleMNPSDataWarehouseReport-OverDrive-Students-Date-Specific.sql > /dev/null 2>&1

# STAFF
sql_staff=$(<NashvilleMNPSDataWarehouseReport-OverDrive-Staff.sql)
sql_staff=${sql_staff//DATEPLACEHOLDERYYYYMMDD/$date_safe}
sql_staff=${sql_staff//DATEPLACEHOLDER_ISO/$date_output}
sql_staff=${sql_staff//DATEPLACEHOLDER/$date_safe}

echo "$sql_staff" > NashvilleMNPSDataWarehouseReport-OverDrive-Staff-Date-Specific.sql
sqlite3 ../data/ic2carlx_mnps_staff.db < NashvilleMNPSDataWarehouseReport-OverDrive-Staff-Date-Specific.sql > /dev/null 2>&1

# Output and move files
STAFF_OUTPUT_FILE="../data/LibraryServices-Checkouts-OverDrive-staff-$date_safe.csv"
STUDENT_OUTPUT_FILE="../data/LibraryServices-Checkouts-OverDrive-student-$date_safe.csv"

# Ensure output files exist even if queries returned 0 results
for f in "$STAFF_OUTPUT_FILE" "$STUDENT_OUTPUT_FILE"; do
    if [ ! -f "$f" ] || [ ! -s "$f" ]; then
        echo "tn_school_code,yearmonthday,patronid,count_of_checkouts" > "$f"
    fi
done

SOURCE_FILES="../data/LibraryServices-Checkouts-OverDrive-*-$date_safe.csv"
DEST_DIR="/home/mnps.org/data"

# Count how many files we have to move
FILE_COUNT=$(ls $SOURCE_FILES 2>/dev/null | wc -l)

if [ "$FILE_COUNT" -gt 0 ]; then
    # Set permissions for the files
    chown :mnps.org $SOURCE_FILES 2>/dev/null
    chmod 660 $SOURCE_FILES 2>/dev/null
    # Move the files
    mv $SOURCE_FILES $DEST_DIR/
    echo "Process completed. $FILE_COUNT files moved to $DEST_DIR"
else
    send_error_email "Output files were not created."
    exit 1
fi
