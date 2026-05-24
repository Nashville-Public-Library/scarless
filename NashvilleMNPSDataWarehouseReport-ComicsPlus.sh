#!/bin/bash
# NashvilleMNPSDataWarehouseReport-ComicsPlus.sh
# James Staub, Nashville Public Library with significant assistance from JetBrains Junie
#
# USAGE:
# ./NashvilleMNPSDataWarehouseReport-ComicsPlus.sh [date] [-localfile]
#
# DESCRIPTION:
#   This script retrieves ComicsPlus usage reports via API for Nashville MNPS.
#   It transforms the data, splitting the Patron field at '@' to get the patronid,
#   and outputs CSV files to the specified destination for pickup by MNPS.
#
# PARAMETERS:
#   [date]       Optional. The date for which to process reports in YYYY-MM-DD format.
#                If not provided, defaults to yesterday's date.
#
#   [-localfile] Optional. Flag indicating that the files are already downloaded locally
#                and should not be retrieved from the API. Must be used with [date].
#
# EXAMPLES:
#   ./NashvilleMNPSDataWarehouseReport-ComicsPlus.sh                    # Process yesterday's reports
#   ./NashvilleMNPSDataWarehouseReport-ComicsPlus.sh 2026-05-23         # Process reports for May 23, 2026
#   ./NashvilleMNPSDataWarehouseReport-ComicsPlus.sh 2026-05-23 -localfile  # Process local files for May 23, 2026
#

# Read the configuration file
comicsplusUser=$(awk -F "=" '/comicsplusUser/ {print $2}' ../config.pwd.ini | tr -d ' ' | sed 's/^"\(.*\)"$/\1/')
comicsplusPassword=$(awk -F "=" '/comicsplusPassword/ {print $2}' ../config.pwd.ini | tr -d ' ' | sed 's/^"\(.*\)"$/\1/')
mackinErrorEmailRecipients=$(awk -F "=" '/NashvilleMNPS/ {print $2}' ../config.pwd.ini | tr -d ' ' | sed 's/^"\(.*\)"$/\1/')

# Function to send error emails
send_error_email() {
    local error_message="$1"
    local subject="MNPS ComicsPlus Report Error: $error_message"
    echo "$error_message"
    if [ -n "$mackinErrorEmailRecipients" ]; then
        echo "$error_message" | mail -s "$subject" "$mackinErrorEmailRecipients"
    fi
}

