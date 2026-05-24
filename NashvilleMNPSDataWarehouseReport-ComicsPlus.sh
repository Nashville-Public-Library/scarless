#!/bin/bash
# NashvilleMNPSDataWarehouseReport-ComicsPlus.sh
# James Staub, Nashville Public Library with significant assistance from JetBrains Junie
#
# USAGE:
# ./NashvilleMNPSDataWarehouseReport-ComicsPlus.sh [date] [-localfile] [-verbose] [-no-email]
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
#   [-verbose]   Optional. Flag to enable verbose output for debugging.
#
#   [-no-email]  Optional. Flag to suppress sending error emails during testing.
#
# EXAMPLES:
#   ./NashvilleMNPSDataWarehouseReport-ComicsPlus.sh                    # Process yesterday's reports
#   ./NashvilleMNPSDataWarehouseReport-ComicsPlus.sh 2026-05-23         # Process reports for May 23, 2026
#   ./NashvilleMNPSDataWarehouseReport-ComicsPlus.sh 2026-05-23 -localfile  # Process local files for May 23, 2026
#   ./NashvilleMNPSDataWarehouseReport-ComicsPlus.sh -verbose           # Process yesterday with verbose output
#   ./NashvilleMNPSDataWarehouseReport-ComicsPlus.sh -no-email          # Process without sending error emails
#

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

if [ "$verbose" = true ]; then
    echo "Verbose mode enabled."
fi

# Read up to 9 ComicsPlus account configurations
CP_USERS=()
CP_PASSWORDS=()
CP_LIB_IDS=()

if [ "$verbose" = true ]; then echo "Reading account configurations from config.pwd.ini..."; fi
for i in {1..9}; do
    user=$(awk -F "=" "/comicsPlus${i}User/ {print \$2}" ../config.pwd.ini | tr -d '[:space:]' | sed 's/^"\(.*\)"$/\1/')
    pass=$(awk -F "=" "/comicsPlus${i}Password/ {print \$2}" ../config.pwd.ini | tr -d '[:space:]' | sed 's/^"\(.*\)"$/\1/')
    libid=$(awk -F "=" "/comicsPlus${i}LibraryID/ {print \$2}" ../config.pwd.ini | tr -d '[:space:]' | sed 's/^"\(.*\)"$/\1/')
    
    if [ -n "$user" ] && [ -n "$pass" ] && [ -n "$libid" ]; then
        if [ "$verbose" = true ]; then echo "Found account $i: User=$user, LibraryID=$libid"; fi
        CP_USERS+=("$user")
        CP_PASSWORDS+=("$pass")
        CP_LIB_IDS+=("$libid")
    fi
done

