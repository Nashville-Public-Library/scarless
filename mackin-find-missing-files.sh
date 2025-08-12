#!/bin/bash

# Define the start and end dates
start_date="2024-08-02"
end_date=$(date +"%Y-%m-%d")  # Today's date

# Directory to check
directory="/home/mnps.org/data"

# File prefix
#prefix="LibraryServices-Checkouts-MackinVIA-student-"
prefix="LibraryServices-Checkouts-MackinVIA-staff-"

# Create an array to store missing files
missing_files=()

# Loop through each date from start_date to end_date
current_date=$start_date
while [[ "$current_date" < "$end_date" ]]; do
    # Construct the expected filename
    filename="${prefix}${current_date}.csv"
    
    # Check if the file exists
    if [[ ! -f "${directory}/${filename}" ]]; then
        missing_files+=("$filename")
	bash NashvilleMNPSDataWarehouseReport-Mackin.sh $current_date -localfile
    fi
    
    # Increment the date by 1 day
    current_date=$(date -d "$current_date + 1 day" +"%Y-%m-%d")
done

# Output the results
echo "Missing files between $start_date and $end_date:"
if [[ ${#missing_files[@]} -eq 0 ]]; then
    echo "No missing files found."
else
    for file in "${missing_files[@]}"; do
        echo "$file"
    done
    echo "Total missing files: ${#missing_files[@]}"
fi
