#!/bin/bash
# ic2carlx_mnps_students_image_report.sh
# James Staub
# Nashville Public Library
# Generate a summary report of student images that are older than a specified date
# 2024 09 22

# Define the paths
image_dir="../data/images/students/"
csv_file="../data/ic2carlx_mnps_students_infinitecampus.csv"
derivative_id_branch_csv_file="../data/ic2carlx_mnps_students_infinitecampus_derivative.csv"
find_images_file="../data/ic2carlx_mnps_students_images_exist.txt"
find_images_missing_file="../data/ic2carlx_mnps_students_infinitecampus_images_missing_join.txt"
find_old_images_file="../data/ic2carlx_mnps_students_images_old.txt"
find_old_images_join_file="../data/ic2carlx_mnps_students_infinitecampus_images_old_join.txt"
output_missing_images_file="../data/ic2carlx_mnps_students_images_missing_summary.txt"
output_old_images_file="../data/ic2carlx_mnps_students_images_old_summary.txt"
combined_output_file="../data/ic2carlx_mnps_students_images_summary.txt"
final_output_file="../data/ic2carlx_mnps_students_images_final_summary.txt"

# Clear all output files
> "$find_images_file"
> "$find_images_missing_file"
> "$find_old_images_file"
> "$find_old_images_join_file"
> "$output_missing_images_file"
> "$output_old_images_file"
> "$combined_output_file"

# Define the cutoff date
cutoff_date="2022-08-01"

# Create the derivative CSV file with only the patron id and branch code
echo "Creating derivative CSV file from $csv_file "
awk -vFPAT='[^,]*|"[^"]*"' -F, '$1 < 190999000 {print $1 "," $12}' "$csv_file" > "$derivative_id_branch_csv_file"
sort -t, -k1,1 "$derivative_id_branch_csv_file" -o "$derivative_id_branch_csv_file"

# Output the find images command results to a file
echo "Finding all images..."
find "$image_dir" -type f -printf "%f\n" | grep -oP '\d{9}' > "$find_images_file"
sort "$find_images_file" -o "$find_images_file"

# Join the find results with the derivative CSV file
echo "Creating the list of missing files by branch codes..."
join -t, -1 1 -2 1 -v 2 "$find_images_file" "$derivative_id_branch_csv_file" > "$find_images_missing_file"

# Create the summary output of missing images by branch code
echo "Creating summary output of missing images by branch code..."
awk -F, '{count[$2]++} END {for (branch in count) print branch, count[branch]}' "$find_images_missing_file" > "$output_missing_images_file"
sort "$output_missing_images_file" -o "$output_missing_images_file"
echo "Summary output created. Output written to $output_missing_images_file"
# Display the summary output
#cat "$output_missing_images_file"

# Output the find-old-images command results to a file
echo "Finding files older than $cutoff_date..."
find "$image_dir" -type f ! -newermt "$cutoff_date" -printf "%f\n" | grep -oP '\d{9}' > "$find_old_images_file"
sort "$find_old_images_file" -o "$find_old_images_file"

# Join the find results with the derivative CSV file
echo "Creating the list of too-old files and branch codes..."
join -t, -1 1 -2 1 -o 1.1,2.2 "$find_old_images_file" "$derivative_id_branch_csv_file" > "$find_old_images_join_file"

# Create the summary output of old images by branch code
echo "Creating summary output..."
awk -F, '{count[$2]++} END {for (branch in count) print branch, count[branch]}' "$find_old_images_join_file" > "$output_old_images_file"
sort "$output_old_images_file" -o "$output_old_images_file"
echo "Summary output created. Output written to $output_old_images_file"
# Display the summary output
#cat "$output_old_images_file"

# Combine the files on the id column
join -t' ' -a 1 -a 2 -e '0' -o '0,1.2,2.2' "$output_missing_images_file" "$output_old_images_file" > "$combined_output_file"
echo "Combined output written to $combined_output_file"
# Display the combined output
#cat "$combined_output_file"

# Add the values of the second and third columns, calculate totals, and output the result to a new file
awk '{
    print $1, $2, $3, $2 + $3;
    total2 += $2;
    total3 += $3;
} END {
    print "Total", total2, total3, total2 + total3;
}' "$combined_output_file" > "$final_output_file"