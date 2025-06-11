#!/bin/bash
# insert_column_into_test.sh
# James Staub, Nashville Public Library
# Script to insert a new column into ic2carlx_mnps_students_test.txt based on unique default branch values from CARLX_INFINITECAMPUS_STUDENT.txt

# Get unique default branch values from CARLX_INFINITECAMPUS_STUDENT.txt
# to determine which schools actually have promising scholars students
unique_branches=$(awk -F '|' '{print $20}' ../data/CARLX_INFINITECAMPUS_STUDENT.txt | sort | uniq)

# Insert the new column into the original file
for row in *; do
  IFS='|' read -r -a values <<< "$row"
  default_branch=${values[17]}

  # If the 18th column value matches a unique branch value, set the new column value to it
  if [[ $unique_branches =~ $default_branch ]]; then
    sed -i "s/|/$default_branch|/" ../data/ic2carlx_mnps_students_test.txt
  else
    sed -i "/$row/s/[^|]*$|/$row|promisingScholarsBranch/|/" ../data/ic2carlx_mnps_students_test.txt
  fi
done