# Determine the date and whether to use local files
if [ $# -gt 1 ] && [[ "$2" == *"-localfile"* ]]; then
    # If -localfile flag is present, use the first argument as the date
    date_str=$1
    date=$(date -d "$date_str" +'%Y-%m-%d' 2>/dev/null)
    if [ $? -ne 0 ]; then
        send_error_email "Invalid date format. Use YYYY-MM-DD."
        exit 1
    fi
    use_local=true
elif [ $# -gt 0 ]; then
    date_str=$1
    date=$(date -d "$date_str" +'%Y-%m-%d' 2>/dev/null)
    if [ $? -ne 0 ]; then
        send_error_email "Invalid date format. Use YYYY-MM-DD."
        exit 1
    fi
    use_local=false
else
    date=$(date -d "yesterday" +'%Y-%m-%d')
    use_local=false
fi

# Format dates
date_api=$(date -d "$date" +'%m-%d-%Y' | sed 's/^0//; s/-0/-/g')
date_connected=$(date -d "$date" +'%Y-%m-%d')
date_safe=$(date -d "$date" +'%Y%m%d')

# Ensure data directory exists
mkdir -p ../data/comicsplus/

COMBINED_FILE="../data/comicsplus/ComicsPlus_Report_$date_safe.csv"

if [ "$use_local" = true ]; then
    echo "Using local file: $COMBINED_FILE"
    if [ ! -f "$COMBINED_FILE" ]; then
        send_error_email "Local file not found: $COMBINED_FILE"
        exit 1
    fi
else
    # Authenticate
    echo "Authenticating with LibraryPass..."
    LOGIN_RESPONSE=$(curl -s -X POST "https://myapi.librarypass.com/token" \
        -H "Content-Type: application/json" \
        -d "{\"username\":\"$comicsplusUser\", \"password\":\"$comicsplusPassword\"}")

    TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.token')

    if [ -z "$TOKEN" ] || [ "$TOKEN" == "null" ]; then
        send_error_email "Authentication failed. Could not retrieve token. Response: $LOGIN_RESPONSE"
        exit 1
    fi

    # Retrieval for both library IDs: 2140 and 2141
    LIBRARY_IDS=("2140" "2141")
    echo "library_id,activity_date,patron_full,checkouts" > "$COMBINED_FILE"

    for LIB_ID in "${LIBRARY_IDS[@]}"; do
        echo "Retrieving data for Library ID: $LIB_ID"
        # Note: limit query key - testing upper boundary. Let's try 1000.
        
        PAGE=1
        LIMIT=1000
        while true; do
            RESPONSE=$(curl -s -H "Authorization: Bearer $TOKEN" \
                "https://myapi.librarypass.com/library-reports/checkouts?library_id=$LIB_ID&filter=custom_range&page=$PAGE&limit=$LIMIT&start=$date_api&end=$date_api")
            
            # Check for error in response
            if echo "$RESPONSE" | jq -e '.status == "error"' > /dev/null; then
                send_error_email "API Error for Library $LIB_ID: $(echo "$RESPONSE" | jq -r '.message')"
                break
            fi

            # Extract rows. The actual response has an "items" array.
            COUNT=$(echo "$RESPONSE" | jq '.items | length' 2>/dev/null || echo 0)
            if [ "$COUNT" -eq 0 ]; then
                break
            fi

            # Append to combined file
            echo "$RESPONSE" | jq -r --arg lib "$LIB_ID" \
                '.items[] | [$lib, .Date, .Patron, 1] | @csv' >> "$COMBINED_FILE"

            if [ "$COUNT" -lt "$LIMIT" ]; then
                break
            fi
            PAGE=$((PAGE + 1))
        done
    done
fi

# Perform Carl.X School Lookup
echo "Performing Carl.X school lookup for retrieved patrons..."

# Extract the actual date from the data to use for filenames
# The Date field from API is like "2026-05-23 17:50:40"
# We'll take the first date found in the second column of the CSV
data_date=$(tail -n +2 "$COMBINED_FILE" | head -n 1 | cut -d',' -f2 | tr -d '"' | cut -d' ' -f1)

if [ -z "$data_date" ]; then
    echo "No data retrieved for $date_connected. Using $date_connected for filenames."
    data_date=$date_connected
fi

# Format the date for the output filename (YYYY-MM-DD)
date_output=$data_date

PATRON_LIST_FILE="../data/comicsplus/Patron_List_$date_safe.txt"
# Extract unique patron IDs (before the @)
tail -n +2 "$COMBINED_FILE" | cut -d',' -f3 | tr -d '"' | cut -d'@' -f1 | sort -u > "$PATRON_LIST_FILE"

LOOKUP_FILE="../data/comicsplus/ComicsPlus_School_Lookup_$date_safe.csv"
php ComicsPlusSchoolLookup.php "$PATRON_LIST_FILE" > "$LOOKUP_FILE"

# Run SQL transformations
# STUDENTS
sql_student=$(<NashvilleMNPSDataWarehouseReport-ComicsPlus-Students.sql)
sql_student=${sql_student//DATEPLACEHOLDER/$date_safe}
sql_student=${sql_student//DATEPLACEHOLDERYYYYMMDD/$date_output}
echo "$sql_student" > NashvilleMNPSDataWarehouseReport-ComicsPlus-Students-Date-Specific.sql
sqlite3 ../data/ic2carlx_mnps_students.db < NashvilleMNPSDataWarehouseReport-ComicsPlus-Students-Date-Specific.sql

# STAFF
sql_staff=$(<NashvilleMNPSDataWarehouseReport-ComicsPlus-Staff.sql)
sql_staff=${sql_staff//DATEPLACEHOLDER/$date_safe}
sql_staff=${sql_staff//DATEPLACEHOLDERYYYYMMDD/$date_output}
echo "$sql_staff" > NashvilleMNPSDataWarehouseReport-ComicsPlus-Staff-Date-Specific.sql
sqlite3 ../data/ic2carlx_mnps_staff.db < NashvilleMNPSDataWarehouseReport-ComicsPlus-Staff-Date-Specific.sql

# Output and move files
STAFF_OUTPUT_FILE="../data/LibraryServices-Checkouts-ComicsPlus-staff-$date_output.csv"
STUDENT_OUTPUT_FILE="../data/LibraryServices-Checkouts-ComicsPlus-student-$date_output.csv"

if [ -f "$STAFF_OUTPUT_FILE" ] && [ -f "$STUDENT_OUTPUT_FILE" ]; then
    SOURCE_FILES="../data/LibraryServices-Checkouts-ComicsPlus-*-$date_output.csv"
    DEST_DIR="/home/mnps.org/data"
    # Set permissions for the files
    chown :mnps.org $SOURCE_FILES
    chmod 660 $SOURCE_FILES
    # Move the files
    mv $SOURCE_FILES $DEST_DIR/
    echo "Process completed. Files moved to $DEST_DIR"
else
    send_error_email "Output files were not created."
    exit 1
fi