# Fallback to legacy single account if no numbered accounts found
if [ ${#CP_USERS[@]} -eq 0 ]; then
    if [ "$verbose" = true ]; then echo "No numbered accounts found. Checking for legacy comicsplusUser..."; fi
    user=$(awk -F "=" '/comicsplusUser/ {print $2}' ../config.pwd.ini | tr -d '[:space:]' | sed 's/^"\(.*\)"$/\1/')
    pass=$(awk -F "=" '/comicsplusPassword/ {print $2}' ../config.pwd.ini | tr -d '[:space:]' | sed 's/^"\(.*\)"$/\1/')
    if [ -n "$user" ] && [ -n "$pass" ]; then
        if [ "$verbose" = true ]; then echo "Found legacy account: User=$user"; fi
        CP_USERS+=("$user")
        CP_PASSWORDS+=("$pass")
        CP_LIB_IDS+=("2140" "2141") 
    fi
fi

# Function to send error emails
send_error_email() {
    local error_message="$1"
    local subject="MNPS ComicsPlus Report Error: $error_message"
    echo "$error_message"
    if [ "$no_email" = true ]; then
        if [ "$verbose" = true ]; then echo "Email suppressed (-no-email): $error_message"; fi
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

if [ "$verbose" = true ]; then echo "Processing data for date: $date (Local files: $use_local)"; fi

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
    echo "library_id,activity_date,patron_full,checkouts" > "$COMBINED_FILE"

    for i in "${!CP_USERS[@]}"; do
        USER="${CP_USERS[$i]}"
        PASS="${CP_PASSWORDS[$i]}"
        LIB_ID="${CP_LIB_IDS[$i]}"

        echo "Authenticating with LibraryPass for Library ID: $LIB_ID via Basic Auth + JSON..."
        
        # Use a temporary file for the JSON payload to avoid escaping issues
        PAYLOAD_FILE=$(mktemp)
        echo -n "{\"username\":\"$USER\", \"password\":\"$PASS\"}" > "$PAYLOAD_FILE"
        
        if [ "$verbose" = true ]; then 
            echo "Request Payload: $(cat "$PAYLOAD_FILE")"
            echo "Executing: curl -s -X POST \"https://myapi.librarypass.com/token\" -u \"$USER:$PASS\" -H \"Content-Type: application/json\" -H \"Accept: application/json\" -A \"Mozilla/5.0 (Windows NT 10.0; Win64; x64)\" -d @\"$PAYLOAD_FILE\""
        fi
        
        LOGIN_RESPONSE=$(curl -s -X POST "https://myapi.librarypass.com/token" \
            -u "$USER:$PASS" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json" \
            -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64)" \
            -d @"$PAYLOAD_FILE")
        
        rm -f "$PAYLOAD_FILE"
        
        if [ "$verbose" = true ]; then echo "Login Response: $LOGIN_RESPONSE"; fi

        TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.token')

        if [ -z "$TOKEN" ] || [ "$TOKEN" == "null" ]; then
            send_error_email "Authentication failed for Library $LIB_ID. Could not retrieve token. Response: $LOGIN_RESPONSE"
            continue
        fi

        echo "Retrieving data for Library ID: $LIB_ID"
        # Note: limit query key - testing upper boundary. Let's try 1000.
        
        PAGE=1
        LIMIT=1000
        while true; do
            API_URL="https://myapi.librarypass.com/library-reports/checkouts?library_id=$LIB_ID&filter=custom_range&page=$PAGE&limit=$LIMIT&start=$date_api&end=$date_api"
            if [ "$verbose" = true ]; then echo "Retrieving Page $PAGE: $API_URL"; fi
            
            RESPONSE=$(curl -s -H "Authorization: Bearer $TOKEN" \
                -H "Accept: application/json" \
                -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64)" \
                "$API_URL")
            
            # Check for error in response
            if echo "$RESPONSE" | jq -e '.status == "error"' > /dev/null; then
                ERROR_MSG=$(echo "$RESPONSE" | jq -r '.message')
                if [ "$verbose" = true ]; then echo "API Error: $ERROR_MSG"; fi
                send_error_email "API Error for Library $LIB_ID: $ERROR_MSG"
                break
            fi

            # Extract rows. The actual response has an "items" array.
            COUNT=$(echo "$RESPONSE" | jq '.items | length' 2>/dev/null || echo 0)
            if [ "$verbose" = true ]; then echo "Items found on Page $PAGE: $COUNT"; fi
            
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
PHP_VERBOSE=""
if [ "$verbose" = true ]; then 
    PHP_VERBOSE="--verbose"
    echo "Running Carl.X lookup with patron IDs:"
    cat "$PATRON_LIST_FILE"
fi
php ComicsPlusSchoolLookup.php "$PATRON_LIST_FILE" $PHP_VERBOSE > "$LOOKUP_FILE"

if [ "$verbose" = true ]; then
    echo "Lookup results:"
    cat "$LOOKUP_FILE"
fi

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

# Ensure output files exist even if queries returned 0 results
for f in "$STAFF_OUTPUT_FILE" "$STUDENT_OUTPUT_FILE"; do
    if [ ! -f "$f" ]; then
        if [ "$verbose" = true ]; then echo "Creating empty output file with headers: $f"; fi
        echo "tn_school_code,yearmonthday,patronid,count_of_checkouts" > "$f"
    fi
done

SOURCE_FILES="../data/LibraryServices-Checkouts-ComicsPlus-*-$date_output.csv"
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
