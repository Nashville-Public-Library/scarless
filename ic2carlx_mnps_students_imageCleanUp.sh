#!/bin/bash
# James Staub
# Nashville Public Library
# Delete patron images that are not in the ..._infinitecampus.csv file
# 2024 09 21

# Define the paths
image_dir="../data/images/students/"
csv_file="../data/ic2carlx_mnps_students_infinitecampus.csv"

# Extract the first column from the CSV file and store it in an array
mapfile -t valid_filenames < <(cut -d',' -f1 "$csv_file")

# Convert the array to a set for faster lookup
declare -A valid_set
for filename in "${valid_filenames[@]}"; do
    valid_set["$filename.jpg"]=1
done

# Iterate over the files in the image directory
for file in "$image_dir"*; do
    # Get the base name of the file
    base_name=$(basename "$file")

    # Check if the base name is in the set of valid filenames
    if [[ ! ${valid_set["$base_name"]} ]]; then
        echo "Deleting $file"
        rm "$file"
    fi
done