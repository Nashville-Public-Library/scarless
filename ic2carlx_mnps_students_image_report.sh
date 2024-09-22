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
while IFS=, read -r id col1 col2; do
    csv_data["$id"]="$col1,$col2"
done < "$derivative_csv_file"

# Initialize pivot table and totals
declare -A pivot_table
declare -A row_totals
declare -A col_totals
total_count=0

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
        if [ -n "$values" ]; then
            IFS=',' read -r col1 col2 <<< "$values"
            key="$col2,$col1"
            pivot_table["$key"]=$((pivot_table["$key"] + 1))
            row_totals["$col2"]=$((row_totals["$col2"] + 1))
            col_totals["$col1"]=$((col_totals["$col1"] + 1))
            total_count=$((total_count + 1))
        fi
    fi
done

# Get unique col1 and col2 values
col1_values=($(awk -F, '{print $2}' "$derivative_csv_file" | sort | uniq))
col2_values=($(awk -F, '{print $3}' "$derivative_csv_file" | sort | uniq))

# Print the header
{
    printf "Branch Code"
    for col1 in "${col1_values[@]}"; do
        printf ",%s" "$col1"
    done
    printf ",Total\n"

    # Print the rows
    for col2 in "${col2_values[@]}"; do
        printf "%s" "$col2"
        for col1 in "${col1_values[@]}"; do
            key="$col2,$col1"
            printf ",%d" "${pivot_table["$key"]}"
        done
        printf ",%d\n" "${row_totals["$col2"]}"
    done

    # Print the column totals
    printf "Total"
    for col1 in "${col1_values[@]}"; do
        printf ",%d" "${col_totals["$col1"]}"
    done
    printf ",%d\n" "$total_count"
} > "$output_file"

echo "Pivot table created. Output written to $output_file"