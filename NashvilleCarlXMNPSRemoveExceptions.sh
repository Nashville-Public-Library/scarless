#!/bin/bash
# NashvilleCarlXMNPSRemoveExceptions.sh
# James Staub, Nashville Public Library
# Script to identify and delete records in an exceptions file that appear identically in the SMS extract file (i.e., the extract already has the correct information, and there is no need for an exception record).
# Usage: ./NashvilleCarlXMNPSRemoveExceptions.sh CARLX_INFINITECAMPUS_STAFF.txt ic2carlx_mnps_staff_test.txt

# Set default values for FILE1 and FILE2
FILE1="../data/CARLX_INFINITECAMPUS_STAFF.txt"
FILE2="../data/ic2carlx_mnps_staff_test.txt"

# Override defaults if arguments are provided
if [ $# -eq 2 ]; then
    FILE1="$1"
    FILE2="$2"
elif [ $# -ne 0 ]; then
    echo "Usage: $0 <file1> <file2>"
    echo "  <file1>: ../data/CARLX_INFINITECAMPUS_STAFF.txt"
    echo "  <file2>: ../data/ic2carlx_mnps_staff_test.txt"
    exit 1
fi

# Ensure ../data directory exists
if [ ! -d "../data" ]; then
    mkdir -p "../data"
fi

# Create temporary files in ../data/ directory
TEMP_FILE="../data/NashvilleCarlXMNPSRemoveExceptions_temp_file_$(date +%Y%m%d%H%M%S)_$$.txt"
RECORDS_TO_DELETE_FILE="../data/NashvilleCarlXMNPSRemoveExceptions_records_to_delete_$(date +%Y%m%d%H%M%S)_$$.txt"

# Create empty files to ensure they exist
touch "$TEMP_FILE"
touch "$RECORDS_TO_DELETE_FILE"

# Check if files exist
if [ ! -f "$FILE1" ]; then
    echo "Error: File $FILE1 does not exist"
    exit 1
fi

if [ ! -f "$FILE2" ]; then
    echo "Error: File $FILE2 does not exist"
    exit 1
fi

# Check for duplicate records in FILE2
DUPLICATE_RECORDS=$(awk -F'|' '{print $1}' "$FILE2" | sort | uniq -d)
if [ -n "$DUPLICATE_RECORDS" ]; then
    echo "Error: Duplicate records found in $FILE2"
    echo "Please eliminate the following duplicate patron IDs before proceeding:"
    echo "$DUPLICATE_RECORDS"
    exit 1
fi

# Process each record in file #2
while IFS= read -r line2; do
    # Extract patronid, borrowertype, and schoolcode from file #2
    patronid2=$(echo "$line2" | cut -d'|' -f1)
    borrowertype2=$(echo "$line2" | cut -d'|' -f2)
    schoolcode2=$(echo "$line2" | cut -d'|' -f7)

    # Count occurrences of patronid in file #1
    count=$(grep -c "^$patronid2|" "$FILE1")

    # If patronid doesn't exist in file #1 or appears multiple times, keep the record
    if [ "$count" -eq 0 ] || [ "$count" -gt 1 ]; then
        echo "$line2" >> "$TEMP_FILE"
        continue
    fi

    # If patronid appears exactly once in file #1, check columns 2 and 7
    if [ "$count" -eq 1 ]; then
        line1=$(grep "^$patronid2|" "$FILE1")
        borrowertype1=$(echo "$line1" | cut -d'|' -f2)
        schoolcode1=$(echo "$line1" | cut -d'|' -f7)

        # If both columns match, record will be deleted from file #2
        # Otherwise, keep the record
        if [ "$borrowertype1" != "$borrowertype2" ] || [ "$schoolcode1" != "$schoolcode2" ]; then
            echo "$line2" >> "$TEMP_FILE"
        else
            # Record the line that will be deleted
            echo "$line2" >> "$RECORDS_TO_DELETE_FILE"
        fi
    fi
done < "$FILE2"

# Count the number of records to be deleted
RECORDS_TO_DELETE_COUNT=$(wc -l < "$RECORDS_TO_DELETE_FILE" | tr -d ' ')

# Show the user which records will be deleted and ask for confirmation
if [ "$RECORDS_TO_DELETE_COUNT" -gt 0 ]; then
    echo "The following $RECORDS_TO_DELETE_COUNT record(s) will be deleted from $FILE2:"
    cat "$RECORDS_TO_DELETE_FILE"
    echo ""
    echo "Do you want to proceed with these changes? (y/n)"
    read -r response

    if [[ "$response" =~ ^[Yy]$ ]]; then
        # Replace file #2 with the filtered content
        mv "$TEMP_FILE" "$FILE2"
        echo "Processing complete. $RECORDS_TO_DELETE_COUNT record(s) have been removed from $FILE2."
    else
        echo "Operation aborted. No changes were made to $FILE2."
        # Clean up temporary files
        rm -f "$TEMP_FILE"
    fi
else
    echo "No records need to be deleted from $FILE2."
    # Replace file #2 with the filtered content (which is identical)
    mv "$TEMP_FILE" "$FILE2"
fi

# Clean up temporary files
rm -f "$RECORDS_TO_DELETE_FILE"