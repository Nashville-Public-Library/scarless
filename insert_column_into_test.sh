#!/bin/bash
# insert_column_into_test.sh
# James Staub, Nashville Public Library

# Get unique default branch values
declare -A unique_branches
while IFS='|' read -r _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ branch _; do
  unique_branches["$branch"]=1
done < <(awk -F'|' '{print $20}' ../data/CARLX_INFINITECAMPUS_STUDENT.txt | sort | uniq)

input_file="../data/ic2carlx_mnps_students_test.txt"
output_file="../data/ic2carlx_mnps_students_test_with_branch.txt"

while IFS='|' read -r -a row; do
  default_branch="${row[17]}"
  col19="${row[18]}"
  if [[ -n "${unique_branches[$default_branch]}" ]]; then
    new_col="$col19"
  else
    new_col=""
  fi
  # Insert new column after 19th (index 18)
  row=( "${row[@]:0:19}" "$new_col" "${row[@]:19}" )
  (IFS='|'; echo "${row[*]}")
done < "$input_file" > "$output_file"