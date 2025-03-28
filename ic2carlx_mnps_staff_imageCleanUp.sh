#!/bin/bash
# James Staub
# Nashville Public Library
# Delete patron images that:
# 1. are likely invalid because they are less than 4KB
# 2. are not in the ..._infinitecampus.csv file

# Define the paths
base_dir="../data/images/"
sub_dir="${1:-staff}"
image_dir="${base_dir}${sub_dir}/"
csv_file="../data/ic2carlx_mnps_staff_infinitecampus.csv"

# Delete images that are less than 4KB
find "$image_dir" -type f -size -4k -delete

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