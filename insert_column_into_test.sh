#!/bin/bash
# insert_column_into_test.sh
# James Staub, Nashville Public Library

# Create a temp file with unique branch values
awk -F'|' '{print $20}' ../data/CARLX_INFINITECAMPUS_STUDENT.txt | sort | uniq > /tmp/unique_branches.txt

input_file="../data/ic2carlx_mnps_students_test.txt"
output_file="../data/ic2carlx_mnps_students_test_with_branch.txt"

while IFS='|' read -r -a row; do
  default_branch="${row[17]}"
  col19="${row[18]}"
  if grep -Fxq "$default_branch" /tmp/unique_branches.txt; then
    new_col="$col19"
  else
    new_col=""
  fi
  row=( "${row[@]:0:19}" "$new_col" "${row[@]:19}" )
  (IFS='|'; echo "${row[*]}")
done < "$input_file" > "$output_file"

rm /tmp/unique_branches.txt