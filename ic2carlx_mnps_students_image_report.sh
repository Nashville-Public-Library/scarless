#!/bin/bash

# Define the paths
image_dir="../data/images/students/"
csv_file="../data/ic2carlx_mnps_students_infinitecampus.csv"
derivative_csv_file="../data/ic2carlx_mnps_students_infinitecampus_derivative.csv"
output_file="../data/output.txt"

# Clear the output file
> "$output_file"

# Define the cutoff date
cutoff_date="2024-08-01"

echo "Creating derivative CSV file..."
# Create the derivative CSV file
awk -vFPAT='[^,]*|"[^"]*"' -F, '{print $1 "," $2 "," $12}' "$csv_file" > "$derivative_csv_file"

echo "Processing files in $image_dir..."

# Read the derivative CSV file into an associative array
declare -A csv_data
while IFS=, read -r id col2 col12; do
    csv_data["$id"]="$col2 $col12"
done < "$derivative_csv_file"

# Get the total number of records to be processed
total_records=$(find "$image_dir" -type f ! -newermt "$cutoff_date" | wc -l)
progress_step=$((total_records / 20)) # 5% of total records

# Initialize progress variables
current_record=0
next_update=$progress_step

# Process the find command output with cutoff date
find "$image_dir" -type f ! -newermt "$cutoff_date" -printf "%TY-%Tm-%Td %f\n" | sort | while read -r line; do
    # Extract the date and filename
    date=$(echo "$line" | awk '{print $1}')
    filename=$(echo "$line" | awk '{print $2}')

    # Extract the 9-digit ID from the filename
    id=$(echo "$filename" | grep -oP '\d{9}')

    # Look up the ID in the associative array
    if [ -n "$id" ]; then
        values="${csv_data[$id]}"
        # Write the results to the output file
        if [ -n "$values" ]; then
            echo "$date $filename $values" >> "$output_file"
        else
            echo "$date $filename NOT_FOUND" >> "$output_file"
        fi
    else
        echo "No valid ID found in filename: $filename"
    fi

    # Update progress
    current_record=$((current_record + 1))
    if [ $current_record -ge $next_update ]; then
        progress=$((current_record * 100 / total_records))
        echo -ne "Progress: $progress% \r"
        next_update=$((next_update + progress_step))
    fi
done

echo -e "\nProcessing complete. Output written to $output_file"