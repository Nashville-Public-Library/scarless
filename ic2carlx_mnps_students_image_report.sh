#!/bin/bash

# Define the paths
image_dir="../data/images/students/"
csv_file="../data/ic2carlx_mnps_students_infinitecampus.csv"
output_file="../data/output.txt"

# Clear the output file
> "$output_file"

# Define the cutoff date
cutoff_date="2024-08-01"

echo "Processing files in $image_dir..."

# Read the CSV file into an associative array
declare -A csv_data
while IFS=, read -r id col2 col12; do
    csv_data["$id"]="$col2 $col12"
done < <(tail -n +2 "$csv_file")  # Skip the header row

# Process the find command output with cutoff date
find "$image_dir" -type f -newermt "$cutoff_date" -printf "%TY-%Tm-%Td %f\n" | sort | while read -r line; do
    # Extract the date and filename
    date=$(echo "$line" | awk '{print $1}')
    filename=$(echo "$line" | awk '{print $2}')

    echo "Processing file: $filename with date: $date"

    # Extract the 9-digit ID from the filename
    id=$(echo "$filename" | grep -oP '\d{9}')

    # Look up the ID in the associative array
    if [ -n "$id" ]; then
        values="${csv_data[$id]}"
        # Write the results to the output file
        if [ -n "$values" ]; then
            echo "$date $filename $values" >> "$output_file"
            echo "Found values for ID $id: $values"
        else
            echo "$date $filename NOT_FOUND" >> "$output_file"
            echo "ID $id not found in CSV"
        fi
    else
        echo "No valid ID found in filename: $filename"
    fi
done

echo "Processing complete. Output written to $output_file"