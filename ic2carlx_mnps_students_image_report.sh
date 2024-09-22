#!/bin/bash

# Define the paths
image_dir="../data/images/students/"
csv_file="../data/ic2carlx_mnps_students_infinitecampus.csv"
derivative_csv_file="../data/ic2carlx_mnps_students_infinitecampus_derivative.csv"
output_file="../data/output.txt"
find_file="../data/ic2carlx_mnps_students_infinitecampus_images_old.txt"
join_file="../data/ic2carlx_mnps_students_infinitecampus_images_old_join.txt"

# Clear the output file
> "$output_file"

# Define the cutoff date
cutoff_date="2024-08-01"

# Create the derivative CSV file with only the necessary columns
echo "Creating derivative CSV file..."
awk -vFPAT='[^,]*|"[^"]*"' -F, '{print $1 "," $12}' "$csv_file" > "$derivative_csv_file"
sort -t, -k1,1 "$derivative_csv_file" -o "$derivative_csv_file"
# Output the find command results to a temporary file
echo "Finding files older than $cutoff_date..."
find "$image_dir" -type f ! -newermt "$cutoff_date" -printf "%f\n" | grep -oP '\d{9}' | sort > "$find_file"
# Join the find results with the derivative CSV file
echo "Creating the list of too-old files and branch codes"
join -t, -1 1 -2 1 -o 1.1,2.2 -v 1 "$find_file" "$derivative_csv_file" > "$join_file"
# Create the summary output
echo "Creating summary output..."
awk -F, '{count[$2]++} END {for (branch in count) print branch, count[branch]}' "$join_file" > "$output_file"
echo "Summary output created. Output written to $output_file"
# Display the summary output
cat "$output_file"