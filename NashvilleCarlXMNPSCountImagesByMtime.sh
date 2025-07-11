#!/bin/bash

# Directory to search
directory="../data/images/Students/"

# Target month and year
target_month="04"
target_year="2025"

# Initialize an associative array to store counts
declare -A file_counts

# Debugging: Check if the directory exists
if [ ! -d "$directory" ]; then
    echo "Error: Directory $directory does not exist."
    exit 1
fi

# Find files modified in April 2025 and process their mtimes
while IFS= read -r date; do
    # Extract the day from the date
    day=$(echo "$date" | awk -F'-' '{print $3}')
    # Increment the count for the day
    file_counts["$day"]=$((file_counts["$day"] + 1))
done < <(find "$directory" -type f -newermt "$target_year-$target_month-01" ! -newermt "$target_year-$((10#$target_month + 1))-01" -printf "%TY-%Tm-%Td\n")

# Print the results
if [ ${#file_counts[@]} -eq 0 ]; then
    echo "No files found with mtime in April $target_year."
else
    for day in "${!file_counts[@]}"; do
        echo "$target_month/$day/$target_year: ${file_counts[$day]}"
    done
fi